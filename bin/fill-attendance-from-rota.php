<?php

/**
 * Records attendance from the rota for published shifts that have ended (up to the date in
 * AttendanceService::ROTA_UNTIL). Run it daily (with the expiry reminders); running it more often is harmless.
 *
 *   php bin/fill-attendance-from-rota.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use App\Services\AttendanceService;

$r = AttendanceService::fillFromRota();
fwrite(STDOUT, sprintf("Attendance from the rota (until %s): %d recorded, %d updated, %d removed\n", uk_date(AttendanceService::ROTA_UNTIL), $r['created'], $r['updated'], $r['removed']));
