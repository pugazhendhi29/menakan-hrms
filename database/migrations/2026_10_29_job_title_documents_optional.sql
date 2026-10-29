-- Job titles whose holders do not have to keep documents on file (e.g. Director): no required documents,
-- and their expiring documents are left off the dashboard.
SET NAMES utf8mb4;

ALTER TABLE job_titles ADD COLUMN documents_optional TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = no mandatory documents; not shown in dashboard document alerts' AFTER is_driver;
UPDATE job_titles SET documents_optional = 1 WHERE title = 'Director';
