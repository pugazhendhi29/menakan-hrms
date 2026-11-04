-- =====================================================================
-- Menakan Transport Ltd - HRMS v1 schema
-- Runs on MariaDB 10.4+ and MySQL 8.0.16+. InnoDB, utf8mb4.
-- All DATETIME columns hold UTC. Working time is stored as whole minutes.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS report_notices, management_actions, report_attachments, report_consolidations, report_reviews, report_metrics, report_versions, reports,
  report_template_fields, report_templates,
  audit_logs, notifications, system_settings, news_thread_messages, news_threads, employee_import_rows, employee_import_batches, news_sender_checks, news_deliveries, news_posts, news_templates, id_cards, p60_documents, payslips,
  leave_ledger, leave_requests, leave_entitlements, leave_types, public_holidays,
  hours_exceptions, shift_import_rows, shift_import_batches, shifts, locations, shift_types,
  attendance_import_rows, attendance_corrections, attendance_records, attendance_import_batches,
  employee_documents, document_requirements, document_categories, employee_work_experience, employee_education,
  user_sessions, login_attempts, password_tokens, users,
  employees, employee_statuses, employee_code_seq, job_titles, departments, work_patterns;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------- People
CREATE TABLE departments (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(80)  NOT NULL,
  code          VARCHAR(12)  NOT NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_departments_name (name),
  UNIQUE KEY uq_departments_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE job_titles (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title         VARCHAR(100) NOT NULL,
  department_id INT UNSIGNED NULL,
  is_driver     TINYINT(1)   NOT NULL DEFAULT 0,
  documents_optional TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = no mandatory documents; not shown in dashboard document alerts',
  report_role   VARCHAR(40)  NULL COMMENT 'Approved reporting role (ReportCatalog::ROLES); NULL = not an approved title',
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_job_titles_title (title),
  UNIQUE KEY uq_job_titles_report_role (report_role),
  CONSTRAINT fk_job_titles_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE work_patterns (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(80)  NOT NULL,
  mon TINYINT(1) NOT NULL DEFAULT 1, tue TINYINT(1) NOT NULL DEFAULT 1, wed TINYINT(1) NOT NULL DEFAULT 1,
  thu TINYINT(1) NOT NULL DEFAULT 1, fri TINYINT(1) NOT NULL DEFAULT 1, sat TINYINT(1) NOT NULL DEFAULT 0,
  sun TINYINT(1) NOT NULL DEFAULT 0,
  hours_per_day DECIMAL(4,2) NOT NULL DEFAULT 7.50,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_work_patterns_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Single-row sequence, locked with SELECT ... FOR UPDATE so employee codes are never reused.
CREATE TABLE employee_code_seq (
  id         TINYINT UNSIGNED PRIMARY KEY,
  prefix     VARCHAR(4)   NOT NULL DEFAULT 'A',
  next_value INT UNSIGNED NOT NULL DEFAULT 1,
  pad_length TINYINT UNSIGNED NOT NULL DEFAULT 3 COMMENT 'Digits after the prefix: A001'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE employee_statuses (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code              VARCHAR(30)  NOT NULL COMMENT 'Stored on employees.status',
  name              VARCHAR(60)  NOT NULL,
  colour            CHAR(7)      NOT NULL DEFAULT '#64748b',
  counts_as_working TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1 = counted as active headcount on the dashboard and reports',
  is_system         TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Built in (Active, Probation, Left): the code cannot change and it cannot be switched off',
  sort_order        SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  is_active         TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_employee_status_code (code),
  UNIQUE KEY uq_employee_status_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO employee_statuses (code, name, colour, counts_as_working, is_system, sort_order) VALUES
 ('active',       'Active',       '#15803d', 1, 1, 10),
 ('probation',    'Probation',    '#1d4ed8', 1, 1, 20),
 ('on_leave',     'On leave',     '#b45309', 1, 0, 30),
 ('career_break', 'Career Break', '#b45309', 0, 0, 40),
 ('suspended',    'Suspended',    '#dc2626', 0, 0, 50),
 ('left',         'Left',         '#64748b', 0, 1, 90);

CREATE TABLE employees (
  id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_code           VARCHAR(12)  NOT NULL,
  title                   VARCHAR(10)  NULL,
  first_name              VARCHAR(60)  NOT NULL,
  last_name               VARCHAR(60)  NOT NULL,
  preferred_name          VARCHAR(60)  NULL,
  email                   VARCHAR(190) NULL,
  phone                   VARCHAR(30)  NULL,
  personal_mobile         VARCHAR(30)  NULL,
  personal_email          VARCHAR(190) NULL,
  date_of_birth           DATE         NULL,
  gender                  VARCHAR(30)  NULL COMMENT 'Optional, admin-only',
  marital_status          VARCHAR(30)  NULL,
  nationality             VARCHAR(60)  NULL,
  visa_type               VARCHAR(60)  NULL,
  visa_start_date         DATE         NULL,
  visa_end_date           DATE         NULL,
  rtw_check_status        VARCHAR(30)  NULL COMMENT 'Right to work audit check result',
  rtw_check_date          DATE         NULL,
  rtw_followup_date       DATE         NULL COMMENT 'Next right to work check due',
  ni_number               VARCHAR(13)  NULL COMMENT 'Admin-only',
  passport_number         VARCHAR(20)  NULL COMMENT 'Admin-only',
  address_line1           VARCHAR(120) NULL,
  address_line2           VARCHAR(120) NULL,
  city                    VARCHAR(80)  NULL,
  postcode                VARCHAR(10)  NULL,
  permanent_same_as_present TINYINT(1) NOT NULL DEFAULT 1,
  permanent_address       VARCHAR(400) NULL,
  emergency_contact_name  VARCHAR(120) NULL,
  emergency_contact_phone VARCHAR(30)  NULL,
  emergency_contact_relationship VARCHAR(40) NULL,
  department_id           INT UNSIGNED NULL,
  job_title_id            INT UNSIGNED NULL,
  line_manager_id         INT UNSIGNED NULL,
  reporting_manager_name  VARCHAR(120) NULL COMMENT 'Used when the manager is not an employee in the system',
  work_pattern_id         INT UNSIGNED NULL,
  default_shift_type_id   INT UNSIGNED NULL COMMENT 'Usual shift (FK added after shift_types)',
  location_id             INT UNSIGNED NULL COMMENT 'Usual work location (FK added after locations)',
  employment_type         ENUM('full_time','part_time','casual','fixed_term','agency') NOT NULL DEFAULT 'full_time',
  source_of_hire          VARCHAR(40)  NULL,
  contract_hours          DECIMAL(5,2) NULL COMMENT 'Contracted weekly hours; also the contract hours limit',
  weekly_hours_limit      DECIMAL(5,2) NULL COMMENT 'Employee-specific override of the weekly limit',
  start_date              DATE         NOT NULL,
  probation_end_date      DATE         NULL,
  end_date                DATE         NULL,
  leaving_reason          VARCHAR(120) NULL,
  status                  VARCHAR(30)  NOT NULL DEFAULT 'active' COMMENT 'employee_statuses.code (Settings > Employee statuses)',
  salary                  DECIMAL(10,2) NULL COMMENT 'Admin-only',
  pay_frequency           ENUM('weekly','fortnightly','four_weekly','monthly') NULL,
  payroll_reference       VARCHAR(40)  NULL COMMENT 'Admin-only',
  notes                   TEXT         NULL,
  photo_key               VARCHAR(255) NULL,
  created_by              INT UNSIGNED NULL,
  created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at              DATETIME     NULL,
  UNIQUE KEY uq_employees_code (employee_code),
  UNIQUE KEY uq_employees_email (email),
  UNIQUE KEY uq_employees_ni (ni_number),
  KEY idx_employees_visa_end (visa_end_date),
  KEY idx_employees_rtw_followup (rtw_followup_date),
  KEY idx_employees_status (status),
  KEY idx_employees_department (department_id),
  KEY idx_employees_name (last_name, first_name),
  CONSTRAINT fk_employees_department  FOREIGN KEY (department_id)   REFERENCES departments(id)   ON DELETE SET NULL,
  CONSTRAINT fk_employees_job_title   FOREIGN KEY (job_title_id)    REFERENCES job_titles(id)    ON DELETE SET NULL,
  CONSTRAINT fk_employees_manager     FOREIGN KEY (line_manager_id) REFERENCES employees(id)     ON DELETE SET NULL,
  CONSTRAINT fk_employees_pattern     FOREIGN KEY (work_pattern_id) REFERENCES work_patterns(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE employee_work_experience (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id  INT UNSIGNED NOT NULL,
  company_name VARCHAR(120) NOT NULL,
  job_title    VARCHAR(100) NOT NULL,
  from_date    DATE         NOT NULL,
  to_date      DATE         NULL COMMENT 'NULL while the person still works there',
  is_current   TINYINT(1)   NOT NULL DEFAULT 0,
  location     VARCHAR(120) NULL,
  reference_name  VARCHAR(120) NULL,
  reference_email VARCHAR(190) NULL,
  reference_phone VARCHAR(30)  NULL COMMENT 'International format, e.g. +44 7700900123',
  description  TEXT         NULL,
  is_relevant  TINYINT(1)   NOT NULL DEFAULT 1,
  created_by   INT UNSIGNED NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_work_experience_emp (employee_id, from_date),
  CONSTRAINT fk_work_experience_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT chk_work_experience_dates CHECK (to_date >= from_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE employee_education (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id     INT UNSIGNED NOT NULL,
  institute_name  VARCHAR(150) NOT NULL,
  education_level VARCHAR(40)  NULL,
  qualification   VARCHAR(120) NULL COMMENT 'Legacy free-text qualification',
  specialization  VARCHAR(120) NULL,
  completion_date DATE         NULL,
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_education_emp (employee_id),
  CONSTRAINT fk_education_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- Accounts
CREATE TABLE users (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id          INT UNSIGNED NULL,
  username             VARCHAR(60)  NOT NULL,
  email                VARCHAR(190) NULL,
  password_hash        VARCHAR(255) NULL COMMENT 'NULL until activated',
  role                 ENUM('admin','employee') NOT NULL DEFAULT 'employee',
  is_active            TINYINT(1)   NOT NULL DEFAULT 1,
  must_change_password TINYINT(1)   NOT NULL DEFAULT 0,
  can_backdate_threads TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = may date news thread emails before today',
  can_edit_employees   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = may edit employee details',
  report_email_opt_out TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = no emails about management reports',
  can_backdate_reports TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = may create backdated management reports',
  last_login_at        DATETIME     NULL,
  created_by           INT UNSIGNED NULL,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  -- Only one ACTIVE account per employee (NULLs are not compared by UNIQUE).
  active_employee_id   INT UNSIGNED AS (IF(is_active = 1, employee_id, NULL)) STORED,
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_active_employee (active_employee_id),
  KEY idx_users_employee (employee_id),
  CONSTRAINT fk_users_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
  CONSTRAINT chk_users_employee_role CHECK (role = 'admin' OR employee_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_tokens (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  token_hash  CHAR(64)     NOT NULL COMMENT 'SHA-256 of the token; the token itself is never stored',
  purpose     ENUM('reset','activation') NOT NULL,
  expires_at  DATETIME     NOT NULL,
  used_at     DATETIME     NULL,
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_password_tokens_hash (token_hash),
  CONSTRAINT fk_password_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username     VARCHAR(190) NOT NULL,
  ip           VARCHAR(45)  NOT NULL,
  success      TINYINT(1)   NOT NULL,
  attempted_at DATETIME     NOT NULL,
  KEY idx_login_attempts_lookup (username, ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_sessions (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED NOT NULL,
  session_id_hash CHAR(64)     NOT NULL,
  ip              VARCHAR(45)  NULL,
  user_agent      VARCHAR(255) NULL,
  created_at      DATETIME     NOT NULL,
  last_seen_at    DATETIME     NOT NULL,
  revoked_at      DATETIME     NULL,
  UNIQUE KEY uq_user_sessions_hash (session_id_hash),
  KEY idx_user_sessions_user (user_id, revoked_at),
  CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- Documents
CREATE TABLE document_categories (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(80)  NOT NULL,
  code         VARCHAR(20)  NOT NULL,
  has_expiry   TINYINT(1)   NOT NULL DEFAULT 0,
  is_sensitive TINYINT(1)   NOT NULL DEFAULT 0,
  visible_to_employee TINYINT(1) NOT NULL DEFAULT 1,
  description  VARCHAR(255) NULL,
  sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_document_categories_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A requirement applies to everyone (both NULL), a department, or a job title.
CREATE TABLE document_requirements (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id   INT UNSIGNED NOT NULL,
  department_id INT UNSIGNED NULL,
  job_title_id  INT UNSIGNED NULL,
  is_mandatory  TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_document_requirements (category_id, department_id, job_title_id),
  CONSTRAINT fk_docreq_category   FOREIGN KEY (category_id)   REFERENCES document_categories(id) ON DELETE CASCADE,
  CONSTRAINT fk_docreq_department FOREIGN KEY (department_id) REFERENCES departments(id)         ON DELETE CASCADE,
  CONSTRAINT fk_docreq_job_title  FOREIGN KEY (job_title_id)  REFERENCES job_titles(id)          ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE employee_documents (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id       INT UNSIGNED NOT NULL,
  category_id       INT UNSIGNED NOT NULL,
  title             VARCHAR(150) NOT NULL,
  document_number   VARCHAR(60)  NULL,
  issue_date        DATE         NULL,
  expiry_date       DATE         NULL,
  original_filename VARCHAR(255) NOT NULL,
  storage_key       VARCHAR(255) NOT NULL COMMENT 'Random path under storage/, never the original name',
  mime_type         VARCHAR(100) NOT NULL,
  size_bytes        INT UNSIGNED NOT NULL,
  sha256            CHAR(64)     NOT NULL,
  verification      ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  verified_by       INT UNSIGNED NULL,
  verified_at       DATETIME     NULL,
  is_current        TINYINT(1)   NOT NULL DEFAULT 1,
  replaced_by_id    INT UNSIGNED NULL,
  archived_at       DATETIME     NULL,
  notes             VARCHAR(255) NULL,
  uploaded_by       INT UNSIGNED NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_employee_documents_key (storage_key),
  KEY idx_employee_documents_emp (employee_id, category_id, is_current),
  KEY idx_employee_documents_expiry (expiry_date),
  CONSTRAINT fk_empdoc_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
  CONSTRAINT fk_empdoc_category FOREIGN KEY (category_id) REFERENCES document_categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- Shifts (before attendance, which references shifts)
CREATE TABLE shift_types (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name                  VARCHAR(60) NOT NULL,
  code                  VARCHAR(12) NOT NULL,
  default_start         TIME        NOT NULL,
  default_end           TIME        NOT NULL,
  default_break_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  is_night              TINYINT(1)  NOT NULL DEFAULT 0,
  colour                VARCHAR(7)  NOT NULL DEFAULT '#1f5fa8',
  is_active             TINYINT(1)  NOT NULL DEFAULT 1,
  UNIQUE KEY uq_shift_types_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE locations (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name      VARCHAR(80)  NOT NULL,
  address   VARCHAR(255) NULL,
  is_active TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_locations_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE employees ADD CONSTRAINT fk_employees_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL;
ALTER TABLE employees ADD CONSTRAINT fk_employees_default_shift FOREIGN KEY (default_shift_type_id) REFERENCES shift_types(id) ON DELETE SET NULL;

CREATE TABLE shift_import_batches (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  original_filename VARCHAR(255) NOT NULL,
  storage_key       VARCHAR(255) NOT NULL,
  status            ENUM('validated','imported','cancelled','failed') NOT NULL DEFAULT 'validated',
  total_rows        INT UNSIGNED NOT NULL DEFAULT 0,
  valid_rows        INT UNSIGNED NOT NULL DEFAULT 0,
  duplicate_rows    INT UNSIGNED NOT NULL DEFAULT 0,
  error_rows        INT UNSIGNED NOT NULL DEFAULT 0,
  imported_rows     INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_by       INT UNSIGNED NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  confirmed_at      DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Employee import (Employee ID is the key)
CREATE TABLE employee_import_batches (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  original_filename VARCHAR(255) NOT NULL,
  storage_key       VARCHAR(255) NOT NULL,
  status            ENUM('validated','importing','imported','cancelled') NOT NULL DEFAULT 'validated',
  total_rows        INT UNSIGNED NOT NULL DEFAULT 0,
  create_rows       INT UNSIGNED NOT NULL DEFAULT 0,
  update_rows       INT UNSIGNED NOT NULL DEFAULT 0,
  unchanged_rows    INT UNSIGNED NOT NULL DEFAULT 0,
  error_rows        INT UNSIGNED NOT NULL DEFAULT 0,
  imported_rows     INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_by       INT UNSIGNED NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  confirmed_at      DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE employee_import_rows (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id       INT UNSIGNED NOT NULL,
  row_no         INT UNSIGNED NOT NULL,
  employee_code  VARCHAR(20)  NOT NULL DEFAULT '' COMMENT 'Employee ID from the file (empty = next ID)',
  employee_id    INT UNSIGNED NULL,
  employee_name  VARCHAR(130) NULL,
  action         ENUM('create','update') NOT NULL,
  result         ENUM('ready','unchanged','error','imported') NOT NULL,
  changes        TEXT         NULL,
  messages       TEXT         NULL,
  input_json     MEDIUMTEXT   NULL COMMENT 'Validated employee-form input applied on confirm',
  raw_json       MEDIUMTEXT   NULL,
  KEY idx_employee_import_rows (batch_id, result),
  CONSTRAINT fk_employee_import_batch FOREIGN KEY (batch_id) REFERENCES employee_import_batches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE shifts (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id     INT UNSIGNED NOT NULL,
  shift_type_id   INT UNSIGNED NULL,
  location_id     INT UNSIGNED NULL,
  start_at        DATETIME     NOT NULL COMMENT 'UTC',
  end_at          DATETIME     NOT NULL COMMENT 'UTC; may be the next day for night shifts',
  break_minutes   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  net_minutes     INT          NOT NULL,
  week_start      DATE         NOT NULL COMMENT 'UK Monday of the start date',
  status          ENUM('draft','published','cancelled') NOT NULL DEFAULT 'draft',
  notes           VARCHAR(255) NULL,
  import_batch_id INT UNSIGNED NULL,
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  cancelled_at    DATETIME     NULL,
  KEY idx_shifts_emp_start (employee_id, start_at),
  KEY idx_shifts_week (employee_id, week_start, status),
  KEY idx_shifts_start (start_at),
  CONSTRAINT fk_shifts_employee FOREIGN KEY (employee_id)     REFERENCES employees(id)            ON DELETE RESTRICT,
  CONSTRAINT fk_shifts_type     FOREIGN KEY (shift_type_id)   REFERENCES shift_types(id)          ON DELETE SET NULL,
  CONSTRAINT fk_shifts_location FOREIGN KEY (location_id)     REFERENCES locations(id)            ON DELETE SET NULL,
  CONSTRAINT fk_shifts_batch    FOREIGN KEY (import_batch_id) REFERENCES shift_import_batches(id) ON DELETE SET NULL,
  CONSTRAINT chk_shifts_order CHECK (end_at > start_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE shift_import_rows (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id      INT UNSIGNED NOT NULL,
  row_no    INT UNSIGNED NOT NULL,
  employee_code VARCHAR(20)  NULL,
  employee_id   INT UNSIGNED NULL,
  start_at      DATETIME     NULL,
  end_at        DATETIME     NULL,
  break_minutes SMALLINT UNSIGNED NULL,
  net_minutes   INT          NULL,
  shift_type_id INT UNSIGNED NULL,
  location_id   INT UNSIGNED NULL,
  status        VARCHAR(12)  NULL,
  notes         VARCHAR(255) NULL,
  raw_json      TEXT         NOT NULL,
  result        ENUM('ready','warning','duplicate','error','imported','skipped') NOT NULL,
  messages      TEXT         NULL,
  shift_id      INT UNSIGNED NULL,
  KEY idx_shift_import_rows_batch (batch_id, row_no),
  CONSTRAINT fk_shift_import_rows_batch FOREIGN KEY (batch_id) REFERENCES shift_import_batches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE hours_exceptions (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id       INT UNSIGNED NOT NULL,
  week_start        DATE         NOT NULL,
  limit_minutes     INT          NOT NULL,
  approved_minutes  INT          NOT NULL COMMENT 'Scheduled minutes the approval covers',
  reason            VARCHAR(255) NOT NULL,
  approved_by       INT UNSIGNED NOT NULL,
  approved_at       DATETIME     NOT NULL,
  UNIQUE KEY uq_hours_exceptions (employee_id, week_start),
  CONSTRAINT fk_hours_exceptions_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- Attendance
CREATE TABLE attendance_import_batches (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  original_filename VARCHAR(255) NOT NULL,
  storage_key       VARCHAR(255) NOT NULL,
  status            ENUM('validated','imported','cancelled','failed') NOT NULL DEFAULT 'validated',
  duplicate_mode    ENUM('skip','update') NOT NULL DEFAULT 'skip',
  total_rows        INT UNSIGNED NOT NULL DEFAULT 0,
  valid_rows        INT UNSIGNED NOT NULL DEFAULT 0,
  duplicate_rows    INT UNSIGNED NOT NULL DEFAULT 0,
  error_rows        INT UNSIGNED NOT NULL DEFAULT 0,
  imported_rows     INT UNSIGNED NOT NULL DEFAULT 0,
  updated_rows      INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_by       INT UNSIGNED NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  confirmed_at      DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance_records (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id     INT UNSIGNED NOT NULL,
  work_date       DATE         NOT NULL COMMENT 'UK date of check-in',
  check_in_at     DATETIME     NOT NULL COMMENT 'UTC',
  check_out_at    DATETIME     NULL COMMENT 'UTC',
  break_minutes   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  net_minutes     INT          NULL,
  status          ENUM('present','late','incomplete','off') NOT NULL DEFAULT 'present' COMMENT 'off = no shift on the rota that day',
  source          ENUM('web','import','manual','rota') NOT NULL COMMENT 'rota = recorded from the published shift (AttendanceService::fillFromRota)',
  shift_id        INT UNSIGNED NULL,
  import_batch_id INT UNSIGNED NULL,
  is_approved     TINYINT(1)   NOT NULL DEFAULT 0,
  approved_by     INT UNSIGNED NULL,
  approved_at     DATETIME     NULL,
  notes           VARCHAR(255) NULL,
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  -- At most one open (not checked-out, not flagged incomplete) session per employee.
  open_employee_id INT UNSIGNED AS (IF(check_out_at IS NULL AND status <> 'incomplete', employee_id, NULL)) STORED,
  UNIQUE KEY uq_attendance_unique (employee_id, work_date, check_in_at),
  UNIQUE KEY uq_attendance_open (open_employee_id),
  KEY idx_attendance_date (work_date),
  CONSTRAINT fk_attendance_employee FOREIGN KEY (employee_id)     REFERENCES employees(id)                 ON DELETE RESTRICT,
  CONSTRAINT fk_attendance_shift    FOREIGN KEY (shift_id)        REFERENCES shifts(id)                    ON DELETE SET NULL,
  CONSTRAINT fk_attendance_batch    FOREIGN KEY (import_batch_id) REFERENCES attendance_import_batches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance_corrections (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  attendance_id INT UNSIGNED NOT NULL,
  field         VARCHAR(40)  NOT NULL,
  old_value     VARCHAR(255) NULL,
  new_value     VARCHAR(255) NULL,
  reason        VARCHAR(255) NOT NULL,
  changed_by    INT UNSIGNED NOT NULL,
  changed_at    DATETIME     NOT NULL,
  KEY idx_attendance_corrections (attendance_id),
  CONSTRAINT fk_attendance_corrections FOREIGN KEY (attendance_id) REFERENCES attendance_records(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance_import_rows (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id           INT UNSIGNED NOT NULL,
  row_no         INT UNSIGNED NOT NULL,
  employee_code      VARCHAR(20)  NULL,
  employee_name      VARCHAR(120) NULL,
  employee_id        INT UNSIGNED NULL,
  work_date          DATE         NULL,
  check_in_at        DATETIME     NULL,
  check_out_at       DATETIME     NULL,
  break_minutes      SMALLINT UNSIGNED NULL,
  net_minutes        INT          NULL,
  notes              VARCHAR(255) NULL,
  raw_json           TEXT         NOT NULL,
  result             ENUM('ready','duplicate','duplicate_approved','error','imported','updated','skipped') NOT NULL,
  messages           TEXT         NULL,
  existing_record_id INT UNSIGNED NULL,
  KEY idx_attendance_import_rows_batch (batch_id, row_no),
  CONSTRAINT fk_attendance_import_rows_batch FOREIGN KEY (batch_id) REFERENCES attendance_import_batches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- Leave
CREATE TABLE leave_types (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name              VARCHAR(60) NOT NULL,
  code              VARCHAR(12) NOT NULL,
  is_paid           TINYINT(1)  NOT NULL DEFAULT 1,
  deducts_balance   TINYINT(1)  NOT NULL DEFAULT 0,
  employee_can_request TINYINT(1) NOT NULL DEFAULT 1,
  colour            VARCHAR(7)  NOT NULL DEFAULT '#1f8a4c',
  sort_order        SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  is_active         TINYINT(1)  NOT NULL DEFAULT 1,
  UNIQUE KEY uq_leave_types_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE public_holidays (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  holiday_date DATE        NOT NULL,
  name         VARCHAR(80) NOT NULL,
  region       VARCHAR(30) NOT NULL DEFAULT 'england-and-wales',
  UNIQUE KEY uq_public_holidays (holiday_date, region)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE leave_entitlements (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id      INT UNSIGNED NOT NULL,
  leave_type_id    INT UNSIGNED NOT NULL,
  leave_year       SMALLINT UNSIGNED NOT NULL COMMENT 'Year the leave year starts in',
  entitlement_days DECIMAL(5,2) NOT NULL,
  is_pro_rated     TINYINT(1)   NOT NULL DEFAULT 0,
  notes            VARCHAR(255) NULL,
  created_by       INT UNSIGNED NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_leave_entitlements (employee_id, leave_type_id, leave_year),
  CONSTRAINT fk_leave_entitlements_employee FOREIGN KEY (employee_id)   REFERENCES employees(id)   ON DELETE CASCADE,
  CONSTRAINT fk_leave_entitlements_type     FOREIGN KEY (leave_type_id) REFERENCES leave_types(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE leave_requests (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id    INT UNSIGNED NOT NULL,
  leave_type_id  INT UNSIGNED NOT NULL,
  start_date     DATE         NOT NULL,
  end_date       DATE         NOT NULL,
  start_half     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = starts at midday (afternoon only)',
  end_half       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = ends at midday (morning only)',
  days           DECIMAL(5,2) NOT NULL,
  reason         VARCHAR(500) NULL,
  requested_on   DATE         NULL COMMENT 'Date the employee asked for the leave',
  approved_on    DATE         NULL COMMENT 'Date the leave was approved',
  status         ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  requested_by   INT UNSIGNED NULL,
  decided_by     INT UNSIGNED NULL,
  decided_at     DATETIME     NULL,
  decision_note  VARCHAR(500) NULL,
  shift_clashes  INT UNSIGNED NOT NULL DEFAULT 0,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_leave_requests_emp (employee_id, start_date, end_date),
  KEY idx_leave_requests_status (status),
  CONSTRAINT fk_leave_requests_employee FOREIGN KEY (employee_id)   REFERENCES employees(id)   ON DELETE RESTRICT,
  CONSTRAINT fk_leave_requests_type     FOREIGN KEY (leave_type_id) REFERENCES leave_types(id) ON DELETE RESTRICT,
  CONSTRAINT chk_leave_requests_dates CHECK (end_date >= start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Balance = SUM(days). Accruals/carry-over are positive, deductions negative.
CREATE TABLE leave_ledger (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id      INT UNSIGNED NOT NULL,
  leave_type_id    INT UNSIGNED NOT NULL,
  leave_year       SMALLINT UNSIGNED NOT NULL,
  entry_type       ENUM('accrual','deduction','adjustment','carry_over','reversal','bank_holiday') NOT NULL,
  days             DECIMAL(6,2) NOT NULL,
  leave_request_id INT UNSIGNED NULL,
  note             VARCHAR(255) NULL,
  created_by       INT UNSIGNED NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_leave_ledger_balance (employee_id, leave_type_id, leave_year),
  CONSTRAINT fk_leave_ledger_employee FOREIGN KEY (employee_id)      REFERENCES employees(id)      ON DELETE CASCADE,
  CONSTRAINT fk_leave_ledger_type     FOREIGN KEY (leave_type_id)    REFERENCES leave_types(id)    ON DELETE RESTRICT,
  CONSTRAINT fk_leave_ledger_request  FOREIGN KEY (leave_request_id) REFERENCES leave_requests(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- Payroll
CREATE TABLE payslips (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id       INT UNSIGNED NOT NULL,
  period_year       SMALLINT UNSIGNED NOT NULL,
  period_month      TINYINT UNSIGNED NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  storage_key       VARCHAR(255) NOT NULL,
  sha256            CHAR(64)     NOT NULL,
  size_bytes        INT UNSIGNED NOT NULL,
  status            ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  is_current        TINYINT(1)   NULL DEFAULT 1 COMMENT '1 = current version, NULL = archived (so UNIQUE allows many archived)',
  uploaded_by       INT UNSIGNED NULL,
  published_by      INT UNSIGNED NULL,
  published_at      DATETIME     NULL,
  archived_at       DATETIME     NULL,
  emailed_to        VARCHAR(190) NULL,
  emailed_at        DATETIME     NULL,
  email_error       VARCHAR(255) NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_payslips_current (employee_id, period_year, period_month, is_current),
  UNIQUE KEY uq_payslips_key (storage_key),
  KEY idx_payslips_period (period_year, period_month, status),
  CONSTRAINT fk_payslips_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
  CONSTRAINT chk_payslips_month CHECK (period_month BETWEEN 1 AND 12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE p60_documents (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id       INT UNSIGNED NOT NULL,
  employee_code     VARCHAR(12)  NOT NULL COMMENT 'Employee ID at upload time',
  employee_name     VARCHAR(130) NOT NULL COMMENT 'Employee name at upload time',
  tax_year_start    SMALLINT UNSIGNED NOT NULL COMMENT '2025 = tax year 6 Apr 2025 - 5 Apr 2026',
  original_filename VARCHAR(255) NOT NULL,
  storage_key       VARCHAR(255) NOT NULL COMMENT 'Private path under storage/, never the original name',
  sha256            CHAR(64)     NOT NULL,
  size_bytes        INT UNSIGNED NOT NULL,
  status            ENUM('current','replaced','deleted') NOT NULL DEFAULT 'current',
  is_current        TINYINT(1)   NULL DEFAULT 1 COMMENT '1 = current, NULL = replaced/deleted (UNIQUE allows many)',
  uploaded_by       INT UNSIGNED NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Upload date (UTC)',
  replaced_by_id    INT UNSIGNED NULL,
  replaced_at       DATETIME     NULL,
  deleted_at        DATETIME     NULL,
  deleted_by        INT UNSIGNED NULL,
  deleted_reason    VARCHAR(255) NULL,
  emailed_to        VARCHAR(190) NULL,
  emailed_at        DATETIME     NULL,
  email_error       VARCHAR(255) NULL,
  UNIQUE KEY uq_p60_current (employee_id, tax_year_start, is_current),
  UNIQUE KEY uq_p60_key (storage_key),
  KEY idx_p60_year (tax_year_start, status),
  CONSTRAINT fk_p60_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Employee ID cards: one current card per employee; regenerated or revoked cards are kept as history.
CREATE TABLE id_cards (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id   INT UNSIGNED NOT NULL,
  card_number   VARCHAR(20)  NOT NULL COMMENT 'Employee ID + version, e.g. A001-02',
  version       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  issue_date    DATE         NOT NULL,
  expiry_date   DATE         NOT NULL,
  status        ENUM('active','suspended','replaced','revoked') NOT NULL DEFAULT 'active',
  is_current    TINYINT(1)   NULL DEFAULT 1 COMMENT '1 = current, NULL = replaced/revoked (UNIQUE allows many)',
  notes         VARCHAR(255) NULL,
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by    INT UNSIGNED NULL,
  updated_at    DATETIME     NULL,
  ended_at      DATETIME     NULL COMMENT 'When it was replaced or revoked (UTC)',
  ended_reason  VARCHAR(255) NULL,
  UNIQUE KEY uq_id_card_current (employee_id, is_current),
  UNIQUE KEY uq_id_card_number (card_number),
  CONSTRAINT fk_id_card_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- News: company announcements emailed from hr@menakantransport.com (templates, drafts, scheduling, delivery log, sender checks).
CREATE TABLE news_templates (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  subject     VARCHAR(200) NOT NULL,
  body        TEXT         NOT NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by  INT UNSIGNED NULL,
  updated_at  DATETIME     NULL,
  UNIQUE KEY uq_news_template_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE news_posts (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title            VARCHAR(200) NOT NULL COMMENT 'Shown in the HRMS news list',
  subject          VARCHAR(200) NOT NULL COMMENT 'Email subject',
  body             TEXT         NOT NULL,
  audience         ENUM('all','employees','departments','teams') NOT NULL DEFAULT 'all',
  audience_ids     VARCHAR(2000) NULL COMMENT 'Comma-separated employee, department or manager ids',
  template_id      INT UNSIGNED NULL,
  status           ENUM('draft','scheduled','sending','sent','partially_sent','failed','cancelled') NOT NULL DEFAULT 'draft',
  scheduled_at     DATETIME     NULL COMMENT 'UTC',
  sent_at          DATETIME     NULL COMMENT 'UTC, when the last send finished',
  recipients_total INT UNSIGNED NOT NULL DEFAULT 0,
  sent_count       INT UNSIGNED NOT NULL DEFAULT 0,
  failed_count     INT UNSIGNED NOT NULL DEFAULT 0,
  test_mode        TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = written to the mail log, not delivered',
  last_error       VARCHAR(500) NULL,
  created_by       INT UNSIGNED NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by       INT UNSIGNED NULL,
  updated_at       DATETIME     NULL,
  KEY idx_news_status (status, scheduled_at),
  CONSTRAINT fk_news_template FOREIGN KEY (template_id) REFERENCES news_templates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE news_deliveries (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  news_id       INT UNSIGNED NOT NULL,
  employee_id   INT UNSIGNED NOT NULL,
  email         VARCHAR(190) NULL COMMENT 'Address used; visible to admins only, never to other recipients',
  status        ENUM('pending','sent','logged','failed','skipped') NOT NULL DEFAULT 'pending',
  error         VARCHAR(500) NULL,
  attempts      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  attempted_at  DATETIME     NULL,
  sent_at       DATETIME     NULL,
  UNIQUE KEY uq_news_delivery (news_id, employee_id),
  KEY idx_news_delivery_employee (employee_id, status),
  CONSTRAINT fk_news_delivery_post FOREIGN KEY (news_id) REFERENCES news_posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_news_delivery_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE news_sender_checks (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sender      VARCHAR(190) NOT NULL,
  ok          TINYINT(1)   NOT NULL,
  message     VARCHAR(500) NOT NULL,
  checked_by  INT UNSIGNED NULL,
  checked_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE news_threads (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title            VARCHAR(200) NOT NULL,
  channel          ENUM('email','chat','meeting','phone','letter','other','portal') NOT NULL DEFAULT 'email' COMMENT 'Where the conversation took place; portal = sent in the HRMS',
  summary          VARCHAR(1000) NULL,
  audience         ENUM('receivers','all','employees','departments','teams') NOT NULL DEFAULT 'receivers' COMMENT 'Extra readers; the receivers of every message can always read it',
  audience_ids     VARCHAR(2000) NULL COMMENT 'Comma-separated employee, department or manager ids',
  status           ENUM('draft','published','withdrawn') NOT NULL DEFAULT 'draft',
  published_at     DATETIME     NULL COMMENT 'UTC, real time of publishing',
  published_by     INT UNSIGNED NULL,
  withdrawn_at     DATETIME     NULL,
  withdrawn_by     INT UNSIGNED NULL,
  withdrawn_reason VARCHAR(500) NULL,
  created_by       INT UNSIGNED NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by       INT UNSIGNED NULL,
  updated_at       DATETIME     NULL,
  KEY idx_news_threads_status (status, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE news_thread_messages (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  thread_id        INT UNSIGNED NOT NULL,
  occurred_at      DATETIME     NOT NULL COMMENT 'UTC; the date and time of the message',
  custom_time      TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1 = date and time entered by an admin; 0 = stamped when it was sent in the HRMS',
  sender_type      ENUM('department','employee') NOT NULL,
  sender_id        INT UNSIGNED NOT NULL,
  sender_label     VARCHAR(200) NOT NULL COMMENT 'Name at the time of entry, kept if the department or employee is renamed',
  recipient_type   ENUM('all','department','employee','thread') NOT NULL COMMENT 'thread = everyone already in the thread (a reply)',
  recipient_ids    VARCHAR(2000) NULL COMMENT 'Comma-separated department or employee ids; NULL for all',
  recipient_label  VARCHAR(1000) NOT NULL,
  subject          VARCHAR(200) NULL COMMENT 'Shown as the subject line; the thread title when empty',
  body             MEDIUMTEXT   NOT NULL COMMENT 'Plain text, or cleaned HTML from the rich editor (starts with a tag)',
  created_by       INT UNSIGNED NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Real time the message was recorded in the HRMS',
  updated_by       INT UNSIGNED NULL,
  updated_at       DATETIME     NULL,
  KEY idx_news_thread_messages (thread_id, occurred_at),
  CONSTRAINT fk_news_thread_message_thread FOREIGN KEY (thread_id) REFERENCES news_threads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- System
CREATE TABLE notifications (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  type       VARCHAR(40)  NOT NULL,
  title      VARCHAR(150) NOT NULL,
  body       VARCHAR(500) NULL,
  link       VARCHAR(255) NULL,
  read_at    DATETIME     NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_notifications_user (user_id, read_at),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NULL,
  action      VARCHAR(60)  NOT NULL,
  entity_type VARCHAR(40)  NULL,
  entity_id   INT UNSIGNED NULL,
  summary     VARCHAR(255) NOT NULL,
  changes     LONGTEXT     NULL COMMENT 'JSON; never contains passwords or tokens',
  ip          VARCHAR(45)  NULL,
  user_agent  VARCHAR(255) NULL,
  created_at  DATETIME     NOT NULL,
  KEY idx_audit_logs_entity (entity_type, entity_id),
  KEY idx_audit_logs_user (user_id, created_at),
  KEY idx_audit_logs_created (created_at),
  CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE system_settings (
  `key`       VARCHAR(60)  NOT NULL PRIMARY KEY,
  `value`     VARCHAR(255) NOT NULL,
  type        ENUM('int','decimal','string','bool') NOT NULL DEFAULT 'string',
  label       VARCHAR(120) NOT NULL,
  description VARCHAR(255) NULL,
  updated_by  INT UNSIGNED NULL,
  updated_at  DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- Reference data required by the app
INSERT INTO employee_code_seq (id, prefix, next_value, pad_length) VALUES (1, 'A', 1, 3);

INSERT INTO system_settings (`key`, `value`, type, label, description) VALUES
 ('company_name',               'Menakan Transport Ltd', 'string',  'Company name', NULL),
 ('weekly_hours_limit',         '37.5', 'decimal', 'Weekly hours limit (company rule)', 'Used when the employee has no override and no contract hours'),
 ('late_grace_minutes',         '5',    'int',     'Late grace minutes', 'Check-in after shift start + this many minutes is Late'),
 ('incomplete_after_hours',     '16',   'int',     'Flag open sessions as Incomplete after (hours)', NULL),
 ('default_break_minutes',      '30',   'int',     'Default unpaid break (minutes) when no shift is linked', 'Applied to sessions longer than 6 hours'),
 ('document_expiry_warning_days','30',  'int',     'Document expiry warning (days)', NULL),
 ('wtd_average_limit_hours',    '48',   'int',     'Working Time: average weekly limit (hours)', NULL),
 ('wtd_reference_weeks',        '17',   'int',     'Working Time: reference period (weeks)', NULL),
 ('daily_rest_hours',           '11',   'int',     'Working Time: minimum daily rest (hours)', NULL),
 ('leaver_retention_years',     '6',    'int',     'Keep leaver records for (years)', 'Leavers older than this are listed for archiving'),
 ('bank_holiday_region',        'england-and-wales', 'string', 'Bank holiday region', NULL),
 ('id_card_expiry_date',        '2026-12-31', 'string', 'Default ID card expiry date (YYYY-MM-DD)', 'Pre-filled when an admin creates or regenerates an ID card'),
 ('company_website',            'www.menakantransport.com', 'string', 'Company website', 'Shown on the ID card'),
 ('company_address',            'Unit 11, 58 Peregrine Rd, Essex IG6 3SZ', 'string', 'Company address', 'Shown on the ID card'),
 ('email_signature',            'Human Resource\nMenakan Transport Ltd\nUnit 11, 58 Peregrine Road,\nHainault, Essex, UK\nIG6 3SZ\nMenakan Transport Ltd | Human Resources Department', 'string', 'Email signature', 'Added to the end of every email (payslips, password links, reminders)');

-- Starter News templates (admins can edit or add their own)
INSERT INTO news_templates (name, subject, body) VALUES
 ('General announcement', 'Announcement from Menakan Transport', 'Dear {first_name},\n\n[Write the announcement here.]\n\nIf you have any questions, please contact HR.'),
 ('Policy update', 'Policy update: [policy name]', 'Dear {first_name},\n\nWe have updated the [policy name] policy. The main changes are:\n\n- [change 1]\n- [change 2]\n\nThe new policy applies from [date]. Please read it and contact HR with any questions.'),
 ('Site or depot notice', 'Notice: [subject]', 'Dear {first_name},\n\nPlease note: [details, including dates, times and location].\n\nThank you for your co-operation.');

-- ================================================================ Management reporting
-- ---------------------------------------------------------------- The 12 approved job titles (Management reporting)
INSERT INTO job_titles (title, report_role) VALUES
 ('Director', 'director'),
 ('Transport Manager', 'transport_manager'),
 ('Logistics Co-ordinator', 'logistics_coordinator'),
 ('Delivery Service Manager', 'delivery_service_manager'),
 ('Fleet Manager', 'fleet_manager'),
 ('Business Support Manager', 'business_support_manager'),
 ('Freight Administrator', 'freight_administrator'),
 ('Distribution Service Manager', 'distribution_service_manager'),
 ('Route Planning Manager', 'route_planning_manager'),
 ('Business Development Manager', 'business_development_manager'),
 ('Operations Supervisor', 'operations_supervisor'),
 ('Financial Accounts Manager', 'financial_accounts_manager');

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

-- ---------------------------------------------------------------- Report templates (generated by bin/make-report-templates-sql.php)
INSERT INTO report_templates (role_code, period, title, version) VALUES ('director', 'daily', 'Director - Daily Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'company_position', 'Overall company position', 'text', NULL, 'text', 1, 0, 1),
 (@t, 'role', 'operational_developments', 'Important operational developments', 'text', NULL, 'text', 0, 0, 2),
 (@t, 'role', 'critical_issues', 'Critical business issues', 'text', NULL, 'text', 0, 0, 3),
 (@t, 'role', 'decisions_made', 'Important decisions made', 'text', NULL, 'text', 0, 0, 4),
 (@t, 'role', 'financial_concerns', 'Financial or commercial concerns', 'text', NULL, 'text', 0, 0, 5),
 (@t, 'role', 'immediate_attention', 'Issues requiring immediate attention', 'text', NULL, 'text', 0, 0, 6),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 7),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 8),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 9),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 10),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 11),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 12);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('director', 'weekly', 'Director - Weekly Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'business_performance', 'Overall business performance', 'text', NULL, 'text', 1, 0, 1),
 (@t, 'role', 'department_updates', 'Department management updates', 'text', NULL, 'text', 0, 0, 2),
 (@t, 'role', 'revenue_profitability', 'Revenue and profitability overview', 'text', NULL, 'text', 0, 0, 3),
 (@t, 'role', 'operational_performance', 'Operational performance', 'text', NULL, 'text', 0, 0, 4),
 (@t, 'role', 'bd_opportunities', 'Business development opportunities', 'text', NULL, 'text', 0, 0, 5),
 (@t, 'role', 'risks_decisions', 'Major risks and decisions', 'text', NULL, 'text', 0, 0, 6),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 7),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 8),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 9),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 10),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 11),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 12);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('director', 'monthly', 'Director - Monthly Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'company_performance', 'Company performance', 'text', NULL, 'text', 1, 0, 1),
 (@t, 'role', 'objectives_achieved', 'Strategic objectives achieved', 'text', NULL, 'text', 0, 0, 2),
 (@t, 'role', 'department_comparison', 'Department performance comparisons', 'text', NULL, 'text', 0, 0, 3),
 (@t, 'role', 'business_growth', 'Business growth', 'text', NULL, 'text', 0, 0, 4),
 (@t, 'role', 'financial_performance', 'Financial performance', 'text', NULL, 'text', 0, 0, 5),
 (@t, 'role', 'strategic_decisions', 'Strategic decisions', 'text', NULL, 'text', 0, 0, 6),
 (@t, 'role', 'next_month_priorities', 'Priorities for the next month', 'text', NULL, 'text', 1, 0, 7),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 8),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 9),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 10),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 11),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 12),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 13);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('transport_manager', 'daily', 'Transport Manager - Daily Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'drivers_available', 'Drivers available', 'int', NULL, 'int', 1, 1, 1),
 (@t, 'role', 'drivers_allocated', 'Drivers allocated', 'int', NULL, 'int', 1, 0, 2),
 (@t, 'role', 'vehicles_available', 'Vehicles available', 'int', NULL, 'int', 1, 0, 3),
 (@t, 'role', 'vehicles_allocated', 'Vehicles allocated', 'int', NULL, 'int', 1, 1, 4),
 (@t, 'role', 'jobs_received', 'Jobs received', 'int', NULL, 'int', 1, 1, 5),
 (@t, 'role', 'jobs_completed', 'Jobs completed', 'int', NULL, 'int', 1, 1, 6),
 (@t, 'role', 'outstanding_deliveries', 'Outstanding deliveries', 'int', NULL, 'int', 1, 0, 7),
 (@t, 'role', 'delayed_deliveries', 'Delayed deliveries', 'int', NULL, 'int', 1, 1, 8),
 (@t, 'role', 'failed_deliveries', 'Failed deliveries', 'int', NULL, 'int', 1, 1, 9),
 (@t, 'role', 'drivers_present', 'Driver attendance: drivers present', 'int', NULL, 'int', 1, 0, 10),
 (@t, 'role', 'drivers_absent', 'Driver attendance: drivers absent', 'int', NULL, 'int', 1, 0, 11),
 (@t, 'role', 'accidents_incidents', 'Accidents and incidents (number)', 'int', NULL, 'int', 1, 1, 12),
 (@t, 'role', 'incident_details', 'Accidents and incidents: details', 'text', NULL, 'text', 0, 0, 13),
 (@t, 'role', 'transport_issues', 'Transport issues', 'text', NULL, 'text', 0, 0, 14),
 (@t, 'role', 'corrective_actions', 'Corrective actions', 'text', NULL, 'text', 0, 0, 15),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 16),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 17),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 18),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 19),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 20),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 21);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('transport_manager', 'weekly', 'Transport Manager - Weekly Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'total_jobs_completed', 'Total jobs completed', 'calc', 'sum(jobs_completed)', 'int', 0, 1, 1),
 (@t, 'role', 'driver_productivity', 'Driver productivity (jobs per allocated driver-day)', 'calc', 'ratio(sum(jobs_completed),sum(drivers_allocated))', 'dec', 0, 1, 2),
 (@t, 'role', 'vehicle_utilisation', 'Vehicle utilisation (%)', 'calc', 'pct(sum(vehicles_allocated),sum(vehicles_available))', 'pct', 0, 1, 3),
 (@t, 'role', 'delivery_success_rate', 'Delivery success rate (%)', 'calc', 'success(sum(jobs_completed),sum(failed_deliveries))', 'pct', 0, 1, 4),
 (@t, 'role', 'fuel_costs', 'Fuel costs (£)', 'money', NULL, 'money', 1, 1, 5),
 (@t, 'role', 'transport_costs', 'Other transport costs (£)', 'money', NULL, 'money', 1, 0, 6),
 (@t, 'role', 'driver_performance', 'Driver performance', 'text', NULL, 'text', 1, 0, 7),
 (@t, 'role', 'accidents_total', 'Accidents and incidents', 'calc', 'sum(accidents_incidents)', 'int', 0, 0, 8),
 (@t, 'role', 'breakdowns', 'Vehicle breakdowns', 'int', NULL, 'int', 1, 0, 9),
 (@t, 'role', 'outstanding_issues', 'Outstanding operational issues', 'text', NULL, 'text', 0, 0, 10),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 11),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 12),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 13),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 14),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 15),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 16);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('transport_manager', 'monthly', 'Transport Manager - Monthly Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'transport_performance', 'Overall transport performance', 'text', NULL, 'text', 1, 0, 1),
 (@t, 'role', 'jobs_completed_month', 'Jobs completed', 'calc', 'sum(jobs_completed)', 'int', 0, 1, 2),
 (@t, 'role', 'transport_costs_month', 'Transport costs (£, fuel and other)', 'calc', 'wsum(fuel_costs)+wsum(transport_costs)', 'money', 0, 1, 3),
 (@t, 'role', 'cost_efficiency', 'Transport costs and efficiency', 'text', NULL, 'text', 0, 0, 4),
 (@t, 'role', 'driver_trends', 'Driver performance trends', 'text', NULL, 'text', 0, 0, 5),
 (@t, 'role', 'vehicle_utilisation_month', 'Vehicle utilisation (%)', 'calc', 'pct(sum(vehicles_allocated),sum(vehicles_available))', 'pct', 0, 1, 6),
 (@t, 'role', 'delivery_success_month', 'Delivery success rate (%)', 'calc', 'success(sum(jobs_completed),sum(failed_deliveries))', 'pct', 0, 1, 7),
 (@t, 'role', 'delivery_performance', 'Delivery performance', 'text', NULL, 'text', 0, 0, 8),
 (@t, 'role', 'operational_improvements', 'Operational improvements', 'text', NULL, 'text', 0, 0, 9),
 (@t, 'role', 'transport_budget', 'Transport budget for the month (£)', 'money', NULL, 'money', 1, 0, 10),
 (@t, 'role', 'recommendations', 'Recommendations', 'text', NULL, 'text', 1, 0, 11),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 12),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 13),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 14),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 15),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 16),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 17);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('logistics_coordinator', 'daily', 'Logistics Co-ordinator - Daily Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'jobs_received', 'Jobs received', 'int', NULL, 'int', 1, 1, 1),
 (@t, 'role', 'jobs_processed', 'Jobs processed', 'int', NULL, 'int', 1, 1, 2),
 (@t, 'role', 'collections_coordinated', 'Collections coordinated', 'int', NULL, 'int', 1, 1, 3),
 (@t, 'role', 'deliveries_coordinated', 'Deliveries coordinated', 'int', NULL, 'int', 1, 1, 4),
 (@t, 'role', 'communication', 'Customer and driver communication', 'text', NULL, 'text', 0, 0, 5),
 (@t, 'role', 'outstanding_jobs', 'Outstanding jobs', 'int', NULL, 'int', 1, 0, 6),
 (@t, 'role', 'delayed_consignments', 'Delayed consignments', 'int', NULL, 'int', 1, 1, 7),
 (@t, 'role', 'documents_completed', 'Documentation completed (number of documents)', 'int', NULL, 'int', 1, 0, 8),
 (@t, 'role', 'logistics_problems', 'Logistics problems', 'text', NULL, 'text', 0, 0, 9),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 10),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 11),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 12),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 13),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 14),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 15);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('logistics_coordinator', 'weekly', 'Logistics Co-ordinator - Weekly Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'total_coordinated', 'Total jobs coordinated', 'calc', 'sum(collections_coordinated)+sum(deliveries_coordinated)', 'int', 0, 1, 1),
 (@t, 'role', 'jobs_completed_week', 'Jobs completed', 'calc', 'sum(jobs_processed)', 'int', 0, 0, 2),
 (@t, 'role', 'jobs_outstanding_week', 'Jobs outstanding at the end of the week', 'calc', 'last(outstanding_jobs)', 'int', 0, 0, 3),
 (@t, 'role', 'coordination_efficiency', 'Coordination efficiency (% of jobs received that were processed)', 'calc', 'pct(sum(jobs_processed),sum(jobs_received))', 'pct', 0, 1, 4),
 (@t, 'role', 'delayed_week', 'Delayed consignments', 'calc', 'sum(delayed_consignments)', 'int', 0, 1, 5),
 (@t, 'role', 'stakeholder_communication', 'Customer and supplier communication', 'text', NULL, 'text', 0, 0, 6),
 (@t, 'role', 'documentation_errors', 'Documentation errors', 'int', NULL, 'int', 1, 1, 7),
 (@t, 'role', 'operational_improvements', 'Operational improvements', 'text', NULL, 'text', 0, 0, 8),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 9),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 10),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 11),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 12),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 13),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 14);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('logistics_coordinator', 'monthly', 'Logistics Co-ordinator - Monthly Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'jobs_received_month', 'Monthly logistics activity: jobs received', 'calc', 'sum(jobs_received)', 'int', 0, 1, 1),
 (@t, 'role', 'jobs_processed_month', 'Monthly logistics activity: jobs processed', 'calc', 'sum(jobs_processed)', 'int', 0, 1, 2),
 (@t, 'role', 'coordination_performance', 'Coordination performance', 'text', NULL, 'text', 1, 0, 3),
 (@t, 'role', 'service_efficiency', 'Service efficiency (% processed)', 'calc', 'pct(sum(jobs_processed),sum(jobs_received))', 'pct', 0, 1, 4),
 (@t, 'role', 'recurring_problems', 'Recurring operational problems', 'text', NULL, 'text', 0, 0, 5),
 (@t, 'role', 'documentation_accuracy', 'Documentation accuracy (%)', 'calc', 'accuracy(sum(documents_completed),wsum(documentation_errors))', 'pct', 0, 1, 6),
 (@t, 'role', 'process_improvements', 'Process improvements', 'text', NULL, 'text', 0, 0, 7),
 (@t, 'role', 'recommendations', 'Recommendations', 'text', NULL, 'text', 1, 0, 8),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 9),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 10),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 11),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 12),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 13),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 14);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('delivery_service_manager', 'daily', 'Delivery Service Manager - Daily Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'deliveries_scheduled', 'Deliveries scheduled', 'int', NULL, 'int', 1, 1, 1),
 (@t, 'role', 'deliveries_completed', 'Deliveries completed', 'int', NULL, 'int', 1, 1, 2),
 (@t, 'role', 'failed_deliveries', 'Failed deliveries', 'int', NULL, 'int', 1, 1, 3),
 (@t, 'role', 'delivery_delays', 'Delivery delays', 'int', NULL, 'int', 1, 1, 4),
 (@t, 'role', 'customer_complaints', 'Customer complaints', 'int', NULL, 'int', 1, 1, 5),
 (@t, 'role', 'driver_performance', 'Driver performance', 'text', NULL, 'text', 0, 0, 6),
 (@t, 'role', 'service_issues', 'Delivery service issues', 'text', NULL, 'text', 0, 0, 7),
 (@t, 'role', 'corrective_actions', 'Corrective actions', 'text', NULL, 'text', 0, 0, 8),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 9),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 10),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 11),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 12),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 13),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 14);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('delivery_service_manager', 'weekly', 'Delivery Service Manager - Weekly Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'total_deliveries', 'Total deliveries completed', 'calc', 'sum(deliveries_completed)', 'int', 0, 1, 1),
 (@t, 'role', 'success_rate', 'Delivery success rate (%)', 'calc', 'success(sum(deliveries_completed),sum(failed_deliveries))', 'pct', 0, 1, 2),
 (@t, 'role', 'failed_week', 'Failed deliveries', 'calc', 'sum(failed_deliveries)', 'int', 0, 1, 3),
 (@t, 'role', 'delayed_week', 'Delayed deliveries', 'calc', 'sum(delivery_delays)', 'int', 0, 0, 4),
 (@t, 'role', 'customer_satisfaction', 'Customer satisfaction score (%)', 'pct', NULL, 'pct', 1, 1, 5),
 (@t, 'role', 'complaints_received', 'Complaints received', 'calc', 'sum(customer_complaints)', 'int', 0, 1, 6),
 (@t, 'role', 'complaints_resolved', 'Complaints resolved', 'int', NULL, 'int', 1, 0, 7),
 (@t, 'role', 'driver_productivity', 'Driver productivity', 'text', NULL, 'text', 0, 0, 8),
 (@t, 'role', 'service_performance_issues', 'Service performance issues', 'text', NULL, 'text', 0, 0, 9),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 10),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 11),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 12),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 13),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 14),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 15);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('delivery_service_manager', 'monthly', 'Delivery Service Manager - Monthly Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'service_performance', 'Overall delivery service performance', 'text', NULL, 'text', 1, 0, 1),
 (@t, 'role', 'success_rate_month', 'Delivery success rate (%)', 'calc', 'success(sum(deliveries_completed),sum(failed_deliveries))', 'pct', 0, 1, 2),
 (@t, 'role', 'success_trends', 'Delivery success trends', 'text', NULL, 'text', 0, 0, 3),
 (@t, 'role', 'satisfaction_month', 'Customer satisfaction (average %, from weekly reports)', 'calc', 'wavg(customer_satisfaction)', 'pct', 0, 1, 4),
 (@t, 'role', 'failed_month', 'Failed deliveries', 'calc', 'sum(failed_deliveries)', 'int', 0, 1, 5),
 (@t, 'role', 'failed_analysis', 'Failed delivery analysis', 'text', NULL, 'text', 0, 0, 6),
 (@t, 'role', 'service_quality', 'Service quality', 'text', NULL, 'text', 0, 0, 7),
 (@t, 'role', 'driver_trends', 'Driver performance trends', 'text', NULL, 'text', 0, 0, 8),
 (@t, 'role', 'recommendations', 'Service improvement recommendations', 'text', NULL, 'text', 1, 0, 9),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 10),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 11),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 12),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 13),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 14),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 15);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('fleet_manager', 'daily', 'Fleet Manager - Daily Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'vehicles_available', 'Vehicles available', 'int', NULL, 'int', 1, 1, 1),
 (@t, 'role', 'inspections', 'Vehicle inspections completed', 'int', NULL, 'int', 1, 0, 2),
 (@t, 'role', 'defects_reported', 'Defects reported', 'int', NULL, 'int', 1, 1, 3),
 (@t, 'role', 'vehicles_off_road', 'Vehicles off the road (VOR)', 'int', NULL, 'int', 1, 1, 4),
 (@t, 'role', 'repairs_completed', 'Repairs completed', 'int', NULL, 'int', 1, 0, 5),
 (@t, 'role', 'maintenance_outstanding', 'Maintenance jobs outstanding', 'int', NULL, 'int', 1, 0, 6),
 (@t, 'role', 'mot_insurance', 'MOT and insurance concerns', 'text', NULL, 'text', 0, 0, 7),
 (@t, 'role', 'vehicle_incidents', 'Vehicle incidents (number)', 'int', NULL, 'int', 1, 1, 8),
 (@t, 'role', 'incident_details', 'Vehicle incidents: details', 'text', NULL, 'text', 0, 0, 9),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 10),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 11),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 12),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 13),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 14),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 15);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('fleet_manager', 'weekly', 'Fleet Manager - Weekly Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'fleet_availability', 'Fleet availability (average vehicles available per day)', 'calc', 'avg(vehicles_available)', 'dec', 0, 1, 1),
 (@t, 'role', 'vor_average', 'Vehicles off the road (average per day)', 'calc', 'avg(vehicles_off_road)', 'dec', 0, 0, 2),
 (@t, 'role', 'vehicle_utilisation', 'Vehicle utilisation (%)', 'pct', NULL, 'pct', 1, 1, 3),
 (@t, 'role', 'maintenance_completed', 'Maintenance and repairs completed', 'calc', 'sum(repairs_completed)', 'int', 0, 1, 4),
 (@t, 'role', 'breakdowns', 'Breakdowns', 'int', NULL, 'int', 1, 1, 5),
 (@t, 'role', 'repair_costs', 'Repair costs (£)', 'money', NULL, 'money', 1, 1, 6),
 (@t, 'role', 'vehicle_compliance', 'Vehicle compliance', 'text', NULL, 'text', 0, 0, 7),
 (@t, 'role', 'outstanding_repairs', 'Outstanding repairs at the end of the week', 'calc', 'last(maintenance_outstanding)', 'int', 0, 0, 8),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 9),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 10),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 11),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 12),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 13),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 14);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('fleet_manager', 'monthly', 'Fleet Manager - Monthly Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'fleet_performance', 'Fleet performance', 'text', NULL, 'text', 1, 0, 1),
 (@t, 'role', 'maintenance_costs', 'Total maintenance costs (£)', 'calc', 'wsum(repair_costs)', 'money', 0, 1, 2),
 (@t, 'role', 'downtime', 'Vehicle downtime (vehicle-days off the road)', 'calc', 'sum(vehicles_off_road)', 'int', 0, 1, 3),
 (@t, 'role', 'breakdowns_month', 'Breakdowns', 'calc', 'wsum(breakdowns)', 'int', 0, 1, 4),
 (@t, 'role', 'breakdown_trends', 'Breakdown trends', 'text', NULL, 'text', 0, 0, 5),
 (@t, 'role', 'replacement_requirements', 'Vehicle replacement requirements', 'text', NULL, 'text', 0, 0, 6),
 (@t, 'role', 'mot_insurance_compliance', 'Insurance and MOT compliance', 'text', NULL, 'text', 1, 0, 7),
 (@t, 'role', 'recommendations', 'Fleet efficiency recommendations', 'text', NULL, 'text', 1, 0, 8),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 9),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 10),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 11),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 12),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 13),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 14);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('business_support_manager', 'daily', 'Business Support Manager - Daily Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'administrative_activities', 'Administrative activities', 'text', NULL, 'text', 1, 0, 1),
 (@t, 'role', 'staff_support', 'Internal staff support requests handled', 'int', NULL, 'int', 1, 1, 2),
 (@t, 'role', 'documents_processed', 'Documents processed', 'int', NULL, 'int', 1, 1, 3),
 (@t, 'role', 'support_requests', 'Business support requests received', 'int', NULL, 'int', 1, 1, 4),
 (@t, 'role', 'outstanding_tasks', 'Outstanding tasks', 'int', NULL, 'int', 1, 1, 5),
 (@t, 'role', 'management_attention', 'Issues requiring management attention', 'text', NULL, 'text', 0, 0, 6),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 7),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 8),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 9),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 10),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 11),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 12);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('business_support_manager', 'weekly', 'Business Support Manager - Weekly Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'workload', 'Business support workload (requests received)', 'calc', 'sum(support_requests)', 'int', 0, 1, 1),
 (@t, 'role', 'administrative_performance', 'Administrative performance', 'text', NULL, 'text', 1, 0, 2),
 (@t, 'role', 'tasks_completed_week', 'Tasks completed (support handled and documents processed)', 'calc', 'sum(staff_support)+sum(documents_processed)', 'int', 0, 1, 3),
 (@t, 'role', 'outstanding_requests', 'Outstanding requests at the end of the week', 'calc', 'last(outstanding_tasks)', 'int', 0, 1, 4),
 (@t, 'role', 'department_support', 'Department support', 'text', NULL, 'text', 0, 0, 5),
 (@t, 'role', 'process_issues', 'Process issues', 'text', NULL, 'text', 0, 0, 6),
 (@t, 'role', 'improvement_actions', 'Improvement actions', 'text', NULL, 'text', 0, 0, 7),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 8),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 9),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 10),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 11),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 12),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 13);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('business_support_manager', 'monthly', 'Business Support Manager - Monthly Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'support_performance', 'Business support performance', 'text', NULL, 'text', 1, 0, 1),
 (@t, 'role', 'administrative_efficiency', 'Administrative efficiency', 'text', NULL, 'text', 0, 0, 2),
 (@t, 'role', 'requests_month', 'Department support statistics: requests received', 'calc', 'sum(support_requests)', 'int', 0, 1, 3),
 (@t, 'role', 'handled_month', 'Department support statistics: requests handled', 'calc', 'sum(staff_support)', 'int', 0, 1, 4),
 (@t, 'role', 'documents_month', 'Documents processed', 'calc', 'sum(documents_processed)', 'int', 0, 0, 5),
 (@t, 'role', 'process_improvements', 'Process improvements', 'text', NULL, 'text', 0, 0, 6),
 (@t, 'role', 'resource_requirements', 'Resource requirements', 'text', NULL, 'text', 0, 0, 7),
 (@t, 'role', 'outstanding_issues', 'Outstanding issues', 'text', NULL, 'text', 0, 0, 8),
 (@t, 'role', 'recommendations', 'Recommendations', 'text', NULL, 'text', 1, 0, 9),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 10),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 11),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 12),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 13),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 14),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 15);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('freight_administrator', 'daily', 'Freight Administrator - Daily Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'bookings_processed', 'Freight bookings processed', 'int', NULL, 'int', 1, 1, 1),
 (@t, 'role', 'consignment_documents', 'Consignment documentation completed', 'int', NULL, 'int', 1, 0, 2),
 (@t, 'role', 'delivery_paperwork', 'Delivery paperwork completed', 'int', NULL, 'int', 1, 0, 3),
 (@t, 'role', 'stakeholder_updates', 'Customer and supplier updates', 'text', NULL, 'text', 0, 0, 4),
 (@t, 'role', 'invoices_processed', 'Invoices and supporting documents processed', 'int', NULL, 'int', 1, 1, 5),
 (@t, 'role', 'missing_documentation', 'Missing documentation', 'int', NULL, 'int', 1, 1, 6),
 (@t, 'role', 'booking_errors', 'Booking errors', 'int', NULL, 'int', 1, 1, 7),
 (@t, 'role', 'outstanding_admin', 'Outstanding administrative tasks', 'int', NULL, 'int', 1, 0, 8),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 9),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 10),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 11),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 12),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 13),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 14);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('freight_administrator', 'weekly', 'Freight Administrator - Weekly Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'total_bookings', 'Total bookings processed', 'calc', 'sum(bookings_processed)', 'int', 0, 1, 1),
 (@t, 'role', 'documentation_accuracy', 'Documentation accuracy (%)', 'calc', 'accuracy(sum(consignment_documents)+sum(delivery_paperwork),sum(missing_documentation))', 'pct', 0, 1, 2),
 (@t, 'role', 'outstanding_paperwork', 'Outstanding paperwork at the end of the week', 'calc', 'last(outstanding_admin)', 'int', 0, 0, 3),
 (@t, 'role', 'admin_workload', 'Freight administration workload', 'text', NULL, 'text', 1, 0, 4),
 (@t, 'role', 'booking_amendments', 'Booking amendments', 'int', NULL, 'int', 1, 0, 5),
 (@t, 'role', 'customer_communication', 'Customer communication', 'text', NULL, 'text', 0, 0, 6),
 (@t, 'role', 'administrative_errors', 'Administrative errors (booking errors)', 'calc', 'sum(booking_errors)', 'int', 0, 1, 7),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 8),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 9),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 10),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 11),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 12),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 13);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('freight_administrator', 'monthly', 'Freight Administrator - Monthly Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'bookings_month', 'Monthly freight bookings', 'calc', 'sum(bookings_processed)', 'int', 0, 1, 1),
 (@t, 'role', 'amendments_month', 'Booking amendments', 'calc', 'wsum(booking_amendments)', 'int', 0, 0, 2),
 (@t, 'role', 'documentation_accuracy_month', 'Documentation accuracy (%)', 'calc', 'accuracy(sum(consignment_documents)+sum(delivery_paperwork),sum(missing_documentation))', 'pct', 0, 1, 3),
 (@t, 'role', 'productivity', 'Administrative productivity', 'text', NULL, 'text', 1, 0, 4),
 (@t, 'role', 'booking_errors_month', 'Booking errors', 'calc', 'sum(booking_errors)', 'int', 0, 1, 5),
 (@t, 'role', 'error_trends', 'Booking error trends', 'text', NULL, 'text', 0, 0, 6),
 (@t, 'role', 'outstanding_paperwork_month', 'Outstanding paperwork at month end', 'calc', 'last(outstanding_admin)', 'int', 0, 0, 7),
 (@t, 'role', 'process_improvements', 'Process improvements', 'text', NULL, 'text', 0, 0, 8),
 (@t, 'role', 'recommendations', 'Recommendations', 'text', NULL, 'text', 1, 0, 9),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 10),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 11),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 12),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 13),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 14),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 15);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('distribution_service_manager', 'daily', 'Distribution Service Manager - Daily Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'jobs_received', 'Distribution jobs received', 'int', NULL, 'int', 1, 1, 1),
 (@t, 'role', 'routes_allocated', 'Distribution routes allocated', 'int', NULL, 'int', 1, 0, 2),
 (@t, 'role', 'vehicles_assigned', 'Vehicles assigned', 'int', NULL, 'int', 1, 0, 3),
 (@t, 'role', 'drivers_assigned', 'Drivers assigned', 'int', NULL, 'int', 1, 0, 4),
 (@t, 'role', 'collections_completed', 'Collections completed', 'int', NULL, 'int', 1, 1, 5),
 (@t, 'role', 'deliveries_completed', 'Deliveries completed', 'int', NULL, 'int', 1, 1, 6),
 (@t, 'role', 'distribution_delays', 'Distribution delays', 'int', NULL, 'int', 1, 1, 7),
 (@t, 'role', 'failed_jobs', 'Failed distribution jobs', 'int', NULL, 'int', 1, 1, 8),
 (@t, 'role', 'operational_issues', 'Operational issues', 'text', NULL, 'text', 0, 0, 9),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 10),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 11),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 12),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 13),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 14),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 15);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('distribution_service_manager', 'weekly', 'Distribution Service Manager - Weekly Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'distribution_performance', 'Distribution performance', 'text', NULL, 'text', 1, 0, 1),
 (@t, 'role', 'total_completed', 'Total jobs completed', 'calc', 'sum(collections_completed)+sum(deliveries_completed)', 'int', 0, 1, 2),
 (@t, 'role', 'route_efficiency', 'Route efficiency (jobs completed per route)', 'calc', 'ratio(sum(collections_completed)+sum(deliveries_completed),sum(routes_allocated))', 'dec', 0, 1, 3),
 (@t, 'role', 'vehicle_utilisation', 'Vehicle utilisation (%)', 'pct', NULL, 'pct', 1, 1, 4),
 (@t, 'role', 'driver_productivity', 'Driver productivity (jobs per driver-day)', 'calc', 'ratio(sum(collections_completed)+sum(deliveries_completed),sum(drivers_assigned))', 'dec', 0, 1, 5),
 (@t, 'role', 'delays_week', 'Distribution delays', 'calc', 'sum(distribution_delays)', 'int', 0, 0, 6),
 (@t, 'role', 'failed_week', 'Failed deliveries', 'calc', 'sum(failed_jobs)', 'int', 0, 1, 7),
 (@t, 'role', 'operational_improvements', 'Operational improvements', 'text', NULL, 'text', 0, 0, 8),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 9),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 10),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 11),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 12),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 13),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 14);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('distribution_service_manager', 'monthly', 'Distribution Service Manager - Monthly Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'overall_performance', 'Overall distribution performance', 'text', NULL, 'text', 1, 0, 1),
 (@t, 'role', 'distribution_efficiency', 'Distribution efficiency (% of jobs completed, not failed)', 'calc', 'success(sum(collections_completed)+sum(deliveries_completed),sum(failed_jobs))', 'pct', 0, 1, 2),
 (@t, 'role', 'route_trends', 'Route performance trends', 'text', NULL, 'text', 0, 0, 3),
 (@t, 'role', 'operational_costs', 'Operational costs (£)', 'money', NULL, 'money', 1, 1, 4),
 (@t, 'role', 'vehicle_utilisation_month', 'Vehicle utilisation (average %, from weekly reports)', 'calc', 'wavg(vehicle_utilisation)', 'pct', 0, 1, 5),
 (@t, 'role', 'service_quality', 'Service quality', 'text', NULL, 'text', 0, 0, 6),
 (@t, 'role', 'improvement_plans', 'Distribution improvement plans', 'text', NULL, 'text', 1, 0, 7),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 8),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 9),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 10),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 11),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 12),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 13);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('route_planning_manager', 'daily', 'Route Planning Manager - Daily Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'routes_planned', 'Routes planned', 'int', NULL, 'int', 1, 1, 1),
 (@t, 'role', 'jobs_allocated', 'Jobs allocated', 'int', NULL, 'int', 1, 1, 2),
 (@t, 'role', 'drivers_assigned', 'Drivers assigned', 'int', NULL, 'int', 1, 0, 3),
 (@t, 'role', 'vehicles_allocated', 'Vehicles allocated', 'int', NULL, 'int', 1, 0, 4),
 (@t, 'role', 'route_changes', 'Route changes', 'int', NULL, 'int', 1, 1, 5),
 (@t, 'role', 'missed_delayed', 'Missed or delayed jobs', 'int', NULL, 'int', 1, 1, 6),
 (@t, 'role', 'planning_issues', 'Planning issues', 'text', NULL, 'text', 0, 0, 7),
 (@t, 'role', 'actions', 'Actions taken', 'text', NULL, 'text', 0, 0, 8),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 9),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 10),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 11),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 12),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 13),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 14);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('route_planning_manager', 'weekly', 'Route Planning Manager - Weekly Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'total_routes', 'Total routes planned', 'calc', 'sum(routes_planned)', 'int', 0, 1, 1),
 (@t, 'role', 'planning_accuracy', 'Planning accuracy (% of routes run without changes)', 'calc', 'accuracy(sum(routes_planned),sum(route_changes))', 'pct', 0, 1, 2),
 (@t, 'role', 'route_efficiency', 'Route efficiency (jobs per route)', 'calc', 'ratio(sum(jobs_allocated),sum(routes_planned))', 'dec', 0, 1, 3),
 (@t, 'role', 'allocation_efficiency', 'Vehicle and driver allocation efficiency', 'text', NULL, 'text', 0, 0, 4),
 (@t, 'role', 'unplanned_changes', 'Unplanned route changes', 'calc', 'sum(route_changes)', 'int', 0, 0, 5),
 (@t, 'role', 'delayed_week', 'Delayed deliveries (missed or delayed jobs)', 'calc', 'sum(missed_delayed)', 'int', 0, 1, 6),
 (@t, 'role', 'fuel_efficiency', 'Fuel efficiency (miles per gallon)', 'dec', NULL, 'dec', 1, 1, 7),
 (@t, 'role', 'planned_mileage', 'Planned mileage (miles)', 'int', NULL, 'int', 1, 0, 8),
 (@t, 'role', 'planning_improvements', 'Planning improvements', 'text', NULL, 'text', 0, 0, 9),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 10),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 11),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 12),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 13),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 14),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 15);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('route_planning_manager', 'monthly', 'Route Planning Manager - Monthly Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'planning_performance', 'Route planning performance', 'text', NULL, 'text', 1, 0, 1),
 (@t, 'role', 'efficiency_trends', 'Route efficiency trends', 'text', NULL, 'text', 0, 0, 2),
 (@t, 'role', 'mileage_month', 'Mileage (planned miles, from weekly reports)', 'calc', 'wsum(planned_mileage)', 'int', 0, 1, 3),
 (@t, 'role', 'mileage_analysis', 'Mileage analysis', 'text', NULL, 'text', 0, 0, 4),
 (@t, 'role', 'vehicle_utilisation', 'Vehicle utilisation', 'text', NULL, 'text', 0, 0, 5),
 (@t, 'role', 'planning_accuracy_month', 'Planning accuracy (%)', 'calc', 'accuracy(sum(routes_planned),sum(route_changes))', 'pct', 0, 1, 6),
 (@t, 'role', 'cost_savings', 'Operational cost savings (£)', 'money', NULL, 'money', 1, 1, 7),
 (@t, 'role', 'recommendations', 'Route optimisation recommendations', 'text', NULL, 'text', 1, 0, 8),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 9),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 10),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 11),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 12),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 13),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 14);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('business_development_manager', 'daily', 'Business Development Manager - Daily Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'enquiries', 'New business enquiries', 'int', NULL, 'int', 1, 1, 1),
 (@t, 'role', 'customers_contacted', 'Potential customers contacted', 'int', NULL, 'int', 1, 1, 2),
 (@t, 'role', 'calls_meetings', 'Sales calls and meetings', 'int', NULL, 'int', 1, 1, 3),
 (@t, 'role', 'quotations', 'Quotations prepared', 'int', NULL, 'int', 1, 1, 4),
 (@t, 'role', 'quotation_value', 'Value of quotations prepared (£)', 'money', NULL, 'money', 1, 0, 5),
 (@t, 'role', 'new_opportunities', 'New opportunities', 'text', NULL, 'text', 0, 0, 6),
 (@t, 'role', 'follow_ups', 'Customer follow-ups', 'int', NULL, 'int', 1, 0, 7),
 (@t, 'role', 'bd_activities', 'Business development activities', 'text', NULL, 'text', 0, 0, 8),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 9),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 10),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 11),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 12),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 13),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 14);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('business_development_manager', 'weekly', 'Business Development Manager - Weekly Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'leads_generated', 'New leads generated', 'calc', 'sum(enquiries)', 'int', 0, 1, 1),
 (@t, 'role', 'customer_meetings', 'Customer meetings and calls', 'calc', 'sum(calls_meetings)', 'int', 0, 0, 2),
 (@t, 'role', 'quotations_submitted', 'Quotations submitted', 'calc', 'sum(quotations)', 'int', 0, 1, 3),
 (@t, 'role', 'opportunities', 'New business opportunities', 'text', NULL, 'text', 0, 0, 4),
 (@t, 'role', 'pipeline_value', 'Sales pipeline value (£)', 'money', NULL, 'money', 1, 1, 5),
 (@t, 'role', 'deals_secured', 'Deals secured', 'int', NULL, 'int', 1, 1, 6),
 (@t, 'role', 'deals_value', 'Value of deals secured (£)', 'money', NULL, 'money', 1, 1, 7),
 (@t, 'role', 'lost_opportunities', 'Lost opportunities', 'int', NULL, 'int', 1, 0, 8),
 (@t, 'role', 'lost_reasons', 'Lost opportunities: reasons', 'text', NULL, 'text', 0, 0, 9),
 (@t, 'role', 'follow_up_actions', 'Follow-up actions', 'text', NULL, 'text', 0, 0, 10),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 11),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 12),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 13),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 14),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 15),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 16);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('business_development_manager', 'monthly', 'Business Development Manager - Monthly Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'new_customers', 'New customers secured', 'calc', 'wsum(deals_secured)', 'int', 0, 1, 1),
 (@t, 'role', 'new_business_revenue', 'Revenue generated from new business (£)', 'money', NULL, 'money', 1, 1, 2),
 (@t, 'role', 'pipeline_month', 'Sales pipeline value at month end (£)', 'calc', 'wlast(pipeline_value)', 'money', 0, 1, 3),
 (@t, 'role', 'conversion_rate', 'Conversion rate (% of quotations that became deals)', 'calc', 'pct(wsum(deals_secured),sum(quotations))', 'pct', 0, 1, 4),
 (@t, 'role', 'customer_acquisition', 'Customer acquisition', 'text', NULL, 'text', 0, 0, 5),
 (@t, 'role', 'growth_opportunities', 'Business growth opportunities', 'text', NULL, 'text', 0, 0, 6),
 (@t, 'role', 'competitor_observations', 'Competitor observations', 'text', NULL, 'text', 0, 0, 7),
 (@t, 'role', 'next_month_target', 'Next month''s sales target (£)', 'money', NULL, 'money', 1, 0, 8),
 (@t, 'role', 'next_month_targets', 'Next month''s sales targets: details', 'text', NULL, 'text', 1, 0, 9),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 10),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 11),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 12),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 13),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 14),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 15);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('operations_supervisor', 'daily', 'Operations Supervisor - Daily Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'staff_rostered', 'Staff rostered', 'int', NULL, 'int', 1, 0, 1),
 (@t, 'role', 'staff_present', 'Staff attendance: staff present', 'int', NULL, 'int', 1, 1, 2),
 (@t, 'role', 'work_allocated', 'Daily work allocation (tasks allocated)', 'int', NULL, 'int', 1, 0, 3),
 (@t, 'role', 'team_tasks_completed', 'Tasks completed by the team', 'int', NULL, 'int', 1, 1, 4),
 (@t, 'role', 'team_productivity', 'Team productivity', 'text', NULL, 'text', 0, 0, 5),
 (@t, 'role', 'operational_problems', 'Operational problems', 'text', NULL, 'text', 0, 0, 6),
 (@t, 'role', 'performance_issues', 'Employee performance issues', 'text', NULL, 'text', 0, 0, 7),
 (@t, 'role', 'health_safety_concerns', 'Health and safety concerns (number)', 'int', NULL, 'int', 1, 1, 8),
 (@t, 'role', 'health_safety_details', 'Health and safety concerns: details', 'text', NULL, 'text', 0, 0, 9),
 (@t, 'role', 'actions', 'Actions taken', 'text', NULL, 'text', 0, 0, 10),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 11),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 12),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 13),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 14),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 15),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 16);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('operations_supervisor', 'weekly', 'Operations Supervisor - Weekly Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'team_performance', 'Team performance', 'text', NULL, 'text', 1, 0, 1),
 (@t, 'role', 'attendance_rate', 'Staff attendance (% of rostered staff present)', 'calc', 'pct(sum(staff_present),sum(staff_rostered))', 'pct', 0, 1, 2),
 (@t, 'role', 'late_arrivals', 'Punctuality: late arrivals', 'int', NULL, 'int', 1, 0, 3),
 (@t, 'role', 'work_completion', 'Work completion (% of allocated tasks completed)', 'calc', 'pct(sum(team_tasks_completed),sum(work_allocated))', 'pct', 0, 1, 4),
 (@t, 'role', 'productivity_issues', 'Productivity issues', 'text', NULL, 'text', 0, 0, 5),
 (@t, 'role', 'operational_incidents', 'Operational incidents (health and safety)', 'calc', 'sum(health_safety_concerns)', 'int', 0, 1, 6),
 (@t, 'role', 'outstanding_team_tasks', 'Outstanding tasks', 'text', NULL, 'text', 0, 0, 7),
 (@t, 'role', 'staff_support', 'Staff support requirements', 'text', NULL, 'text', 0, 0, 8),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 9),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 10),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 11),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 12),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 13),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 14);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('operations_supervisor', 'monthly', 'Operations Supervisor - Monthly Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'team_performance_month', 'Overall team performance', 'text', NULL, 'text', 1, 0, 1),
 (@t, 'role', 'productivity_trends', 'Employee productivity trends', 'text', NULL, 'text', 0, 0, 2),
 (@t, 'role', 'attendance_month', 'Attendance (% of rostered staff present)', 'calc', 'pct(sum(staff_present),sum(staff_rostered))', 'pct', 0, 1, 3),
 (@t, 'role', 'late_month', 'Late arrivals', 'calc', 'wsum(late_arrivals)', 'int', 0, 0, 4),
 (@t, 'role', 'operational_efficiency', 'Operational efficiency (% of allocated tasks completed)', 'calc', 'pct(sum(team_tasks_completed),sum(work_allocated))', 'pct', 0, 1, 5),
 (@t, 'role', 'training_requirements', 'Staff training requirements', 'text', NULL, 'text', 0, 0, 6),
 (@t, 'role', 'recurring_problems', 'Recurring problems', 'text', NULL, 'text', 0, 0, 7),
 (@t, 'role', 'recommendations', 'Improvement recommendations', 'text', NULL, 'text', 1, 0, 8),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 9),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 10),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 11),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 12),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 13),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 14);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('financial_accounts_manager', 'daily', 'Financial Accounts Manager - Daily Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'payments_received', 'Customer payments received (£)', 'money', NULL, 'money', 1, 1, 1),
 (@t, 'role', 'supplier_invoices', 'Supplier invoices received (number)', 'int', NULL, 'int', 1, 0, 2),
 (@t, 'role', 'supplier_invoices_value', 'Supplier invoices received (£)', 'money', NULL, 'money', 1, 0, 3),
 (@t, 'role', 'payments_processed', 'Payments processed (£)', 'money', NULL, 'money', 1, 1, 4),
 (@t, 'role', 'invoices_raised', 'Customer invoices raised (number)', 'int', NULL, 'int', 1, 1, 5),
 (@t, 'role', 'invoices_raised_value', 'Customer invoices raised (£)', 'money', NULL, 'money', 1, 1, 6),
 (@t, 'role', 'payment_queries', 'Outstanding payment queries', 'int', NULL, 'int', 1, 0, 7),
 (@t, 'role', 'bank_issues', 'Bank and transaction issues', 'text', NULL, 'text', 0, 0, 8),
 (@t, 'role', 'urgent_matters', 'Urgent financial matters', 'text', NULL, 'text', 0, 0, 9),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 10),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 11),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 12),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 13),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 14),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 15);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('financial_accounts_manager', 'weekly', 'Financial Accounts Manager - Weekly Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'weekly_income', 'Weekly income (£)', 'calc', 'sum(payments_received)', 'money', 0, 1, 1),
 (@t, 'role', 'weekly_expenditure', 'Weekly expenditure (£)', 'calc', 'sum(payments_processed)', 'money', 0, 1, 2),
 (@t, 'role', 'outstanding_invoices', 'Outstanding customer invoices (£)', 'money', NULL, 'money', 1, 1, 3),
 (@t, 'role', 'supplier_commitments', 'Supplier payment commitments (£)', 'money', NULL, 'money', 1, 0, 4),
 (@t, 'role', 'debtors_over_90', 'Aged debtors over 90 days (£)', 'money', NULL, 'money', 1, 1, 5),
 (@t, 'role', 'aged_debtors', 'Aged debtors overview', 'text', NULL, 'text', 0, 0, 6),
 (@t, 'role', 'cash_position', 'Cash flow position: closing cash balance (£)', 'money', NULL, 'money', 1, 1, 7),
 (@t, 'role', 'discrepancies', 'Financial discrepancies', 'text', NULL, 'text', 0, 0, 8),
 (@t, 'role', 'actions_required', 'Actions required', 'text', NULL, 'text', 0, 0, 9),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 10),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 11),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 12),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 13),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 14),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 15);
INSERT INTO report_templates (role_code, period, title, version) VALUES ('financial_accounts_manager', 'monthly', 'Financial Accounts Manager - Monthly Report', 1);
SET @t = LAST_INSERT_ID();
INSERT INTO report_template_fields (template_id, section, field_key, label, field_type, formula, display, is_required, is_kpi, sort_order) VALUES
 (@t, 'role', 'monthly_revenue', 'Monthly revenue: customer invoices raised (£)', 'calc', 'sum(invoices_raised_value)', 'money', 0, 1, 1),
 (@t, 'role', 'monthly_receipts', 'Customer payments received (£)', 'calc', 'sum(payments_received)', 'money', 0, 0, 2),
 (@t, 'role', 'monthly_expenditure', 'Monthly expenditure (£)', 'calc', 'sum(payments_processed)', 'money', 0, 1, 3),
 (@t, 'role', 'net_cash_movement', 'Profit and loss summary: receipts less expenditure (£)', 'calc', 'sum(payments_received)-sum(payments_processed)', 'money', 0, 1, 4),
 (@t, 'role', 'pl_summary', 'Profit and loss summary', 'text', NULL, 'text', 1, 0, 5),
 (@t, 'role', 'debtor_creditor', 'Debtor and creditor position', 'text', NULL, 'text', 0, 0, 6),
 (@t, 'role', 'outstanding_invoices_month', 'Outstanding invoices at month end (£)', 'calc', 'wlast(outstanding_invoices)', 'money', 0, 1, 7),
 (@t, 'role', 'cash_flow_analysis', 'Cash flow analysis', 'text', NULL, 'text', 0, 0, 8),
 (@t, 'role', 'budget_performance', 'Budget performance', 'text', NULL, 'text', 0, 0, 9),
 (@t, 'role', 'financial_risks', 'Financial risks', 'text', NULL, 'text', 0, 0, 10),
 (@t, 'role', 'recommendations', 'Recommendations', 'text', NULL, 'text', 1, 0, 11),
 (@t, 'standard', 'tasks_completed', 'Tasks completed', 'text', NULL, 'text', 1, 0, 12),
 (@t, 'standard', 'tasks_outstanding', 'Tasks outstanding', 'text', NULL, 'text', 0, 0, 13),
 (@t, 'standard', 'problems', 'Problems encountered', 'text', NULL, 'text', 0, 0, 14),
 (@t, 'standard', 'actions_taken', 'Actions taken', 'text', NULL, 'text', 0, 0, 15),
 (@t, 'standard', 'support_required', 'Support required', 'text', NULL, 'text', 0, 0, 16),
 (@t, 'standard', 'comments', 'Additional comments', 'text', NULL, 'text', 0, 0, 17);
-- ---------------------------------------------------------------- End of report templates
