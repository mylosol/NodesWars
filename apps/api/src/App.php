<?php

declare(strict_types=1);

namespace NodesWars\Api;

use Slim\App as SlimApp;
use Slim\Factory\AppFactory;

/**
 * Slim application bootstrap. Nothing implemented yet.
 */
final class App
{
    public static function bootstrap(): SlimApp
    {
        $app = AppFactory::create();
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(true, true, true);

        $app->get('/healthz', function ($request, $response) {
            $response->getBody()->write('{"ok":true}');
            return $response->withHeader('Content-Type', 'application/json');
        });

        return $app;
    }
}
