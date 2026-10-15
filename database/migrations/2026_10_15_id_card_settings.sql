-- Virtual ID card: expiry date and the company details printed on the card (editable in Settings).
INSERT IGNORE INTO system_settings (`key`, `value`, type, label, description) VALUES
 ('id_card_expiry_date', '2026-12-31', 'string', 'ID card expiry date (YYYY-MM-DD)', 'Printed on every virtual ID card'),
 ('company_website', 'www.menakantransport.com', 'string', 'Company website', 'Shown on the ID card'),
 ('company_address', 'Unit 11, 58 Peregrine Rd, Essex IG6 3SZ', 'string', 'Company address', 'Shown on the ID card');