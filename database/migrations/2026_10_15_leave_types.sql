-- Leave types: the company's list, in this order. Existing types are renamed (so past requests and
-- annual leave balances keep their links); Training, not on the list, is switched off, not deleted.
-- Paid / Uses balance / Employees can request are starting values; admins can change them in Settings > Leave types.
SET NAMES utf8mb4;

ALTER TABLE leave_types ADD COLUMN sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100 AFTER colour;

UPDATE leave_types SET name = 'Annual Leave (Paid Holiday)', sort_order = 1 WHERE code = 'AL';
UPDATE leave_types SET name = 'Sick Leave', sort_order = 2 WHERE code = 'SICK';
UPDATE leave_types SET name = 'Unpaid Leave', sort_order = 5 WHERE code = 'UNP';
UPDATE leave_types SET name = 'Compassionate Leave', sort_order = 6 WHERE code = 'COMP';
UPDATE leave_types SET name = 'Maternity Leave', code = 'MAT', sort_order = 11 WHERE code = 'PAR';
UPDATE leave_types SET is_active = 0, sort_order = 99 WHERE code = 'TRN';

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
 ('Religious Holiday Leave',     'REL',   0, 0, 1, '#2b8a3e', 16)
ON DUPLICATE KEY UPDATE name = VALUES(name), sort_order = VALUES(sort_order), is_active = 1;