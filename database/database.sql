-- =====================================================================
-- Menakan Transport Ltd - HRMS v1 schema
-- Runs on MariaDB 10.4+ and MySQL 8.0.16+. InnoDB, utf8mb4.
-- All DATETIME columns hold UTC. Working time is stored as whole minutes.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS audit_logs, notifications, system_settings, employee_import_rows, employee_import_batches, news_sender_checks, news_deliveries, news_posts, news_templates, id_cards, p60_documents, payslips,
  leave_ledger, leave_requests, leave_entitlements, leave_types, public_holidays,
  hours_exceptions, shift_import_rows, shift_import_batches, shifts, locations, shift_types,
  attendance_import_rows, attendance_corrections, attendance_records, attendance_import_batches,
  employee_documents, document_requirements, document_categories, employee_work_experience, employee_education,
  user_sessions, login_attempts, password_tokens, users,
  employees, employee_code_seq, job_titles, departments, work_patterns;

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
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_job_titles_title (title),
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
  status                  ENUM('active','probation','on_leave','career_break','suspended','left') NOT NULL DEFAULT 'active',
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
  status          ENUM('present','late','incomplete') NOT NULL DEFAULT 'present',
  source          ENUM('web','import','manual') NOT NULL,
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
 ('leave_year_start_month',     '1',    'int',     'Leave year start month (1-12)', NULL),
 ('annual_leave_weeks',         '5.6',  'decimal', 'Annual leave entitlement (weeks)', '5.6 weeks = 28 days for a 5-day worker'),
 ('leave_includes_bank_holidays','1',   'bool',    'Entitlement includes bank holidays', 'Bank holidays on working days are deducted from the annual balance'),
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
