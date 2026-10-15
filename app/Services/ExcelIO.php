<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Reading import sheets and writing exports with PhpSpreadsheet. */
final class ExcelIO
{
    public const MAX_ROWS = 5000;

    /**
     * Reads the first sheet. Row 1 must hold the headers; each data row is returned as
     * [row_no => int, values => [canonical header => raw value]]. Fully blank rows are skipped.
     * @param array<string,string> $headerMap lower-case header text => canonical key
     * @param string[] $required canonical keys that must be present
     */
    public static function readRows(string $path, array $headerMap, array $required): array
    {
        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $sheet = $reader->load($path)->getSheet(0);
        } catch (\Throwable) {
            throw new ValidationException('The file could not be read as an Excel workbook (.xlsx). Please use the template.');
        }
        $highestRow = $sheet->getHighestDataRow();
        $highestCol = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        if ($highestRow - 1 > self::MAX_ROWS) {
            throw new ValidationException('The file has more than ' . self::MAX_ROWS . ' rows. Please split it into smaller files.');
        }
        $columns = [];
        for ($c = 1; $c <= $highestCol; $c++) {
            $h = strtolower(trim((string) $sheet->getCell([$c, 1])->getValue()));
            $h = preg_replace('/\s*\(.*\)$/', '', $h);
            if (isset($headerMap[$h])) {
                $columns[$c] = $headerMap[$h];
            }
        }
        $missing = array_diff($required, $columns);
        if ($missing) {
            $labels = array_flip($headerMap);
            throw new ValidationException('The file is missing required columns: ' . implode(', ', array_map(fn ($k) => ucwords($labels[$k] ?? $k), $missing)) . '. Please use the template.');
        }
        $rows = [];
        for ($r = 2; $r <= $highestRow; $r++) {
            $values = [];
            $blank = true;
            foreach ($columns as $c => $key) {
                $v = $sheet->getCell([$c, $r])->getValue();
                if (is_string($v)) {
                    $v = trim($v);
                }
                if ($v !== null && $v !== '') {
                    $blank = false;
                }
                $values[$key] = $v;
            }
            if (!$blank) {
                $rows[] = ['row_no' => $r, 'values' => $values];
            }
        }
        if (!$rows) {
            throw new ValidationException('The file has no data rows.');
        }
        return $rows;
    }

    /** Excel serial number or text (dd/mm/yyyy, dd-mm-yyyy, yyyy-mm-dd) to Y-m-d; null if invalid. */
    public static function parseDate(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v) && (float) $v > 1000)) {
            try {
                return XlDate::excelToDateTimeObject((float) $v)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }
        $s = trim((string) $v);
        foreach (['!d/m/Y', '!j/n/Y', '!d-m-Y', '!j-n-Y', '!Y-m-d', '!d.m.Y'] as $fmt) {
            $d = \DateTimeImmutable::createFromFormat($fmt, $s);
            if ($d !== false && $d->format(ltrim($fmt, '!')) === $s) {
                return $d->format('Y-m-d');
            }
        }
        return null;
    }

    /** Excel day fraction or "HH:MM[:SS]" text to "HH:MM"; null if invalid. */
    public static function parseTime(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_string($v) && preg_match('/^(\d{1,2})[:.](\d{2})(?::\d{2})?$/', trim($v), $m)) {
            return (int) $m[1] < 24 && (int) $m[2] < 60 ? sprintf('%02d:%02d', (int) $m[1], (int) $m[2]) : null;
        }
        if (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) {
            $f = (float) $v;
            if ($f < 0 || $f >= 1) {
                return null;
            }
            $mins = (int) round($f * 1440);
            return $mins >= 1440 ? null : sprintf('%02d:%02d', intdiv($mins, 60), $mins % 60);
        }
        if (preg_match('/^(\d{1,2})[:.](\d{2})(?::\d{2})?$/', trim((string) $v), $m) && (int) $m[1] < 24 && (int) $m[2] < 60) {
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }
        return null;
    }

    /**
     * Builds a workbook with several sheets and returns its bytes.
     * @param array<int, array{0:string,1:array,2:array}> $sheets [title, headers, rows] per sheet, in order
     */
    public static function buildBook(array $sheets): string
    {
        $book = new Spreadsheet();
        foreach (array_values($sheets) as $i => [$title, $headers, $rows]) {
            $sheet = $i === 0 ? $book->getActiveSheet() : $book->createSheet();
            self::fill($sheet, $title, $headers, $rows);
        }
        $book->setActiveSheetIndex(0);
        ob_start();
        (new Xlsx($book))->save('php://output');
        return (string) ob_get_clean();
    }

    /** Builds an .xlsx workbook from headers and rows and returns its bytes. */
    public static function build(string $title, array $headers, array $rows, array $notes = []): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        self::fill($sheet, $title, $headers, $rows);
        if ($notes) {
            $n = $book->createSheet();
            $n->setTitle('Instructions');
            foreach (array_values($notes) as $i => $line) {
                $n->setCellValue([1, $i + 1], $line);
            }
            $n->getColumnDimension('A')->setWidth(110);
            $book->setActiveSheetIndex(0);
        }
        ob_start();
        (new Xlsx($book))->save('php://output');
        return (string) ob_get_clean();
    }

    /** Header row (bold, dark), data rows as text or numbers, auto widths, frozen header. */
    private static function fill(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $title, array $headers, array $rows): void
    {
        $sheet->setTitle(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', '', $title), 0, 31) ?: 'Sheet1');
        $sheet->fromArray($headers, null, 'A1');
        $r = 2;
        foreach ($rows as $row) {
            $c = 1;
            foreach (array_values($row) as $v) {
                // Prevent spreadsheet formula injection from user-entered text.
                if (is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@'], true) && !is_numeric($v)) {
                    $v = "'" . $v;
                }
                $sheet->setCellValueExplicit([$c, $r], $v ?? '', is_int($v) || is_float($v) ? \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC : \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $c++;
            }
            $r++;
        }
        $lastCol = Coordinate::stringFromColumnIndex(max(1, count($headers)));
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A1:{$lastCol}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0F1F33');
        foreach (range(1, count($headers)) as $i) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }
        $sheet->freezePane('A2');
    }

    public static function csv(array $headers, array $rows): string
    {
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $headers);
        foreach ($rows as $row) {
            fputcsv($fh, array_map(function ($v) {
                $v = (string) ($v ?? '');
                return ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true) && !is_numeric($v)) ? "'" . $v : $v;
            }, array_values($row)));
        }
        rewind($fh);
        $out = stream_get_contents($fh);
        fclose($fh);
        return (string) $out;
    }

    /** Template workbooks for imports (written to templates/ and served for download). */
    public static function template(string $kind): string
    {
        if ($kind === 'attendance') {
            return self::build('Attendance', ['Employee ID', 'Employee Name', 'Work Date', 'Check In', 'Check Out', 'Break Minutes', 'Notes'], [
                ['A001', 'Example Person', '12/10/2026', '06:00', '14:30', 30, 'Day shift'],
                ['A001', 'Example Person', '13/10/2026', '22:00', '06:00', 30, 'Night shift: Check Out is the next morning'],
            ], [
                'Menakan Transport Ltd - Attendance import template',
                'Employee ID: required, e.g. A012. Employee Name is optional and only used to help you read the file.',
                'Work Date: required, DD/MM/YYYY (UK). This is the date of the check-in.',
                'Check In / Check Out: required, 24-hour HH:MM in UK local time. A Check Out earlier than Check In means the next day (overnight).',
                'Break Minutes: unpaid break in whole minutes (0 if none).',
                'Duplicates (same employee, date and check-in) are skipped, or updated if you choose; approved records are never overwritten.',
                'Delete the example rows before uploading. Maximum 5,000 rows and 5 MB.',
            ]);
        }
        return self::build('Shifts', ['Employee ID', 'Date', 'Start', 'End', 'Break Minutes', 'Shift Type', 'Location', 'Status', 'Notes'], [
            ['A001', '19/10/2026', '06:00', '14:00', 30, 'EARLY', 'Daventry Depot', 'Draft', ''],
            ['A001', '20/10/2026', '22:00', '06:00', 30, 'NIGHT', 'Daventry Depot', 'Published', 'Ends next morning'],
        ], [
            'Menakan Transport Ltd - Shift import template',
            'Employee ID: required, e.g. A012.',
            'Date: required, DD/MM/YYYY (UK) - the date the shift starts.',
            'Start / End: required, 24-hour HH:MM UK local time. An End earlier than Start means the shift ends the next day.',
            'Break Minutes: unpaid break in whole minutes. Shift Type: optional code (EARLY, LATE, NIGHT, DAY). Location: optional name.',
            'Status: Draft or Published (default Draft). Published shifts are visible to employees.',
            'Overlapping shifts are rejected. Shifts that take an employee over their weekly limit are flagged for approval.',
            'Delete the example rows before uploading. Maximum 5,000 rows and 5 MB.',
        ]);
    }
}
