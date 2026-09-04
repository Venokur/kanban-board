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

    // Board
    $router->add('GET', '/api/board', fn($req, $res, $p) => BoardController::board($req, $res, $p));

    // Columns
    $router->add('POST', '/api/columns', fn($req, $res, $p) => BoardController::createColumn($req, $res, $p));
    $router->add('PUT', '/api/columns/{id}', fn($req, $res, $p) => BoardController::renameColumn($req, $res, $p));
    $router->add('DELETE', '/api/columns/{id}', fn($req, $res, $p) => BoardController::deleteColumn($req, $res, $p));
    $router->add('POST', '/api/columns/order', fn($req, $res, $p) => BoardController::orderColumns($req, $res, $p));

    // Cards
    $router->add('POST', '/api/cards', fn($req, $res, $p) => BoardController::createCard($req, $res, $p));
    $router->add('PUT', '/api/cards/{id}', fn($req, $res, $p) => BoardController::updateCard($req, $res, $p));
    $router->add('DELETE', '/api/cards/{id}', fn($req, $res, $p) => BoardController::deleteCard($req, $res, $p));
    $router->add('POST', '/api/cards/order', fn($req, $res, $p) => BoardController::orderCards($req, $res, $p));

    // Comments
    $router->add('POST', '/api/cards/{cardId}/comments', fn($req, $res, $p) => BoardController::addComment($req, $res, $p));
    $router->add('DELETE', '/api/comments/{id}', fn($req, $res, $p) => BoardController::deleteComment($req, $res, $p));

    return $router;
};
