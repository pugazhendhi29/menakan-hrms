-- News threads access: only users with this permission may date a thread email before today.
-- Everyone else can only date it today (up to the current time). Existing admins keep backdating.
SET NAMES utf8mb4;

ALTER TABLE users ADD COLUMN can_backdate_threads TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = may date news thread emails before today' AFTER must_change_password;
UPDATE users SET can_backdate_threads = 1 WHERE role = 'admin';
