<?php

declare(strict_types=1);

namespace NodesWars\Api;

use NodesWars\Api\Ledger\LedgerController;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App as SlimApp;
use Slim\Factory\AppFactory;

/**
 * Slim application bootstrap.
 *
 * The ledger routes need a Postgres PDO, configured via DATABASE_URL /
 * DATABASE_USER / DATABASE_PASSWORD. When no DATABASE_URL is set the app
 * still boots and /healthz works; ledger routes 503 with a clear JSON error
 * (the deploy probe depends on /healthz, so it must never depend on the DB).
 */
final class App
{
    /**
     * @return SlimApp<ContainerInterface|null>
     */
    public static function bootstrap(): SlimApp
    {
        $container = new \DI\Container();
        $container->set(\PDO::class, static function (): \PDO {
            $dsn = getenv('DATABASE_URL');
            if ($dsn === false || $dsn === '') {
                throw new \RuntimeException('DATABASE_URL is not set');
            }
            $user = getenv('DATABASE_USER') ?: 'postgres';
            $pass = getenv('DATABASE_PASSWORD') ?: '';

            return new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
        });

        $app = AppFactory::create(null, $container);
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(true, true, true);

        $app->get('/healthz', function ($request, $response) {
            $response->getBody()->write('{"ok":true}');
            return $response->withHeader('Content-Type', 'application/json');
        });

        $json503 = static function (ResponseInterface $response): ResponseInterface {
            $response->getBody()->write('{"error":"database not configured"}');
            return $response->withStatus(503)->withHeader('Content-Type', 'application/json');
        };

        $app->post('/matches/{matchId}/ledger/blocks', function ($request, $response, $args) use ($container, $json503) {
            try {
                return (new LedgerController($container->get(\PDO::class)))->submitBlock($request, $response, $args);
            } catch (\RuntimeException) {
                return $json503($response);
            }
        });

        $app->get('/matches/{matchId}/ledger/blocks', function ($request, $response, $args) use ($container, $json503) {
            try {
                return (new LedgerController($container->get(\PDO::class)))->listBlocks($request, $response, $args);
            } catch (\RuntimeException) {
                return $json503($response);
            }
        });

        $app->delete('/matches/{matchId}/players/{playerId}/ledger', function ($request, $response, $args) use ($container, $json503) {
            try {
                return (new LedgerController($container->get(\PDO::class)))->rollbackPlayer($request, $response, $args);
            } catch (\RuntimeException) {
                return $json503($response);
            }
        });

        // PHPStan 2 treats Slim's TContainerInterface template as invariant, so
        // the declared and inferred App<ContainerInterface|null> never compare
        // equal. Drop this once Slim annotates AppFactory::create() generically.
        /** @phpstan-ignore return.type */
        return $app;
    }
}
