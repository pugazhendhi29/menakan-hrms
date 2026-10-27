-- News threads: conversations that took place earlier (email, chat, meetings), recorded by HR with their
-- original dates and times and published to employees. The real recording and publishing times are kept too.
SET NAMES utf8mb4;

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
  body             TEXT         NOT NULL,
  created_by       INT UNSIGNED NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Real time the message was recorded in the HRMS',
  updated_by       INT UNSIGNED NULL,
  updated_at       DATETIME     NULL,
  KEY idx_news_thread_messages (thread_id, occurred_at),
  CONSTRAINT fk_news_thread_message_thread FOREIGN KEY (thread_id) REFERENCES news_threads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
