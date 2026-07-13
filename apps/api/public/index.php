<?php

declare(strict_types=1);

use NodesWars\Api\App;

require __DIR__ . '/../vendor/autoload.php';

$app = App::bootstrap();
$app->run();
