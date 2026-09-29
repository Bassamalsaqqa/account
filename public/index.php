<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Candidate paths to locate private Laravel application root
$candidates = [
    __DIR__.'/..',
    $_SERVER['LARAVEL_APP_PATH'] ?? $_ENV['LARAVEL_APP_PATH'] ?? getenv('LARAVEL_APP_PATH') ?: null,
    dirname(__DIR__, 2).'/accounting',
    dirname(__DIR__, 3).'/accounting',
    dirname(__DIR__, 1).'/accounting',
    __DIR__.'/../../accounting',
    __DIR__.'/../../../accounting',
];

$appPath = null;
foreach ($candidates as $candidate) {
    if ($candidate && file_exists($candidate.'/bootstrap/app.php')) {
        $appPath = realpath($candidate) ?: $candidate;
        break;
    }
}

if (! $appPath) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Configuration Error: Laravel application root not found. Please set LARAVEL_APP_PATH or verify the private directory structure.\n";
    exit(1);
}

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = $appPath.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require $appPath.'/vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once $appPath.'/bootstrap/app.php';

// When public directory is separated from application root, register custom public path
if (realpath($appPath) !== realpath(__DIR__.'/..')) {
    $app->usePublicPath(__DIR__);
}

$app->handleRequest(Request::capture());
