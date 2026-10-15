<?php

/** Regenerates the Excel import templates in templates/. */

declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use App\Services\ExcelIO;

file_put_contents(BASE_PATH . '/templates/Attendance_Import_Template.xlsx', ExcelIO::template('attendance'));
file_put_contents(BASE_PATH . '/templates/Shift_Import_Template.xlsx', ExcelIO::template('shifts'));
fwrite(STDOUT, "Templates written to templates/.\n");
