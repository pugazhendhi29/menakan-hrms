-- Employee form: reporting manager may be free text (someone outside the system),
-- a default shift alongside the work pattern, and a right-to-work follow-up date.
SET NAMES utf8mb4;

ALTER TABLE employees
  ADD COLUMN reporting_manager_name VARCHAR(120) NULL COMMENT 'Used when the manager is not an employee in the system' AFTER line_manager_id,
  ADD COLUMN default_shift_type_id  INT UNSIGNED NULL AFTER work_pattern_id,
  ADD COLUMN rtw_followup_date      DATE NULL COMMENT 'Next right to work check due' AFTER rtw_check_date,
  ADD KEY idx_employees_rtw_followup (rtw_followup_date),
  ADD CONSTRAINT fk_employees_default_shift FOREIGN KEY (default_shift_type_id) REFERENCES shift_types(id) ON DELETE SET NULL;
