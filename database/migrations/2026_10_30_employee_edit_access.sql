-- Employees access: only users with this permission may edit employee details (the edit form and the photo).
-- Existing admins keep editing.
SET NAMES utf8mb4;

ALTER TABLE users ADD COLUMN can_edit_employees TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = may edit employee details' AFTER can_backdate_threads;
UPDATE users SET can_edit_employees = 1 WHERE role = 'admin';
