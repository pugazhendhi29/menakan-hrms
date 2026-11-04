<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Branded PDF (TCPDF) and Excel (PhpSpreadsheet) exports of management reports: one report, or a
 * team / department / company pack for a period. Each includes the reporting period, employee details,
 * status, management comments and the approval history. Callers check access first.
 */
final class ReportExport
{
    private const INK = '#14283f';
    private const RED = '#d62828';

    // ------------------------------------------------------------------ One report

    /** @return array{0:string,1:string} [file content, file name] */
    public static function reportPdf(array $r): array
    {
        [$html, $title] = self::reportHtml($r);
        return [self::pdf($title, $html), $r['report_no'] . '-' . $r['employee_code'] . '-' . $r['period_start'] . '.pdf'];
    }

    public static function reportXlsx(array $r): array
    {
        $template = ReportService::template((int) $r['template_id']);
        [$values, $calc, $meta, $team, $v] = self::content($r, $template);
        $details = [['Report ID', $r['report_no']], ['Employee name', $meta['employee'] ?? employee_name($r)], ['Employee ID', $meta['employee_code'] ?? $r['employee_code']],
            ['Job title', $meta['job_title'] ?? $r['job_title']], ['Department', $meta['department'] ?? $r['department']], ['Assigned line manager', $meta['line_manager'] ?? trim($r['m_first_name'] . ' ' . $r['m_last_name'])],
            ['Reporting period', ReportPeriod::label($r['period'], $r['period_start']) . ' (' . uk_date($r['period_start']) . ' to ' . uk_date($r['period_end']) . ')'],
            ['Submitted', uk_datetime($r['submitted_at'])], ['Status', ReportService::STATUSES[$r['status']]], ['Version', (string) $r['current_version']], ['Submitted late', $r['is_late'] ? 'Yes' : 'No']];
        $answers = [];
        foreach ($template['fields'] as $f) {
            $val = $f['field_type'] === 'calc' ? ($calc[$f['field_key']] ?? null) : ($values['fields'][$f['field_key']] ?? null);
            $answers[] = [$f['section'] === 'role' ? 'Role-specific' : 'Standard', $f['label'], $f['field_type'] === 'calc' ? 'Calculated' : 'Entered', $f['display'] === 'text' ? (string) $val : ($val === null || $val === '' ? '' : (float) $val)];
        }
        if (!empty($values['critical'])) {
            $answers[] = ['Critical issue', 'Critical issue', 'Entered', (string) $values['critical_note']];
        }
        if (!empty($values['assessment'])) {
            $answers[] = ['Management', 'Management assessment', 'Entered', (string) $values['assessment']];
            $answers[] = ['Management', 'Team performance rating (1-5)', 'Entered', (int) $values['team_rating']];
        }
        $sheets = [['Report', ['Item', 'Value'], $details], ['Answers', ['Section', 'Question', 'Source', 'Answer'], $answers],
            ['Approval history', ['Date', 'Action', 'By', 'Rating', 'Comment'], self::historyRows((int) $r['id'])]];
        if ($team) {
            $sheets[] = ['Team consolidation', ['Employee', 'Employee ID', 'Job title', 'Report ID', 'Status', 'Rating', 'Manager comment', 'Critical issue'],
                array_map(fn ($m) => [$m['name'], $m['code'], $m['job_title'], $m['report']['report_no'] ?? '', $m['report'] ? ReportService::STATUSES[$m['report']['status']] : ($m['expected'] ? 'Not submitted' : 'Not expected'),
                    $m['report']['rating'] ?? '', $m['report']['comment'] ?? '', $m['report']['critical_note'] ?? ''], $team['members'])];
        }
        return [ExcelIO::buildBook($sheets), $r['report_no'] . '-' . $r['employee_code'] . '-' . $r['period_start'] . '.xlsx'];
    }

    /** The submitted version (or the working copy of an unsubmitted report). */
    private static function content(array $r, array $template): array
    {
        $v = (int) $r['current_version'] > 0 ? ReportService::version((int) $r['id']) : null;
        if ($v) {
            return [$v['data'], $v['data']['calculated'] ?? [], $v['data']['meta'] ?? [], $v['consolidation'], $v];
        }
        return [ReportService::data($r), ReportService::calculated($r, $template)['values'], [], null, null];
    }

    private static function historyRows(int $reportId): array
    {
        $rows = [];
        foreach (ReportService::versions($reportId) as $ver) {
            $rows[] = [uk_datetime($ver['submitted_at']), $ver['version_no'] > 1 ? 'Amended and resubmitted (version ' . $ver['version_no'] . ')' : 'Submitted (original)', $ver['username'] ?? '', '', (string) $ver['amendment_note']];
        }
        foreach (ReportService::reviews($reportId) as $rv) {
            $rows[] = [uk_datetime($rv['created_at']), self::reviewLabel($rv['action']), $rv['first_name'] ? employee_name($rv) : ($rv['username'] ?? 'System'), $rv['rating'] ?? '', (string) $rv['comment']];
        }
        usort($rows, fn ($a, $b) => strcmp(self::sortable($a[0]), self::sortable($b[0])));
        return $rows;
    }

    private static function sortable(string $ukDateTime): string
    {
        return preg_match('#^(\d{2})/(\d{2})/(\d{4}) (\d{2}:\d{2})$#', $ukDateTime, $m) ? "$m[3]-$m[2]-$m[1] $m[4]" : $ukDateTime;
    }

    public static function reviewLabel(string $action): string
    {
        return ['opened' => 'Opened for review', 'approved' => 'Approved', 'returned' => 'Returned for correction', 'escalated' => 'Escalated to the next manager',
            'auto_escalated' => 'Escalated automatically (not reviewed in time)', 'commented' => 'Comment'][$action] ?? $action;
    }

    private static function reportHtml(array $r): array
    {
        $template = ReportService::template((int) $r['template_id']);
        [$values, $calc, $meta, $team] = self::content($r, $template);
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $nl = fn ($s) => nl2br($e($s));
        $title = $template['title'];
        $h = self::banner($title, ReportPeriod::label($r['period'], $r['period_start']) . ' · ' . uk_date($r['period_start']) . ($r['period_start'] !== $r['period_end'] ? ' to ' . uk_date($r['period_end']) : ''));
        $details = [
            'Report ID' => $r['report_no'], 'Status' => ReportService::STATUSES[$r['status']] . ($r['is_late'] ? ' (submitted late)' : ''),
            'Employee name' => $meta['employee'] ?? employee_name($r), 'Employee ID' => $meta['employee_code'] ?? $r['employee_code'],
            'Job title' => $meta['job_title'] ?? $r['job_title'], 'Department' => $meta['department'] ?? $r['department'],
            'Assigned line manager' => $meta['line_manager'] ?? (trim($r['m_first_name'] . ' ' . $r['m_last_name']) ?: '—'), 'Submitted' => uk_datetime($r['submitted_at']),
            'Version' => (int) $r['current_version'] > 0 ? 'Version ' . $r['current_version'] . ((int) $r['current_version'] > 1 ? ' (amended; original kept)' : ' (original)') : 'Not submitted',
            'Deadline' => uk_datetime($r['due_at']),
        ];
        $h .= '<table cellpadding="4" cellspacing="0" style="font-size:8.5pt;border:0.3px solid #dfe7f2">';
        $i = 0;
        foreach (array_chunk($details, 2, true) as $pair) {
            $h .= '<tr style="background-color:' . ($i++ % 2 ? '#ffffff' : '#f5f8fd') . '">';
            foreach ($pair as $k => $v) {
                $h .= '<td width="18%" style="color:#5b6b82">' . $e($k) . '</td><td width="32%"><b>' . $e($v) . '</b></td>';
            }
            $h .= '</tr>';
        }
        $h .= '</table>';
        foreach (['role' => 'Role-specific questions', 'standard' => 'Standard report'] as $section => $heading) {
            $h .= self::heading($heading) . '<table cellpadding="4" cellspacing="0" style="font-size:8.5pt">';
            $i = 0;
            foreach ($template['fields'] as $f) {
                if ($f['section'] !== $section) {
                    continue;
                }
                $val = $f['field_type'] === 'calc' ? ($calc[$f['field_key']] ?? null) : ($values['fields'][$f['field_key']] ?? null);
                $h .= '<tr style="background-color:' . ($i++ % 2 ? '#ffffff' : '#f7f9fc') . '"><td width="38%" style="color:#33405f">' . $e($f['label'])
                    . ($f['field_type'] === 'calc' ? ' <font color="#2563c9" size="6.5">CALCULATED</font>' : '') . '</td><td width="62%">'
                    . ($f['display'] === 'text' ? $nl(($val ?? '') !== '' ? $val : '—') : '<b>' . $e(report_value($f['display'], $val)) . '</b>') . '</td></tr>';
            }
            $h .= '</table>';
        }
        if (!empty($values['critical'])) {
            $h .= self::heading('Critical issue', self::RED) . '<p style="font-size:9pt;color:#b91c1c">' . $nl($values['critical_note']) . '</p>';
        }
        if ($team || !empty($values['assessment'])) {
            $h .= self::heading('Management assessment') . '<p style="font-size:9pt">' . $nl($values['assessment'] ?: '—') . '</p>'
                . '<p style="font-size:8.5pt">Team performance rating: <b>' . ($values['team_rating'] ? (int) $values['team_rating'] . ' / 5' : '—') . '</b></p>';
        }
        if ($team) {
            $c = $team['counts'];
            $h .= self::heading('Team consolidation') . '<p style="font-size:8.5pt">' . sprintf('%d in team · %d expected · %d submitted · %d approved · %d awaiting approval · %d returned · %d not submitted%s',
                $c['team'], $c['expected'], $c['received'], $c['approved'], $c['awaiting'], $c['returned'], $c['missing'], $team['avg_rating'] ? ' · average rating ' . $team['avg_rating'] : '') . '</p>';
            $h .= self::table(['Employee', 'Job title', 'Report ID', 'Status', 'Rating', 'Manager comment'], array_map(fn ($m) => [
                $m['name'] . ' (' . $m['code'] . ')', $m['job_title'], $m['report']['report_no'] ?? '—',
                $m['report'] ? ReportService::STATUSES[$m['report']['status']] : ($m['expected'] ? 'Not submitted' : 'Not expected'),
                $m['report']['rating'] ?? '—', $m['report']['comment'] ?? '—'], $team['members']), [24, 18, 13, 15, 7, 23]);
            foreach ($team['roles'] as $role) {
                $h .= '<p style="font-size:8.5pt"><b>' . $e($role['title']) . '</b> · ' . (int) $role['reports'] . ' approved report(s): '
                    . $e(implode(' · ', array_map(fn ($k) => $k['label'] . ' ' . report_value($k['display'], $k['value']), array_filter($role['kpis'], fn ($k) => $k['value'] !== null)))) . '</p>';
            }
        }
        $h .= self::heading('Management comments and approval history') . self::table(['Date', 'Action', 'By', 'Rating', 'Comment'], self::historyRows((int) $r['id']), [14, 22, 18, 7, 39]);
        $att = ReportService::attachments((int) $r['id']);
        if ($att) {
            $h .= self::heading('Supporting attachments') . '<p style="font-size:8.5pt">' . $e(implode(' · ', array_map(fn ($a) => $a['original_filename'] . ' (' . number_format($a['size_bytes'] / 1024) . ' KB)', $att))) . '</p>';
        }
        return [$h, $title . ' · ' . $r['report_no']];
    }

    // ------------------------------------------------------------------ Team / department / company packs

    /**
     * @param int[] $employeeIds people in the pack
     * @return array{0:string,1:string} [content, file name]
     */
    public static function pack(string $format, string $scopeLabel, array $employeeIds, string $period, string $date): array
    {
        [$start, $end] = ReportPeriod::bounds($period, $date);
        $data = self::packData($employeeIds, $period, $start, $end);
        $name = preg_replace('/[^A-Za-z0-9]+/', '-', ReportCatalog::PERIODS[$period] . ' ' . $scopeLabel . ' ' . $start);
        if ($format === 'xlsx') {
            $c = $data['comp']['totals'];
            $summary = [['Scope', $scopeLabel], ['Period', ReportPeriod::label($period, $start)], ['From', uk_date($start)], ['To', uk_date($end)], ['People expected to report', $c['people']],
                ['Reports expected', $c['expected']], ['Submitted', $c['submitted']], ['Approved', $c['approved']], ['Awaiting approval', $c['awaiting']], ['Returned for correction', $c['returned']],
                ['Overdue', $c['overdue']], ['Submitted late', $c['late']], ['Compliance %', $c['rate'] ?? ''], ['Generated', uk_now()->format('d/m/Y H:i')]];
            $kpis = [];
            foreach ($data['areas'] as $a) {
                foreach ($a['kpis'] as $k) {
                    $kpis[] = [$a['area'], $a['title'], $k['label'], $k['value'] === null ? '' : round($k['value'], 2), $k['display'], $a['approved']];
                }
            }
            return [ExcelIO::buildBook([
                ['Summary', ['Item', 'Value'], $summary],
                ['Reports', ['Report ID', 'Employee ID', 'Employee', 'Job title', 'Department', 'Line manager', 'Period start', 'Status', 'Submitted', 'Late', 'Critical issue', 'Rating', 'Manager comment'], $data['reportRows']],
                ['Performance', ['Area', 'Role', 'Indicator', 'Value', 'Unit', 'Approved reports'], $kpis],
                ['Compliance', ['Employee ID', 'Employee', 'Job title', 'Department', 'Expected', 'Submitted', 'Approved', 'Awaiting', 'Overdue', 'Compliance %'], $data['compRows']],
                ['Actions', ['Title', 'Assigned to', 'Priority', 'Due', 'Status', 'Report'], array_map(fn ($a) => [$a['title'], employee_name($a), $a['priority'], uk_date($a['due_date']), $a['status'], $a['report_no']], $data['actions'])],
                ['Approval history', ['Report ID', 'Date', 'Action', 'By', 'Rating', 'Comment'], $data['history']],
            ]), "$name.xlsx"];
        }
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $c = $data['comp']['totals'];
        $h = self::banner(ReportCatalog::PERIODS[$period] . ' Management Report · ' . $scopeLabel, ReportPeriod::label($period, $start) . ' · ' . uk_date($start) . ($start !== $end ? ' to ' . uk_date($end) : ''));
        $h .= self::heading('Reporting compliance') . self::table(['Expected', 'Submitted', 'Approved', 'Awaiting approval', 'Returned', 'Overdue', 'Late', 'Compliance'],
            [[$c['expected'], $c['submitted'], $c['approved'], $c['awaiting'], $c['returned'], $c['overdue'], $c['late'], $c['rate'] === null ? '—' : $c['rate'] . '%']], [12, 12, 12, 14, 12, 12, 12, 14]);
        $areas = array_filter($data['areas'], fn ($a) => $a['approved'] > 0);
        if ($areas) {
            $h .= self::heading('Performance by area (approved reports)');
            foreach ($areas as $a) {
                $h .= '<p style="font-size:8.5pt"><b>' . $e($a['area']) . '</b> <font color="#5b6b82">· ' . $e($a['title']) . ' · ' . (int) $a['approved'] . ' report(s)' . ($a['pending'] ? ', ' . (int) $a['pending'] . ' awaiting approval not included' : '') . '</font><br>'
                    . $e(implode('   ·   ', array_map(fn ($k) => $k['label'] . ': ' . report_value($k['display'], $k['value']), array_filter($a['kpis'], fn ($k) => $k['value'] !== null)))) . '</p>';
            }
        }
        $h .= self::heading('Reports') . self::table(['Report ID', 'Employee', 'Job title', 'Status', 'Submitted', 'Rating', 'Manager comment'],
            array_map(fn ($r) => [$r[0], $r[2] . ' (' . $r[1] . ')', $r[3], $r[7] . ($r[9] === 'Yes' ? ' · late' : ''), $r[8], $r[11], $r[12]], $data['reportRows']), [12, 20, 16, 15, 12, 6, 19]);
        if ($data['critical']) {
            $h .= self::heading('Critical issues', self::RED) . self::table(['Report ID', 'Employee', 'Issue'], array_map(fn ($x) => [$x['report_no'], employee_name($x), $x['critical_note']], $data['critical']), [14, 22, 64]);
        }
        if ($data['assessments']) {
            $h .= self::heading('Management assessments');
            foreach ($data['assessments'] as $a) {
                $h .= '<p style="font-size:8.5pt"><b>' . $e($a['name']) . '</b> <font color="#5b6b82">(' . $e($a['report_no']) . ($a['rating'] ? ' · team rating ' . (int) $a['rating'] . '/5' : '') . ')</font><br>' . nl2br($e($a['text'])) . '</p>';
            }
        }
        if ($data['recommendations']) {
            $h .= self::heading('Management recommendations');
            foreach ($data['recommendations'] as $x) {
                $h .= '<p style="font-size:8.5pt"><b>' . $e($x['name']) . '</b> <font color="#5b6b82">· ' . $e($x['label']) . '</font><br>' . nl2br($e($x['text'])) . '</p>';
            }
        }
        if ($data['actions']) {
            $h .= self::heading('Outstanding management actions') . self::table(['Action', 'Assigned to', 'Priority', 'Due', 'Status'],
                array_map(fn ($a) => [$a['title'], employee_name($a), ucfirst($a['priority']), uk_date($a['due_date']), str_replace('_', ' ', $a['status'])], $data['actions']), [40, 22, 12, 12, 14]);
        }
        $h .= self::heading('Reporting compliance by employee') . self::table(['Employee', 'Job title', 'Expected', 'Submitted', 'Approved', 'Overdue', 'Compliance'],
            array_map(fn ($r) => [$r[1] . ' (' . $r[0] . ')', $r[2], $r[4], $r[5], $r[6], $r[8], $r[9] === '' ? '—' : $r[9] . '%'], $data['compRows']), [26, 22, 10, 11, 10, 10, 11]);
        if ($data['history']) {
            $h .= self::heading('Approval history') . self::table(['Report ID', 'Date', 'Action', 'By', 'Comment'], array_map(fn ($x) => [$x[0], $x[1], $x[2], $x[3], $x[5]], $data['history']), [13, 14, 20, 17, 36]);
        }
        return [self::pdf(ReportCatalog::PERIODS[$period] . ' Management Report · ' . $scopeLabel, $h), "$name.pdf"];
    }

    private static function packData(array $ids, string $period, string $start, string $end): array
    {
        $comp = ReportStats::compliance($ids, $period, $start, $end);
        $reports = $ids ? DB::all(ReportService::select() . ' WHERE r.period = ? AND r.period_start BETWEEN ? AND ? AND r.employee_id IN (' . DB::placeholders($ids) . ') ORDER BY e.first_name, e.last_name, r.period_start',
            [$period, $start, $end, ...$ids]) : [];
        $reportRows = $history = $assessments = [];
        foreach ($reports as $r) {
            $d = ReportService::lastDecision((int) $r['id']);
            $reportRows[] = [$r['report_no'], $r['employee_code'], employee_name($r), $r['job_title'], $r['department'], trim($r['m_first_name'] . ' ' . $r['m_last_name']), uk_date($r['period_start']),
                ReportService::STATUSES[$r['status']], uk_datetime($r['submitted_at']), $r['is_late'] ? 'Yes' : 'No', (string) $r['critical_note'], $d && $d['action'] === 'approved' ? ($d['rating'] ?? '') : '', $d['comment'] ?? ''];
            foreach (self::historyRows((int) $r['id']) as $row) {
                $history[] = [$r['report_no'], ...$row];
            }
            if ((int) $r['current_version'] > 0) {
                $v = ReportService::version((int) $r['id']);
                if (!empty($v['data']['assessment'])) {
                    $assessments[] = ['name' => employee_name($r), 'report_no' => $r['report_no'], 'text' => $v['data']['assessment'], 'rating' => $v['data']['team_rating'] ?? null];
                }
            }
        }
        $people = ReportAccess::people();
        $compRows = [];
        foreach ($comp['rows'] as $id => $c) {
            $p = $people[$id];
            $compRows[] = [$p['employee_code'], employee_name($p), $p['job_title'], $p['department'], $c['expected'], $c['submitted'], $c['approved'], $c['awaiting'], $c['overdue'], $c['rate'] ?? ''];
        }
        usort($compRows, fn ($a, $b) => strcmp($a[1], $b[1]));
        return ['comp' => $comp, 'areas' => ReportStats::areas($ids, $period, $start, $end), 'reportRows' => $reportRows, 'history' => $history, 'assessments' => $assessments,
            'critical' => ReportStats::critical($ids, $start, 50), 'actions' => ReportStats::actions($ids, 50), 'compRows' => $compRows,
            'recommendations' => $period === 'monthly' ? ReportStats::recommendations($ids, $start, 30) : []];
    }

    // ------------------------------------------------------------------ PDF building blocks

    private static function banner(string $title, string $sub): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $logo = BASE_PATH . '/public/assets/images/logo-mark.png';
        $company = (string) setting('company_name', 'Menakan Transport Ltd');
        return '<table cellpadding="0" cellspacing="0"><tr>'
            . (is_file($logo) ? '<td width="12%"><img src="' . $e($logo) . '" width="40" height="40"></td>' : '')
            . '<td width="' . (is_file($logo) ? '88' : '100') . '%"><font size="15" color="' . self::RED . '"><b>' . $e($company) . '</b></font><br>'
            . '<font size="12" color="' . self::INK . '"><b>' . $e($title) . '</b></font><br><font size="8.5" color="#5b6b82">' . $e($sub) . '</font></td></tr></table>'
            . '<div style="border-bottom:1.2px solid ' . self::RED . ';font-size:2pt">&nbsp;</div><br>';
    }

    private static function heading(string $text, string $colour = self::INK): string
    {
        return '<br><div style="font-size:10.5pt;color:' . $colour . ';font-weight:bold;border-bottom:0.5px solid #c9d5e6">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    /** @param int[] $widths percentages */
    private static function table(array $headers, array $rows, array $widths): string
    {
        $e = fn ($s) => nl2br(htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'));
        $h = '<table cellpadding="3.5" cellspacing="0" style="font-size:7.8pt" border="0"><thead><tr style="background-color:' . self::INK . ';color:#ffffff;font-weight:bold">';
        foreach ($headers as $i => $hd) {
            $h .= '<th width="' . $widths[$i] . '%">' . $e($hd) . '</th>';
        }
        $h .= '</tr></thead>';
        foreach (array_values($rows) as $n => $row) {
            $h .= '<tr nobr="true" style="background-color:' . ($n % 2 ? '#ffffff' : '#f5f8fd') . '">';
            foreach (array_values($row) as $i => $v) {
                $h .= '<td width="' . $widths[$i] . '%">' . $e($v === '' || $v === null ? '—' : $v) . '</td>';
            }
            $h .= '</tr>';
        }
        if (!$rows) {
            $h .= '<tr><td width="100%" style="color:#5b6b82">None.</td></tr>';
        }
        return $h . '</table>';
    }

    private static function pdf(string $title, string $html): string
    {
        $pdf = new class('P', 'mm', 'A4', true, 'UTF-8', false) extends \TCPDF {
            public string $footerText = '';

            public function __construct(...$args)
            {
                parent::__construct(...$args);
                $this->tcpdflink = false;
            }

            public function Footer(): void
            {
                $this->SetY(-12);
                $this->SetFont('dejavusans', '', 7);
                $this->SetTextColor(110, 122, 140);
                $this->Cell(0, 6, $this->footerText . '   ·   Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, 0, 'C');
            }
        };
        $pdf->footerText = 'Confidential · ' . setting('company_name', 'Menakan Transport Ltd') . ' · generated ' . uk_now()->format('d/m/Y H:i');
        $pdf->SetCreator('Menakan Transport HR Portal');
        $pdf->SetAuthor((string) setting('company_name', 'Menakan Transport Ltd'));
        $pdf->SetTitle($title);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->SetMargins(14, 12, 14);
        $pdf->SetAutoPageBreak(true, 16);
        $pdf->setFontSubsetting(true);
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');
        return $pdf->Output('', 'S');
    }
}
