-- P60 documents: one current P60 per employee per UK tax year (6 April - 5 April).
-- Replaced and deleted documents are kept (is_current = NULL) for the audit trail.
SET NAMES utf8mb4;

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
  UNIQUE KEY uq_p60_current (employee_id, tax_year_start, is_current),
  UNIQUE KEY uq_p60_key (storage_key),
  KEY idx_p60_year (tax_year_start, status),
  CONSTRAINT fk_p60_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
