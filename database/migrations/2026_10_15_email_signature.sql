-- Email signature added to every email the HRMS sends (editable in Settings).
INSERT IGNORE INTO system_settings (`key`, `value`, type, label, description) VALUES
 ('email_signature', 'Human Resource\nMenakan Transport Ltd\nUnit 11, 58 Peregrine Road,\nHainault, Essex, UK\nIG6 3SZ\nMenakan Transport Ltd | Human Resources Department', 'string', 'Email signature', 'Added to the end of every email (payslips, password links, reminders)');
