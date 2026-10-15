-- Employee ID cards: issued and managed by admins, shown read-only to the employee.
-- One current card per employee; regenerated or revoked cards are kept (is_current = NULL) as history.
SET NAMES utf8mb4;

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

UPDATE system_settings SET label = 'Default ID card expiry date (YYYY-MM-DD)', description = 'Pre-filled when an admin creates or regenerates an ID card'
 WHERE `key` = 'id_card_expiry_date';

-- Existing employees keep the card they could already see: issue version 1 to every current employee.
INSERT INTO id_cards (employee_id, card_number, version, issue_date, expiry_date)
SELECT e.id, CONCAT(e.employee_code, '-01'), 1, CURDATE(),
       COALESCE((SELECT `value` FROM system_settings WHERE `key` = 'id_card_expiry_date'), '2026-12-31')
  FROM employees e WHERE e.deleted_at IS NULL AND e.status <> 'left';
