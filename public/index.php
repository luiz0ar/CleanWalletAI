<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';

$response = $app->handle(Request::capture());

$response->send();

exit;
    @file_put_contents($logDir . '/webhook.log', $entry, FILE_APPEND);
