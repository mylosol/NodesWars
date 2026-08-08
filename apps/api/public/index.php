<?php

declare(strict_types=1);

use NodesWars\Api\App;

require __DIR__ . '/../vendor/autoload.php';

// The host keeps a persistent .env (deploy mirror excludes it so it
// survives), populated from the production environment secrets. safeLoad
// means local/dev boots without a .env still work — getenv falls back to
// real environment variables.
Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

$app = App::bootstrap();
$app->run();
