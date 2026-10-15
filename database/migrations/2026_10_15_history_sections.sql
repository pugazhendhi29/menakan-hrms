-- Work experience: location, professional reference (name, email, mobile with country code),
-- and "currently works here" (to_date optional). Education: education level dropdown.
SET NAMES utf8mb4;

ALTER TABLE employee_work_experience
  MODIFY COLUMN to_date DATE NULL COMMENT 'NULL while the person still works there',
  ADD COLUMN is_current      TINYINT(1)   NOT NULL DEFAULT 0 AFTER to_date,
  ADD COLUMN location        VARCHAR(120) NULL AFTER is_current,
  ADD COLUMN reference_name  VARCHAR(120) NULL AFTER location,
  ADD COLUMN reference_email VARCHAR(190) NULL AFTER reference_name,
  ADD COLUMN reference_phone VARCHAR(30)  NULL COMMENT 'International format, e.g. +44 7700900123' AFTER reference_email;

ALTER TABLE employee_education
  MODIFY COLUMN qualification VARCHAR(120) NULL COMMENT 'Legacy free-text qualification',
  ADD COLUMN education_level VARCHAR(40) NULL AFTER institute_name;

UPDATE employee_education SET education_level = 'Other' WHERE education_level IS NULL;
