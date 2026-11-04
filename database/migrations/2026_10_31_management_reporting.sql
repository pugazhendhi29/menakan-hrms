-- Management reporting: Daily, Weekly and Monthly role-specific reports that travel up the real line-management
-- hierarchy (employees.line_manager_id) to the Directors. Apply this file first, then 2026_10_31_report_templates.sql.
SET NAMES utf8mb4;

-- ---------------------------------------------------------------- The 12 approved job titles
-- Each approved title carries a fixed role code that links it to its three reporting templates
-- (renaming a title in Settings does not break the link).
ALTER TABLE job_titles ADD COLUMN report_role VARCHAR(40) NULL COMMENT 'Approved reporting role (ReportCatalog::ROLES); NULL = not an approved title' AFTER documents_optional,
  ADD UNIQUE KEY uq_job_titles_report_role (report_role);

-- "FINACE MANAGER" is the Financial Accounts Manager (same record, so its employee stays linked).
UPDATE job_titles SET title = 'Financial Accounts Manager' WHERE title = 'FINACE MANAGER';
-- "Driver" is not an approved title: removed when nobody holds it.
DELETE FROM job_titles WHERE title = 'Driver' AND id NOT IN (SELECT job_title_id FROM employees WHERE job_title_id IS NOT NULL);
-- "OFFICE MANAGER" is not approved: no longer offered; its holder keeps it until HR assigns an approved title.
UPDATE job_titles SET is_active = 0 WHERE title = 'OFFICE MANAGER';

INSERT INTO job_titles (title) SELECT 'Director' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM job_titles WHERE title = 'Director');
INSERT INTO job_titles (title) SELECT 'Transport Manager' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM job_titles WHERE title = 'Transport Manager');
INSERT INTO job_titles (title) SELECT 'Logistics Co-ordinator' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM job_titles WHERE title = 'Logistics Co-ordinator');
INSERT INTO job_titles (title) SELECT 'Delivery Service Manager' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM job_titles WHERE title = 'Delivery Service Manager');
INSERT INTO job_titles (title) SELECT 'Fleet Manager' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM job_titles WHERE title = 'Fleet Manager');
INSERT INTO job_titles (title) SELECT 'Business Support Manager' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM job_titles WHERE title = 'Business Support Manager');
INSERT INTO job_titles (title) SELECT 'Freight Administrator' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM job_titles WHERE title = 'Freight Administrator');
INSERT INTO job_titles (title) SELECT 'Distribution Service Manager' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM job_titles WHERE title = 'Distribution Service Manager');
INSERT INTO job_titles (title) SELECT 'Route Planning Manager' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM job_titles WHERE title = 'Route Planning Manager');
INSERT INTO job_titles (title) SELECT 'Business Development Manager' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM job_titles WHERE title = 'Business Development Manager');
INSERT INTO job_titles (title) SELECT 'Operations Supervisor' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM job_titles WHERE title = 'Operations Supervisor');
INSERT INTO job_titles (title) SELECT 'Financial Accounts Manager' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM job_titles WHERE title = 'Financial Accounts Manager');

UPDATE job_titles SET report_role = 'director', is_active = 1 WHERE title = 'Director';
UPDATE job_titles SET report_role = 'transport_manager', is_active = 1 WHERE title = 'Transport Manager';
UPDATE job_titles SET report_role = 'logistics_coordinator', is_active = 1 WHERE title = 'Logistics Co-ordinator';
UPDATE job_titles SET report_role = 'delivery_service_manager', is_active = 1 WHERE title = 'Delivery Service Manager';
UPDATE job_titles SET report_role = 'fleet_manager', is_active = 1 WHERE title = 'Fleet Manager';
UPDATE job_titles SET report_role = 'business_support_manager', is_active = 1 WHERE title = 'Business Support Manager';
UPDATE job_titles SET report_role = 'freight_administrator', is_active = 1 WHERE title = 'Freight Administrator';
UPDATE job_titles SET report_role = 'distribution_service_manager', is_active = 1 WHERE title = 'Distribution Service Manager';
UPDATE job_titles SET report_role = 'route_planning_manager', is_active = 1 WHERE title = 'Route Planning Manager';
UPDATE job_titles SET report_role = 'business_development_manager', is_active = 1 WHERE title = 'Business Development Manager';
UPDATE job_titles SET report_role = 'operations_supervisor', is_active = 1 WHERE title = 'Operations Supervisor';
UPDATE job_titles SET report_role = 'financial_accounts_manager', is_active = 1 WHERE title = 'Financial Accounts Manager';

-- Per-user switch for report emails (in-app notifications always arrive).
ALTER TABLE users ADD COLUMN report_email_opt_out TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = no emails about management reports' AFTER can_edit_employees;
-- Only users with this access may start a report for a past period (a backdated report). HR admins have it.
ALTER TABLE users ADD COLUMN can_backdate_reports TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = may create backdated management reports' AFTER report_email_opt_out;
UPDATE users SET can_backdate_reports = 1 WHERE role = 'admin';

-- ---------------------------------------------------------------- Templates (36 rows, seeded by 2026_10_31_report_templates.sql)
CREATE TABLE report_templates (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_code   VARCHAR(40)  NOT NULL COMMENT 'job_titles.report_role',
  period      ENUM('daily','weekly','monthly') NOT NULL,
  title       VARCHAR(150) NOT NULL,
  version     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_report_templates (role_code, period, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE report_template_fields (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_id INT UNSIGNED NOT NULL,
  section     ENUM('role','standard') NOT NULL,
  field_key   VARCHAR(60)  NOT NULL,
  label       VARCHAR(200) NOT NULL,
  field_type  ENUM('int','dec','money','pct','text','calc') NOT NULL,
  formula     VARCHAR(255) NULL COMMENT 'calc only: filled from daily data / weekly reports (ReportFormula)',
  display     ENUM('int','dec','money','pct','text') NOT NULL,
  is_required TINYINT(1)   NOT NULL DEFAULT 0,
  is_kpi      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Shown on manager and Directors dashboards',
  sort_order  SMALLINT UNSIGNED NOT NULL,
  UNIQUE KEY uq_report_template_fields (template_id, field_key),
  CONSTRAINT fk_rtf_template FOREIGN KEY (template_id) REFERENCES report_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- Reports
-- One report per employee per period. Who it goes to is the employee's line manager at the time of submission.
CREATE TABLE reports (
  id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_no                VARCHAR(20)  NULL COMMENT 'Report ID shown to people, e.g. MT-D-000123',
  template_id              INT UNSIGNED NOT NULL,
  employee_id              INT UNSIGNED NOT NULL COMMENT 'Author',
  period                   ENUM('daily','weekly','monthly') NOT NULL,
  period_start             DATE         NOT NULL,
  period_end               DATE         NOT NULL,
  status                   ENUM('draft','submitted','under_review','returned','approved','consolidated','forwarded','escalated','overdue','completed') NOT NULL DEFAULT 'draft',
  data                     LONGTEXT     NULL COMMENT 'JSON working copy (draft). Submitted content lives in report_versions and never changes',
  current_version          SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = never submitted',
  department_id            INT UNSIGNED NULL COMMENT 'At submission',
  job_title_id             INT UNSIGNED NULL COMMENT 'At submission',
  line_manager_id          INT UNSIGNED NULL COMMENT 'Assigned line manager at submission (the reviewer)',
  escalated_to_employee_id INT UNSIGNED NULL COMMENT 'Next manager up when escalated; may then decide',
  has_critical             TINYINT(1)   NOT NULL DEFAULT 0,
  critical_note            VARCHAR(500) NULL,
  is_late                  TINYINT(1)   NOT NULL DEFAULT 0,
  is_backdated             TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = created for a past period after its deadline (Backdated report)',
  due_at                   DATETIME     NOT NULL COMMENT 'UTC',
  submitted_at             DATETIME     NULL COMMENT 'Submission date and time shown on the report (people with backdated report access may set it)',
  received_at              DATETIME     NULL COMMENT 'When the line manager actually received it (deadline reminders and escalation use this)',
  decided_at               DATETIME     NULL,
  forwarded_at             DATETIME     NULL,
  completed_at             DATETIME     NULL,
  created_by               INT UNSIGNED NULL,
  created_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reports_period (employee_id, period, period_start),
  UNIQUE KEY uq_reports_no (report_no),
  KEY idx_reports_manager (line_manager_id, status),
  KEY idx_reports_escalated (escalated_to_employee_id, status),
  KEY idx_reports_period (period, period_start, status),
  CONSTRAINT fk_reports_template FOREIGN KEY (template_id) REFERENCES report_templates(id),
  CONSTRAINT fk_reports_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
  CONSTRAINT fk_reports_manager FOREIGN KEY (line_manager_id) REFERENCES employees(id) ON DELETE SET NULL,
  CONSTRAINT fk_reports_escalated FOREIGN KEY (escalated_to_employee_id) REFERENCES employees(id) ON DELETE SET NULL,
  CONSTRAINT fk_reports_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
  CONSTRAINT fk_reports_job_title FOREIGN KEY (job_title_id) REFERENCES job_titles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every submission is frozen here. Version 1 is the original; later versions are amendments with a reason.
CREATE TABLE report_versions (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_id      INT UNSIGNED NOT NULL,
  version_no     SMALLINT UNSIGNED NOT NULL,
  data           LONGTEXT     NOT NULL COMMENT 'JSON: role fields, standard fields and calculated values as submitted',
  consolidation  LONGTEXT     NULL COMMENT 'JSON snapshot of the team reports a manager consolidated',
  amendment_note VARCHAR(1000) NULL COMMENT 'Why the report was changed (versions after 1)',
  submitted_by   INT UNSIGNED NULL,
  submitted_at   DATETIME     NOT NULL,
  UNIQUE KEY uq_report_versions (report_id, version_no),
  CONSTRAINT fk_report_versions_report FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Numeric values of the latest submitted version, for totals, trends and dashboards.
CREATE TABLE report_metrics (
  report_id INT UNSIGNED NOT NULL,
  field_key VARCHAR(60)  NOT NULL,
  value     DECIMAL(16,4) NOT NULL,
  PRIMARY KEY (report_id, field_key),
  KEY idx_report_metrics_key (field_key),
  CONSTRAINT fk_report_metrics_report FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Manager assessments and approvals: every review action, with the manager's comment.
CREATE TABLE report_reviews (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_id            INT UNSIGNED NOT NULL,
  version_no           SMALLINT UNSIGNED NOT NULL,
  reviewer_employee_id INT UNSIGNED NULL,
  reviewer_user_id     INT UNSIGNED NULL COMMENT 'NULL = the scheduler',
  action               ENUM('opened','approved','returned','escalated','auto_escalated','commented') NOT NULL,
  rating               TINYINT UNSIGNED NULL COMMENT '1-5 performance assessment',
  comment              TEXT         NULL,
  created_at           DATETIME     NOT NULL,
  KEY idx_report_reviews_report (report_id, created_at),
  CONSTRAINT fk_report_reviews_report FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE,
  CONSTRAINT fk_report_reviews_employee FOREIGN KEY (reviewer_employee_id) REFERENCES employees(id) ON DELETE SET NULL,
  CONSTRAINT fk_report_reviews_user FOREIGN KEY (reviewer_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A manager's report consolidates the approved reports of their team for the same period.
CREATE TABLE report_consolidations (
  parent_report_id INT UNSIGNED NOT NULL,
  child_report_id  INT UNSIGNED NOT NULL,
  created_at       DATETIME     NOT NULL,
  PRIMARY KEY (parent_report_id, child_report_id),
  KEY idx_report_consolidations_child (child_report_id),
  CONSTRAINT fk_rc_parent FOREIGN KEY (parent_report_id) REFERENCES reports(id) ON DELETE CASCADE,
  CONSTRAINT fk_rc_child FOREIGN KEY (child_report_id) REFERENCES reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE report_attachments (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_id         INT UNSIGNED NOT NULL,
  version_no        SMALLINT UNSIGNED NULL COMMENT 'Version it was submitted with; NULL while still a draft attachment',
  storage_key       VARCHAR(255) NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  mime_type         VARCHAR(100) NOT NULL,
  size_bytes        INT UNSIGNED NOT NULL,
  uploaded_by       INT UNSIGNED NULL,
  created_at        DATETIME     NOT NULL,
  KEY idx_report_attachments (report_id),
  CONSTRAINT fk_report_attachments_report FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Follow-up actions managers raise from reports (and Directors from consolidated reports).
CREATE TABLE management_actions (
  id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_id               INT UNSIGNED NULL,
  raised_by_employee_id   INT UNSIGNED NULL,
  raised_by_user_id       INT UNSIGNED NULL,
  assigned_to_employee_id INT UNSIGNED NOT NULL,
  title                   VARCHAR(200) NOT NULL,
  details                 TEXT         NULL,
  priority                ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  due_date                DATE         NULL,
  status                  ENUM('open','in_progress','done','cancelled') NOT NULL DEFAULT 'open',
  resolution_note         VARCHAR(1000) NULL,
  completed_at            DATETIME     NULL,
  created_at              DATETIME     NOT NULL,
  updated_at              DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_management_actions_assignee (assigned_to_employee_id, status),
  KEY idx_management_actions_report (report_id),
  CONSTRAINT fk_ma_report FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE SET NULL,
  CONSTRAINT fk_ma_assignee FOREIGN KEY (assigned_to_employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ma_raised_by FOREIGN KEY (raised_by_employee_id) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reminders the scheduler has already sent (so running it again sends nothing twice).
CREATE TABLE report_notices (
  notice_key VARCHAR(160) NOT NULL PRIMARY KEY,
  created_at DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- Settings (Settings page)
INSERT INTO system_settings (`key`, `value`, type, label, description) VALUES
 ('report_start_date',        '2026-11-02', 'string', 'Management reports: start date', 'Reports are expected (and can become overdue) from this date'),
 ('report_daily_days',        '1,2,3,4,5', 'string', 'Management reports: daily report days', 'ISO weekdays, 1 = Monday ... 7 = Sunday, comma-separated'),
 ('report_daily_due_time',    '18:00', 'string', 'Management reports: daily deadline (HH:MM)', 'Daily reports are due by this time on the day'),
 ('report_weekly_due_days',   '1',    'int',    'Management reports: weekly deadline (days after Sunday)', 'Due at the daily deadline time on that day'),
 ('report_monthly_due_days',  '3',    'int',    'Management reports: monthly deadline (days after month end)', 'Due at the daily deadline time on that day'),
 ('report_reminder_hours',    '2',    'int',    'Management reports: reminder before deadline (hours)', NULL),
 ('report_escalate_after_days','2',   'int',    'Management reports: escalate unreviewed reports after (days)', 'A report not approved or returned within this many days goes to the next manager up'),
 ('report_email_enabled',     '1',    'bool',   'Management reports: send emails', 'Master switch; in-app notifications are always sent'),
 ('report_email_submissions', '1',    'bool',   'Report emails: new submissions and reports awaiting approval', NULL),
 ('report_email_decisions',   '1',    'bool',   'Report emails: approved or returned for correction', NULL),
 ('report_email_deadlines',   '0',    'bool',   'Report emails: deadline reminders', NULL),
 ('report_email_overdue',     '1',    'bool',   'Report emails: overdue reports', NULL),
 ('report_email_escalations', '1',    'bool',   'Report emails: escalated issues', NULL),
 ('report_email_actions',     '1',    'bool',   'Report emails: management actions', NULL);
