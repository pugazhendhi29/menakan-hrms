-- Attendance recorded from the rota: published shifts that have ended (up to 31/10/2026) get an attendance
-- record with the shift's times, marked source = 'rota'. Those records can only be changed on a day the
-- employee is on approved leave.
SET NAMES utf8mb4;

ALTER TABLE attendance_records MODIFY source ENUM('web','import','manual','rota') NOT NULL COMMENT 'rota = recorded from the published shift (AttendanceService::fillFromRota)';
