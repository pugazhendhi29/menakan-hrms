-- Employee import (Employee ID is the key): one batch per uploaded file, one row per sheet row.
SET NAMES utf8mb4;

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