-- News module: company announcements emailed from hr@menakantransport.com.
-- Templates, drafts, scheduling, a per-recipient delivery log and sender-authorisation checks.
SET NAMES utf8mb4;

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

INSERT INTO news_templates (name, subject, body) VALUES
 ('General announcement', 'Announcement from Menakan Transport', 'Dear {first_name},\n\n[Write the announcement here.]\n\nIf you have any questions, please contact HR.'),
 ('Policy update', 'Policy update: [policy name]', 'Dear {first_name},\n\nWe have updated the [policy name] policy. The main changes are:\n\n- [change 1]\n- [change 2]\n\nThe new policy applies from [date]. Please read it and contact HR with any questions.'),
 ('Site or depot notice', 'Notice: [subject]', 'Dear {first_name},\n\nPlease note: [details, including dates, times and location].\n\nThank you for your co-operation.');
