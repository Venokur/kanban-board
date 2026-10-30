<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\BoardController;
use App\Lib\Router;

return static function (): Router {
    $router = new Router();

    // Auth
    $router->add('POST', '/api/register', fn($req, $res, $p) => AuthController::register($req, $res, $p));
    $router->add('POST', '/api/login', fn($req, $res, $p) => AuthController::login($req, $res, $p));
    $router->add('POST', '/api/logout', fn($req, $res, $p) => AuthController::logout($req, $res, $p));
    $router->add('GET', '/api/me', fn($req, $res, $p) => AuthController::me($req, $res, $p));
    // Public flags for the login page: is the demo account usable, is the CSRF
    // secret still a shipped placeholder. No session, no secrets in the answer.
    $router->add('GET', '/api/config', fn($req, $res, $p) => AuthController::publicConfig($req, $res, $p));

    // Board
    $router->add('GET', '/api/board', fn($req, $res, $p) => BoardController::board($req, $res, $p));
    $router->add('PUT', '/api/board', fn($req, $res, $p) => BoardController::renameBoard($req, $res, $p));
    $router->add('GET', '/api/board/archive', fn($req, $res, $p) => BoardController::archivedCards($req, $res, $p));

    // Columns
    $router->add('POST', '/api/columns', fn($req, $res, $p) => BoardController::createColumn($req, $res, $p));
    $router->add('PUT', '/api/columns/{id}', fn($req, $res, $p) => BoardController::updateColumn($req, $res, $p));
    $router->add('DELETE', '/api/columns/{id}', fn($req, $res, $p) => BoardController::deleteColumn($req, $res, $p));
    $router->add('POST', '/api/columns/order', fn($req, $res, $p) => BoardController::orderColumns($req, $res, $p));

    // Cards
    $router->add('POST', '/api/cards', fn($req, $res, $p) => BoardController::createCard($req, $res, $p));
    $router->add('PUT', '/api/cards/{id}', fn($req, $res, $p) => BoardController::updateCard($req, $res, $p));
    $router->add('DELETE', '/api/cards/{id}', fn($req, $res, $p) => BoardController::deleteCard($req, $res, $p));
    $router->add('POST', '/api/cards/order', fn($req, $res, $p) => BoardController::orderCards($req, $res, $p));
    $router->add('POST', '/api/cards/{id}/archive', fn($req, $res, $p) => BoardController::archiveCard($req, $res, $p));
    $router->add('POST', '/api/cards/{id}/restore', fn($req, $res, $p) => BoardController::restoreCard($req, $res, $p));
    $router->add('GET', '/api/cards/{id}/events', fn($req, $res, $p) => BoardController::cardEvents($req, $res, $p));

    // Labels
    $router->add('POST', '/api/labels', fn($req, $res, $p) => BoardController::createLabel($req, $res, $p));
    $router->add('PUT', '/api/labels/{id}', fn($req, $res, $p) => BoardController::updateLabel($req, $res, $p));
    $router->add('DELETE', '/api/labels/{id}', fn($req, $res, $p) => BoardController::deleteLabel($req, $res, $p));
    $router->add('POST', '/api/labels/order', fn($req, $res, $p) => BoardController::orderLabels($req, $res, $p));

    // Checklist
    $router->add('POST', '/api/cards/{cardId}/checklist', fn($req, $res, $p) => BoardController::addChecklistItem($req, $res, $p));
    $router->add('POST', '/api/cards/{cardId}/checklist/order', fn($req, $res, $p) => BoardController::orderChecklist($req, $res, $p));
    $router->add('PUT', '/api/checklist/{id}', fn($req, $res, $p) => BoardController::updateChecklistItem($req, $res, $p));
    $router->add('DELETE', '/api/checklist/{id}', fn($req, $res, $p) => BoardController::deleteChecklistItem($req, $res, $p));

    // Comments
    $router->add('GET', '/api/cards/{cardId}/comments', fn($req, $res, $p) => BoardController::comments($req, $res, $p));
    $router->add('POST', '/api/cards/{cardId}/comments', fn($req, $res, $p) => BoardController::addComment($req, $res, $p));
    $router->add('DELETE', '/api/comments/{id}', fn($req, $res, $p) => BoardController::deleteComment($req, $res, $p));

    return $router;
};
