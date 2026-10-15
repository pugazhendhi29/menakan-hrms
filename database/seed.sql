-- =====================================================================
-- Menakan HRMS - starting setup lists (no people, no demo records).
-- Load after database.sql:  mysql -u root menakan_hrms < database/seed.sql
--
-- Contains only generic lists the app uses: work patterns, shift types, leave types,
-- the 13 Menakan document categories (+ "everyone needs" requirements) and England & Wales bank holidays.
-- Departments, job titles and locations are company-specific: add them in Settings
-- before creating the first employee. Everything here can be edited in Settings.
-- =====================================================================

SET NAMES utf8mb4;

INSERT INTO work_patterns (name, mon, tue, wed, thu, fri, sat, sun, hours_per_day) VALUES
 ('Monday to Friday',    1, 1, 1, 1, 1, 0, 0, 7.50),
 ('Tuesday to Saturday', 0, 1, 1, 1, 1, 1, 0, 7.50),
 ('Part time Mon-Wed',   1, 1, 1, 0, 0, 0, 0, 7.00);

INSERT INTO shift_types (name, code, default_start, default_end, default_break_minutes, is_night, colour) VALUES
 ('Early',      'EARLY', '06:00', '14:00', 30, 0, '#1f5fa8'),
 ('Late',       'LATE',  '14:00', '22:00', 30, 0, '#7a4fb5'),
 ('Night',      'NIGHT', '22:00', '06:00', 30, 1, '#1b3150'),
 ('Office day', 'DAY',   '09:00', '17:00', 30, 0, '#1f8a4c'),
 ('Long day',   'LONG',  '06:00', '15:00', 30, 0, '#a86400');

-- 'AL' (Annual leave) is required by the leave balance rules; do not change its code.
INSERT INTO leave_types (name, code, is_paid, deducts_balance, employee_can_request, colour, sort_order) VALUES
 ('Annual Leave (Paid Holiday)', 'AL',    1, 1, 1, '#1f8a4c', 1),
 ('Sick Leave',                  'SICK',  1, 0, 0, '#c0392b', 2),
 ('Statutory Sick Pay (SSP)',    'SSP',   1, 0, 0, '#d9480f', 3),
 ('Company Sick Pay',            'CSP',   1, 0, 0, '#e8590c', 4),
 ('Unpaid Leave',                'UNP',   0, 0, 1, '#5b6b7f', 5),
 ('Compassionate Leave',         'COMP',  1, 0, 1, '#5b3fb5', 6),
 ('Bereavement Leave',           'BRV',   1, 0, 1, '#495057', 7),
 ('Emergency Leave',             'EMG',   0, 0, 1, '#e67700', 8),
 ('Time Off for Dependants',     'TOD',   0, 0, 1, '#0c8599', 9),
 ('Carer''s Leave',              'CARER', 0, 0, 1, '#087f5b', 10),
 ('Maternity Leave',             'MAT',   1, 0, 0, '#a86400', 11),
 ('Statutory Maternity Pay',     'SMP',   1, 0, 0, '#c2255c', 12),
 ('Paternity Leave',             'PAT',   1, 0, 0, '#1f5fa8', 13),
 ('Statutory Paternity Pay',     'SPP',   1, 0, 0, '#5c7cfa', 14),
 ('Marriage / Wedding Leave',    'MAR',   1, 0, 1, '#ae3ec9', 15),
 ('Religious Holiday Leave',     'REL',   0, 0, 1, '#2b8a3e', 16);

INSERT INTO document_categories (code, name, has_expiry, is_sensitive, visible_to_employee, description, sort_order) VALUES
 ('EDU',       'EDUCATIONAL DOCUMENTS',   0, 0, 1, 'Certificates and transcripts', 1),
 ('EVISA',     'e-VISA',                  1, 1, 1, 'UKVI eVisa record', 2),
 ('BRP',       'BRP',                     1, 1, 1, 'Biometric residence permit', 3),
 ('RTW',       'RIGHT TO WORK',           0, 1, 1, 'Right to work check (share code or document check)', 4),
 ('PASSPORT',  'PASSPORT',                1, 1, 1, NULL, 5),
 ('DBS',       'DBS',                     0, 1, 1, 'Disclosure and Barring Service certificate', 6),
 ('OFFER',     'OFFER LETTER',            0, 0, 1, NULL, 7),
 ('CONTRACT',  'JOB CONTRACT LETTER',     0, 0, 1, NULL, 8),
 ('APPRAISAL', 'APPRAISAL LETTER',        0, 0, 1, NULL, 9),
 ('P45',       'P-45 LETTER',             0, 1, 1, NULL, 10),
 ('RESUME',    'RESUME',                  0, 0, 1, 'CV', 11),
 ('P60',       'P-60 LETTER',             0, 1, 1, NULL, 12),
 ('HEALTH',    'HEALTH DECLARATION FORM', 0, 1, 1, NULL, 13);
-- Required for every employee. Add department / job title rules in Settings > Document requirements.
INSERT INTO document_requirements (category_id, department_id, job_title_id, is_mandatory)
SELECT id, NULL, NULL, 1 FROM document_categories WHERE code IN ('RTW', 'CONTRACT');

INSERT INTO public_holidays (holiday_date, name) VALUES
 ('2025-01-01', 'New Year''s Day'), ('2025-04-18', 'Good Friday'), ('2025-04-21', 'Easter Monday'),
 ('2025-05-05', 'Early May bank holiday'), ('2025-05-26', 'Spring bank holiday'), ('2025-08-25', 'Summer bank holiday'),
 ('2025-12-25', 'Christmas Day'), ('2025-12-26', 'Boxing Day'),
 ('2026-01-01', 'New Year''s Day'), ('2026-04-03', 'Good Friday'), ('2026-04-06', 'Easter Monday'),
 ('2026-05-04', 'Early May bank holiday'), ('2026-05-25', 'Spring bank holiday'), ('2026-08-31', 'Summer bank holiday'),
 ('2026-12-25', 'Christmas Day'), ('2026-12-28', 'Boxing Day (substitute day)'),
 ('2027-01-01', 'New Year''s Day'), ('2027-03-26', 'Good Friday'), ('2027-03-29', 'Easter Monday'),
 ('2027-05-03', 'Early May bank holiday'), ('2027-05-31', 'Spring bank holiday'), ('2027-08-30', 'Summer bank holiday'),
 ('2027-12-27', 'Christmas Day (substitute day)'), ('2027-12-28', 'Boxing Day (substitute day)');
