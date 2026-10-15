<?php

/**
 * Shared bootstrap for the web front controller, CLI scripts and tests.
 * Loads Composer, the .env file and configuration, and fixes PHP's timezone to UTC:
 * all stored datetimes are UTC and are converted to Europe/London only for display and input.
 */

declare(strict_types=1);

use App\Core\Config;

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';

if (is_file(BASE_PATH . '/.env')) {
    Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();
}

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

Config::load([
    'app'      => require BASE_PATH . '/config/app.php',
    'database' => require BASE_PATH . '/config/database.php',
]);

if (!Config::get('app.debug')) {
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH . '/storage/logs/php-error.log');
