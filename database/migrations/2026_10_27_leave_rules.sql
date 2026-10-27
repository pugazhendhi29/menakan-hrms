-- Leave rules are now fixed in the HRMS: the leave year runs 1 January to 31 December, annual leave is 28 days
-- a year (pro rata for part-time workers, joiners and leavers), and bank holidays are given on top of it.
-- The settings that used to control these are removed, and bank holidays are no longer deducted from balances.
SET NAMES utf8mb4;

DELETE FROM system_settings WHERE `key` IN ('leave_year_start_month', 'annual_leave_weeks', 'leave_includes_bank_holidays');
DELETE FROM leave_ledger WHERE entry_type = 'bank_holiday';
