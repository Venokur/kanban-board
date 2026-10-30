<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Lib\Auth;
use App\Lib\BoardRepo;
use App\Lib\ChecklistRepo;
use App\Lib\EventRepo;
use App\Lib\Http;
use App\Lib\HttpException;
use App\Lib\Hub;
use App\Lib\LabelRepo;
use App\Lib\Revision;
use App\Rt\Request;
use App\Rt\Response;

final class BoardController
{
    // ------------------------------------------------------------------ board

    public static function board(Request $request, Response $response, array $params): array
    {
        Auth::currentUser($request);
        return BoardRepo::snapshot();
    }

    public static function renameBoard(Request $request, Response $response, array $params): array
    {
        Auth::currentUser($request);
        $name = Http::requireString(Http::body($request), 'name', 80);
        BoardRepo::renameBoard($name);
        return self::sync();
    }

    // ---------------------------------------------------------------- columns

    public static function createColumn(Request $request, Response $response, array $params): array
    {
        Auth::currentUser($request);
        $title = Http::requireString(Http::body($request), 'title', 80);
        BoardRepo::addColumn($title);
        return self::sync();
    }

    public static function renameColumn(Request $request, Response $response, array $params): array
    {
        Auth::currentUser($request);
        $title = Http::requireString(Http::body($request), 'title', 80);
        BoardRepo::renameColumn($params['id'], $title);
        return self::sync();
    }

    public static function updateColumn(Request $request, Response $response, array $params): array
    {
        Auth::currentUser($request);
        $body = Http::body($request);
        $fields = [];

        if (array_key_exists('title', $body)) {
            $fields['title'] = Http::requireString($body, 'title', 80);
        }
        if (array_key_exists('wipLimit', $body)) {
            $value = $body['wipLimit'];
            if ($value === null || $value === '' || $value === 0) {
                $fields['wip_limit'] = null;
            } else {
                $fields['wip_limit'] = Http::requireInt($body, 'wipLimit', 1, 999);
            }
        }
        if (array_key_exists('accent', $body)) {
            $value = $body['accent'];
            if ($value === null || $value === '') {
                $fields['accent'] = null;
            } elseif (!is_string($value) || !LabelRepo::isColor($value)) {
                throw new HttpException(422, 'Неизвестный цвет колонки');
            } else {
                $fields['accent'] = $value;
            }
        }

        BoardRepo::updateColumn($params['id'], $fields);
        return self::sync();
    }

    public static function deleteColumn(Request $request, Response $response, array $params): array
    {
        Auth::currentUser($request);
        BoardRepo::deleteColumn($params['id']);
        return self::sync();
    }

    public static function orderColumns(Request $request, Response $response, array $params): array
    {
        Auth::currentUser($request);
        $ids = Http::requireIntList(Http::body($request), 'columnIds');
        BoardRepo::orderColumns($ids);
        return self::sync();
    }

    // ------------------------------------------------------------------ cards

    public static function createCard(Request $request, Response $response, array $params): array
    {
        $user = Auth::currentUser($request);
        $body = Http::body($request);
        $columnId = Http::requireInt($body, 'columnId', 1, PHP_INT_MAX);
        $title = Http::requireString($body, 'title', 1000);
        $description = Http::optionalString($body, 'description', 20000);
        $cardId = BoardRepo::addCard($columnId, $title, $description, (int)$user['id']);
        if ($cardId > 0) {
            EventRepo::log($cardId, (int)$user['id'], 'card.created');
        }
        return self::sync();
    }

    public static function updateCard(Request $request, Response $response, array $params): array
    {
        $user = Auth::currentUser($request);
        $body = Http::body($request);
        $fields = [];

        if (array_key_exists('title', $body)) {
            $fields['title'] = Http::requireString($body, 'title', 1000);
        }
        if (array_key_exists('description', $body)) {
            $fields['description'] = Http::optionalString($body, 'description', 20000);
        }
        if (array_key_exists('assigneeId', $body)) {
            $fields['assignee_id'] = self::assigneeOrNull($body['assigneeId']);
        }
        if (array_key_exists('dueDate', $body)) {
            $fields['due_date'] = self::dateOrNull($body['dueDate'], 'dueDate');
        }
        if (array_key_exists('priority', $body)) {
            $fields['priority'] = Http::requireInt($body, 'priority', 0, 3);
        }
        if (array_key_exists('labelIds', $body)) {
            $fields['label_ids'] = Http::requireIntList($body, 'labelIds');
        }
        if (array_key_exists('coverColor', $body)) {
            $value = $body['coverColor'];
            if ($value === null || $value === '') {
                $fields['cover_color'] = null;
            } elseif (!is_string($value) || !LabelRepo::isColor($value)) {
                throw new HttpException(422, 'Неизвестный цвет обложки');
            } else {
                $fields['cover_color'] = $value;
            }
        }
        if (array_key_exists('estimate', $body)) {
            $value = $body['estimate'];
            if ($value === null || $value === '') {
                $fields['estimate'] = null;
            } elseif (!is_int($value) && !is_float($value)) {
                throw new HttpException(422, 'Поле "estimate" должно быть числом');
            } else {
                $fields['estimate'] = (float)$value;
            }
        }

        // Labels are a set, not a column: diff them before the write so the
        // activity trail can say which ones were attached or removed.
        $labelsBefore = array_key_exists('label_ids', $fields)
            ? BoardRepo::cardLabelIds($params['id'])
            : [];
        BoardRepo::updateCard($params['id'], $fields);
        // Label changes get their own events below, so they are not repeated
        // inside the generic "changed fields" entry.
        $changed = array_values(array_diff(array_keys($fields), ['label_ids']));

        $attached = [];
        $detached = [];
        if (array_key_exists('label_ids', $fields)) {
            $labelsAfter = BoardRepo::cardLabelIds($params['id']);
            $attachedIds = array_values(array_diff($labelsAfter, $labelsBefore));
            $detachedIds = array_values(array_diff($labelsBefore, $labelsAfter));
            $names = LabelRepo::namesByIds(array_merge($attachedIds, $detachedIds));
            $attached = array_values(array_map(
                static fn(int $id): string => $names[$id] ?? ('#' . $id),
                $attachedIds,
            ));
            $detached = array_values(array_map(
                static fn(int $id): string => $names[$id] ?? ('#' . $id),
                $detachedIds,
            ));
        }

        if ($changed !== []) {
            EventRepo::log($params['id'], (int)$user['id'], 'card.updated', ['fields' => $changed]);
        }
        if ($attached !== []) {
            EventRepo::log($params['id'], (int)$user['id'], 'label.attached', ['labels' => $attached]);
        }
        if ($detached !== []) {
            EventRepo::log($params['id'], (int)$user['id'], 'label.detached', ['labels' => $detached]);
        }
        return self::sync();
    }

    public static function deleteCard(Request $request, Response $response, array $params): array
    {
        Auth::currentUser($request);
        BoardRepo::deleteCard($params['id']);
        return self::sync();
    }

    public static function archiveCard(Request $request, Response $response, array $params): array
    {
        $user = Auth::currentUser($request);
        BoardRepo::archiveCard($params['id']);
        EventRepo::log($params['id'], (int)$user['id'], 'card.archived');
        return self::sync();
    }

    public static function restoreCard(Request $request, Response $response, array $params): array
    {
        $user = Auth::currentUser($request);
        BoardRepo::restoreCard($params['id']);
        EventRepo::log($params['id'], (int)$user['id'], 'card.restored');
        return self::sync();
    }

    public static function archivedCards(Request $request, Response $response, array $params): array
    {
        Auth::currentUser($request);
        return ['cards' => BoardRepo::archivedCards()];
    }

    public static function orderCards(Request $request, Response $response, array $params): array
    {
        $user = Auth::currentUser($request);
        $body = Http::body($request);
        $columns = $body['columns'] ?? null;
        if (!is_array($columns)) {
            throw new HttpException(422, 'Передайте columns');
        }
        foreach ($columns as $item) {
            if (!is_array($item) || !isset($item['id']) || !is_array($item['cards'] ?? null)) {
                throw new HttpException(422, 'Каждый элемент columns должен содержать id и массив cards');
            }
        }
        // Ordering only reports the cards that changed column; an in-column
        // reorder is not a move and must not pollute the activity trail.
        foreach (BoardRepo::orderCards($columns) as $move) {
            EventRepo::log($move['cardId'], (int)$user['id'], 'card.moved', [
                'from' => $move['fromTitle'],
                'fromId' => $move['fromId'],
                'to' => $move['toTitle'],
                'toId' => $move['toId'],
            ]);
        }
        return self::sync();
    }

    /** Activity trail for one card (lazy-loaded by the card panel). */
    public static function cardEvents(Request $request, Response $response, array $params): array
    {
        Auth::currentUser($request);
        BoardRepo::boardId(); // authorization scope: board must exist
        return ['events' => EventRepo::timeline((int)$params['id'])];
    }

    // --------------------------------------------------------------- comments

    public static function comments(Request $request, Response $response, array $params): array
    {
        Auth::currentUser($request);
        return ['comments' => BoardRepo::comments((int)$params['cardId'])];
    }

    public static function addComment(Request $request, Response $response, array $params): array
    {
        $user = Auth::currentUser($request);
        $bodyText = Http::requireString(Http::body($request), 'body', 5000);
        BoardRepo::addComment($params['cardId'], $bodyText, (int)$user['id']);
        EventRepo::log($params['cardId'], (int)$user['id'], 'comment.added');
        return self::sync();
    }

    public static function deleteComment(Request $request, Response $response, array $params): array
    {
        $user = Auth::currentUser($request);
        $cardId = BoardRepo::deleteComment($params['id']);
        if ($cardId > 0) {
            EventRepo::log($cardId, (int)$user['id'], 'comment.deleted');
        }
        return self::sync();
    }

    // ----------------------------------------------------------------- labels

    public static function createLabel(Request $request, Response $response, array $params): array
    {
        Auth::currentUser($request);
        $body = Http::body($request);
        $name = Http::requireString($body, 'name', 40);
        $id = LabelRepo::create($name, self::labelColor($body['color'] ?? 'violet'));
        $snapshot = self::sync();
        $snapshot['createdLabelId'] = $id;
        return $snapshot;
    }

    public static function updateLabel(Request $request, Response $response, array $params): array
    {
        Auth::currentUser($request);
        $body = Http::body($request);
        $name = array_key_exists('name', $body)
            ? Http::requireString($body, 'name', 40)
            : null;
        $color = array_key_exists('color', $body)
            ? self::labelColor($body['color'])
            : null;
        LabelRepo::update($params['id'], $name, $color);
        return self::sync();
    }

    public static function deleteLabel(Request $request, Response $response, array $params): array
    {
        Auth::currentUser($request);
        LabelRepo::delete($params['id']);
        return self::sync();
    }

    public static function orderLabels(Request $request, Response $response, array $params): array
    {
        Auth::currentUser($request);
        $ids = Http::requireIntList(Http::body($request), 'labelIds');
        LabelRepo::order($ids);
        return self::sync();
    }

    // -------------------------------------------------------------- checklist

    public static function addChecklistItem(Request $request, Response $response, array $params): array
    {
        $user = Auth::currentUser($request);
        $text = Http::requireString(Http::body($request), 'text', 500);
        ChecklistRepo::add($params['cardId'], $text);
        EventRepo::log($params['cardId'], (int)$user['id'], 'checklist.added');
        return self::sync();
    }

    public static function updateChecklistItem(Request $request, Response $response, array $params): array
    {
        $user = Auth::currentUser($request);
        $body = Http::body($request);
        $text = array_key_exists('text', $body)
            ? Http::requireString($body, 'text', 500)
            : null;
        $done = array_key_exists('done', $body) ? Http::requireBool($body, 'done') : null;
        $cardId = ChecklistRepo::update($params['id'], $text, $done);
        if ($cardId > 0) {
            EventRepo::log($cardId, (int)$user['id'], 'checklist.updated', ['done' => $done]);
        }
        return self::sync();
    }

    public static function deleteChecklistItem(Request $request, Response $response, array $params): array
    {
        $user = Auth::currentUser($request);
        $cardId = ChecklistRepo::delete($params['id']);
        if ($cardId > 0) {
            EventRepo::log($cardId, (int)$user['id'], 'checklist.deleted');
        }
        return self::sync();
    }

    public static function orderChecklist(Request $request, Response $response, array $params): array
    {
        Auth::currentUser($request);
        $ids = Http::requireIntList(Http::body($request), 'itemIds');
        ChecklistRepo::order((int)$params['cardId'], $ids);
        return self::sync();
    }

    // ---------------------------------------------------------------- helpers

    private static function labelColor(mixed $value): string
    {
        if (!is_string($value) || !LabelRepo::isColor($value)) {
            throw new HttpException(422, 'Неизвестный цвет метки');
        }
        return $value;
    }

    private static function assigneeOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (int)$value;
        }
        throw new HttpException(422, 'Поле "assigneeId" должно быть числом или null');
    }

    private static function dateOrNull(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new HttpException(422, 'Поле "' . $field . '" должно быть датой YYYY-MM-DD');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new HttpException(422, 'Поле "' . $field . '" должно быть датой YYYY-MM-DD');
        }
        return $value;
    }

    /**
     * Bump the board revision, build a fresh snapshot, hand it to the coalescing
     * broadcaster and return it as the HTTP response.
     *
     * @return array<string,mixed>
     */
    private static function sync(): array
    {
        Revision::bump(BoardRepo::boardId());
        $snapshot = BoardRepo::snapshot();
        Hub::scheduleBoardSync();
        return $snapshot;
    }
}
