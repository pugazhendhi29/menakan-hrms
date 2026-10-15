-- Replace the document categories with Menakan's list, shown in this order in every dropdown.
-- Right to work, contract and P-45 keep their records (and the "required for everyone" rules);
-- old categories are deleted when unused, otherwise deactivated so existing documents keep their category.
SET NAMES utf8mb4;

ALTER TABLE document_categories ADD COLUMN sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100 AFTER description;

UPDATE document_categories SET name = 'RIGHT TO WORK', has_expiry = 0, is_sensitive = 1, is_active = 1 WHERE code = 'RTW';
UPDATE document_categories SET name = 'JOB CONTRACT LETTER', has_expiry = 0, is_active = 1 WHERE code = 'CONTRACT';
UPDATE document_categories SET name = 'P-45 LETTER', has_expiry = 0, is_sensitive = 1, is_active = 1 WHERE code = 'P45';

DELETE FROM document_categories
 WHERE code NOT IN ('EDU','EVISA','BRP','RTW','PASSPORT','DBS','OFFER','CONTRACT','APPRAISAL','P45','RESUME','P60','HEALTH')
   AND id NOT IN (SELECT category_id FROM (SELECT DISTINCT category_id FROM employee_documents) used);
UPDATE document_categories SET is_active = 0
 WHERE code NOT IN ('EDU','EVISA','BRP','RTW','PASSPORT','DBS','OFFER','CONTRACT','APPRAISAL','P45','RESUME','P60','HEALTH');

INSERT INTO document_categories (code, name, has_expiry, is_sensitive, visible_to_employee, description, sort_order, is_active) VALUES
 ('EDU',       'EDUCATIONAL DOCUMENTS',   0, 0, 1, 'Certificates and transcripts', 1, 1),
 ('EVISA',     'e-VISA',                  1, 1, 1, 'UKVI eVisa record', 2, 1),
 ('BRP',       'BRP',                     1, 1, 1, 'Biometric residence permit', 3, 1),
 ('RTW',       'RIGHT TO WORK',           0, 1, 1, 'Right to work check (share code or document check)', 4, 1),
 ('PASSPORT',  'PASSPORT',                1, 1, 1, NULL, 5, 1),
 ('DBS',       'DBS',                     0, 1, 1, 'Disclosure and Barring Service certificate', 6, 1),
 ('OFFER',     'OFFER LETTER',            0, 0, 1, NULL, 7, 1),
 ('CONTRACT',  'JOB CONTRACT LETTER',     0, 0, 1, NULL, 8, 1),
 ('APPRAISAL', 'APPRAISAL LETTER',        0, 0, 1, NULL, 9, 1),
 ('P45',       'P-45 LETTER',             0, 1, 1, NULL, 10, 1),
 ('RESUME',    'RESUME',                  0, 0, 1, 'CV', 11, 1),
 ('P60',       'P-60 LETTER',             0, 1, 1, NULL, 12, 1),
 ('HEALTH',    'HEALTH DECLARATION FORM', 0, 1, 1, NULL, 13, 1)
ON DUPLICATE KEY UPDATE name = VALUES(name), sort_order = VALUES(sort_order), is_active = 1, description = VALUES(description);
