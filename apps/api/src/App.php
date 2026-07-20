<?php

declare(strict_types=1);

namespace NodesWars\Api;

use Psr\Container\ContainerInterface;
use Slim\App as SlimApp;
use Slim\Factory\AppFactory;

/**
 * Slim application bootstrap. Nothing implemented yet.
 */
final class App
{
    /**
     * @return SlimApp<ContainerInterface|null>
     */
    public static function bootstrap(): SlimApp
    {
        $app = AppFactory::create();
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(true, true, true);

        $app->get('/healthz', function ($request, $response) {
            $response->getBody()->write('{"ok":true}');
            return $response->withHeader('Content-Type', 'application/json');
        });

        // PHPStan 2 treats Slim's TContainerInterface template as invariant, so
        // the declared and inferred App<ContainerInterface|null> never compare
        // equal. Drop this once Slim annotates AppFactory::create() generically.
        /** @phpstan-ignore return.type */
        return $app;
    }
}
