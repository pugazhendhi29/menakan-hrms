<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use App\Core\Config;

// Never let tests touch the real database.
Config::set('database.database', getenv('TEST_DB_DATABASE') ?: 'menakan_hrms_test');
App\Services\FileStore::$requireHttpUpload = false;

// ...nor the real private files: tests write uploads, payslips and the mail log to a throw-away folder.
$testStorage = sys_get_temp_dir() . '/menakan-hrms-test-storage';
foreach (['documents', 'payslips', 'photos', 'imports', 'tmp', 'logs', 'sessions'] as $dir) {
    if (!is_dir("$testStorage/$dir")) {
        mkdir("$testStorage/$dir", 0777, true);
    }
}
Config::set('app.storage_path', $testStorage);

// ...and never send real email, whatever .env says.
Config::set('app.mail.driver', 'log');
