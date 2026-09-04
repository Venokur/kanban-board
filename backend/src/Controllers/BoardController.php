<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Lib\Auth;
use App\Lib\BoardRepo;
use App\Lib\Http;
use App\Lib\HttpException;
use App\Lib\Hub;
use Swoole\Http\Request;
use Swoole\Http\Response;

final class BoardController
{
    public static function board(Request $request, Response $response, array $params): array
    {
        Auth::currentUser($request);
        return BoardRepo::snapshot();
    }

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

    public static function deleteColumn(Request $request, Response $response, array $params): array
    {
        Auth::currentUser($request);
        BoardRepo::deleteColumn($params['id']);
        return self::sync();
    }

    public static function orderColumns(Request $request, Response $response, array $params): array
    {
        Auth::currentUser($request);
        $body = Http::body($request);
        $ids = $body['columnIds'] ?? null;
        if (!is_array($ids)) {
            throw new HttpException(422, 'Передайте columnIds');
        }
        BoardRepo::orderColumns(array_map('intval', $ids));
        return self::sync();
    }

    public static function createCard(Request $request, Response $response, array $params): array
    {
        $userId = Auth::userId($request);
        $body = Http::body($request);
        $columnId = (int)($body['columnId'] ?? 0);
        $title = Http::requireString($body, 'title', 1000);
        $description = Http::optionalString($body, 'description', 20000);
        BoardRepo::addCard($columnId, $title, $description, $userId);
        return self::sync();
    }

    public static function updateCard(Request $request, Response $response, array $params): array
    {
        Auth::currentUser($request);
        $body = Http::body($request);
        $title = array_key_exists('title', $body)
            ? Http::requireString($body, 'title', 1000)
            : null;
        $description = array_key_exists('description', $body)
            ? Http::optionalString($body, 'description', 20000)
            : null;
        BoardRepo::updateCard($params['id'], $title, $description);
        return self::sync();
    }

    public static function deleteCard(Request $request, Response $response, array $params): array
    {
        Auth::currentUser($request);
        BoardRepo::deleteCard($params['id']);
        return self::sync();
    }

    public static function orderCards(Request $request, Response $response, array $params): array
    {
        Auth::currentUser($request);
        $body = Http::body($request);
        $columns = $body['columns'] ?? null;
        if (!is_array($columns)) {
            throw new HttpException(422, 'Передайте columns');
        }
        BoardRepo::orderCards($columns);
        return self::sync();
    }

    public static function addComment(Request $request, Response $response, array $params): array
    {
        $userId = Auth::userId($request);
        $bodyText = Http::requireString(Http::body($request), 'body', 5000);
        BoardRepo::addComment($params['cardId'], $bodyText, $userId);
        return self::sync();
    }

    public static function deleteComment(Request $request, Response $response, array $params): array
    {
        Auth::currentUser($request);
        BoardRepo::deleteComment($params['id']);
        return self::sync();
    }

    /**
     * Build a fresh snapshot, broadcast it over WS and return it as HTTP response.
     * @return array<string,mixed>
     */
    private static function sync(): array
    {
        $snapshot = BoardRepo::snapshot();
        Hub::broadcast('board:sync', $snapshot);
        return $snapshot;
    }
}
