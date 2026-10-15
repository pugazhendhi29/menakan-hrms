-- Employee ID format: prefix "A" + 3 digits (A001, A002 ... A999, then A1000).
-- Existing employees keep their current codes; only new codes use the new format.
-- To restart numbering on an EMPTY database, also run:  UPDATE employee_code_seq SET next_value = 1 WHERE id = 1;
ALTER TABLE employee_code_seq
  ADD COLUMN pad_length TINYINT UNSIGNED NOT NULL DEFAULT 3 COMMENT 'Digits after the prefix: A001' AFTER next_value,
  ALTER COLUMN prefix SET DEFAULT 'A';
UPDATE employee_code_seq SET prefix = 'A', pad_length = 3 WHERE id = 1;