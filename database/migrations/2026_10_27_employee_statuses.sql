-- Employee statuses become a list kept in Settings > Employee statuses (add, rename, recolour, switch off).
-- Active, Probation and Left are built in. employees.status now holds a status code instead of a fixed ENUM.
SET NAMES utf8mb4;

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

ALTER TABLE employees MODIFY status VARCHAR(30) NOT NULL DEFAULT 'active' COMMENT 'employee_statuses.code (Settings > Employee statuses)';
