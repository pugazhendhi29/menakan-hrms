-- Employee profile extension: work location, source of hire, personal/visa/right-to-work details,
-- identity numbers, permanent address, personal contact, photo, plus work experience and education.
-- Safe to run once on an existing database (database.sql already includes these changes for new installs).

SET NAMES utf8mb4;

ALTER TABLE employees
  ADD COLUMN location_id               INT UNSIGNED NULL AFTER work_pattern_id,
  ADD COLUMN source_of_hire            VARCHAR(40)  NULL AFTER employment_type,
  ADD COLUMN marital_status            VARCHAR(30)  NULL AFTER gender,
  ADD COLUMN nationality               VARCHAR(60)  NULL AFTER marital_status,
  ADD COLUMN visa_type                 VARCHAR(60)  NULL AFTER nationality,
  ADD COLUMN visa_start_date           DATE         NULL AFTER visa_type,
  ADD COLUMN visa_end_date             DATE         NULL AFTER visa_start_date,
  ADD COLUMN rtw_check_status          VARCHAR(30)  NULL COMMENT 'Right to work audit check result' AFTER visa_end_date,
  ADD COLUMN rtw_check_date            DATE         NULL AFTER rtw_check_status,
  ADD COLUMN ni_number                 VARCHAR(13)  NULL COMMENT 'Admin-only' AFTER rtw_check_date,
  ADD COLUMN passport_number           VARCHAR(20)  NULL COMMENT 'Admin-only' AFTER ni_number,
  ADD COLUMN permanent_same_as_present TINYINT(1)   NOT NULL DEFAULT 1 AFTER postcode,
  ADD COLUMN permanent_address         VARCHAR(400) NULL AFTER permanent_same_as_present,
  ADD COLUMN personal_mobile           VARCHAR(30)  NULL AFTER phone,
  ADD COLUMN personal_email            VARCHAR(190) NULL AFTER personal_mobile,
  ADD COLUMN photo_key                 VARCHAR(255) NULL AFTER notes,
  ADD UNIQUE KEY uq_employees_ni (ni_number),
  ADD KEY idx_employees_visa_end (visa_end_date),
  ADD CONSTRAINT fk_employees_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL;

CREATE TABLE employee_work_experience (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id  INT UNSIGNED NOT NULL,
  company_name VARCHAR(120) NOT NULL,
  job_title    VARCHAR(100) NOT NULL,
  from_date    DATE         NOT NULL,
  to_date      DATE         NOT NULL,
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
  qualification   VARCHAR(120) NOT NULL COMMENT 'Degree / diploma',
  specialization  VARCHAR(120) NULL,
  completion_date DATE         NULL,
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_education_emp (employee_id),
  CONSTRAINT fk_education_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
