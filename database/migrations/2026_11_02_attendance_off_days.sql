-- Attendance from the rota extended to 04/11/2026: days without a published shift are recorded as 'off'
-- (source = 'rota', check-in = check-out = UK midnight, 0 hours). Shift times now replace the employee's own check-ins.
SET NAMES utf8mb4;

ALTER TABLE attendance_records MODIFY status ENUM('present','late','incomplete','off') NOT NULL DEFAULT 'present' COMMENT 'off = no shift on the rota that day';
