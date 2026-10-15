-- Leave: explicit Requested Date and Approved Date. Payslips: record of the email sent to the employee.
SET NAMES utf8mb4;

ALTER TABLE leave_requests
  ADD COLUMN requested_on DATE NULL COMMENT 'Date the employee asked for the leave' AFTER reason,
  ADD COLUMN approved_on  DATE NULL COMMENT 'Date the leave was approved' AFTER requested_on;

UPDATE leave_requests SET requested_on = DATE(created_at) WHERE requested_on IS NULL;
UPDATE leave_requests SET approved_on = DATE(decided_at) WHERE status = 'approved' AND approved_on IS NULL AND decided_at IS NOT NULL;

ALTER TABLE payslips
  ADD COLUMN emailed_to  VARCHAR(190) NULL AFTER archived_at,
  ADD COLUMN emailed_at  DATETIME     NULL AFTER emailed_to,
  ADD COLUMN email_error VARCHAR(255) NULL AFTER emailed_at;
