# Menakan HRMS

HR management system for Menakan Transport Ltd: employees, documents, attendance, shifts, leave, payslips, users, reports and audit, with a read-only self-service portal for staff.

Plain PHP 8.2 (small MVC, no framework), MySQL 8 / MariaDB 10.4, Bootstrap 5.3, PhpSpreadsheet. Built and tested on XAMPP for Windows.

## Requirements

- PHP 8.2+ with `pdo_mysql`, `mbstring`, `fileinfo`, `zip`, `gd`, `intl`, `openssl` (all bundled with XAMPP; enable `zip`, `gd` and `intl` in `php.ini` if they are commented out)
- MariaDB 10.4+ or MySQL 8.0.16+
- Apache 2.4 with `mod_rewrite`
- Composer 2 (only needed if `vendor/` is missing)

## Install on XAMPP (Windows)

1. Copy the project folder somewhere outside `htdocs`, e.g. `C:\Projects\menakan-hrms`. Only `public/` is ever served.
2. Install PHP dependencies (skip if `vendor/` is present):
   ```
   C:\xampp\php\php.exe C:\xampp\php\composer.phar install --no-dev
   ```
3. Create the database and a dedicated user (in a MySQL shell as root):
   ```sql
   CREATE DATABASE menakan_hrms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'menakan_app'@'localhost' IDENTIFIED BY 'choose-a-strong-password';
   GRANT SELECT, INSERT, UPDATE, DELETE ON menakan_hrms.* TO 'menakan_app'@'localhost';
   ```
4. Load the schema, then the reference data:
   ```
   C:\xampp\mysql\bin\mysql.exe -uroot menakan_hrms < database\database.sql
   C:\xampp\mysql\bin\mysql.exe -uroot menakan_hrms < database\seed.sql
   ```
   `seed.sql` holds only generic setup lists (work patterns, shift types, leave types, document categories, England & Wales bank holidays); there are no people or demo records. Before adding the first employee, create your departments, job titles and locations in **Settings**.
5. Copy `.env.example` to `.env` and set `DB_PASSWORD`, `APP_URL` and `APP_BASE_PATH`.
6. Point Apache at `public/`. Create `C:\xampp\apache\conf\extra\httpd-menakan-hrms.conf`:
   ```apache
   Alias /menakan-hrms "C:/Projects/menakan-hrms/public"
   <Directory "C:/Projects/menakan-hrms/public">
       Options -Indexes +FollowSymLinks
       AllowOverride All
       Require all granted
   </Directory>
   ```
   then add `Include conf/extra/httpd-menakan-hrms.conf` to the end of `httpd.conf` and restart Apache. If you mount the app on another path, change `RewriteBase` in `public/.htaccess` to match `APP_BASE_PATH`.
7. Create the first administrator (the password is typed at the prompt, never stored in code):
   ```
   C:\xampp\php\php.exe bin\create-admin.php --username=admin --email=hr@yourcompany.co.uk
   ```
   Add `--employee-code=A001` to link the admin to their own employee record (gives them the "My portal" pages too).
8. Open `http://localhost:8080/menakan-hrms` (or your Apache port) and sign in.

### Scheduled job

Create a daily Windows Task Scheduler task (e.g. 07:00) that runs:
```
C:\xampp\php\php.exe C:\Projects\menakan-hrms\bin\send-expiry-reminders.php
```
It notifies admins and employees about documents expiring within the warning period (Settings) or already expired. Running it twice in a day does not send duplicates.

Add a second daily task (or put it in the same one) for attendance from the rota:
```
C:
mpp\php\php.exe C:\Projects\menakan-hrms\binill-attendance-from-rota.php
```
Every published shift that has ended, from the employee's start date up to 31/10/2026 (AttendanceService::ROTA_UNTIL), gets an attendance record with the shift's times, shown as "From rota". Days with approved leave and shifts that already have real attendance (web check-in, import, manual) are skipped. The record follows its shift when the shift is changed or cancelled. Rota attendance is locked: an admin can only correct or remove it on a day the employee is on approved leave. The Attendance page also fills in shifts that have ended since the last visit (migration 2026_10_27_attendance_from_rota.sql).

### Email

Uploading a payslip emails the PDF to the address in the employee's Basic information. `MAIL_DRIVER=log` (the default) writes every message - payslip emails with their attachment names, activation and reset links, expiry summaries - to `storage/logs/mail.log` instead of sending it. To deliver real email set `MAIL_DRIVER=smtp` and the `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_ENCRYPTION` and `MAIL_FROM` values in `.env` (sent with PHPMailer). Whether each payslip email was sent is shown in the Emailed column of the payslip list.

## Upgrading an existing install

Apply any new files in `database/migrations/` in date order, e.g.:
```nC:\xampp\mysql\bin\mysql.exe -uroot menakan_hrms < database\migrations\2026_10_15_employee_profile.sql
```

## Shared hosting / production

- Upload everything; make the web root point at `public/` (or upload `public/` contents to `public_html` and keep the rest one level above it, adjusting the two `require` paths in `public/index.php`).
- Set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`, `APP_BASE_PATH=` (empty for a domain root) and `RewriteBase /` in `public/.htaccess`.
- Serve over HTTPS: the session cookie is marked `Secure` automatically on HTTPS.
- `storage/` must be writable by PHP and must not be web-accessible.
- Back up the database and `storage/documents` + `storage/payslips` together.

## Running the tests

```
C:\xampp\php\php.exe vendor\bin\phpunit                # 185 unit + feature tests (uses database menakan_hrms_test)
C:\xampp\php\php.exe tests\e2e\run.php                 # 102 end-to-end HTTP scenarios (set MAIL_DRIVER=log first) against the running site
```
The feature tests rebuild `menakan_hrms_test` from `database/database.sql` (create it first and grant the app user access). The E2E suite needs the fictional demo data loaded with `php tests/fixtures/demo-seed.php` (on a copy or test install only, never on live data) and `E2E_ADMIN_USER`, `E2E_ADMIN_PASSWORD` and `E2E_DEMO_PASSWORD` environment variables; it adds its own records, so reload the demo afterwards. Results are written to `storage/logs/e2e-results.json`.

## Project layout

```
app/Core         Router, Request, Response, View, DB (PDO), Session, Auth, Validator, Kernel
app/Middleware   Authenticate (idle timeout, revoked sessions), RequireRole, RequireEmployee, RedirectIfAuthenticated
app/Controllers  One per module; thin, business rules live in Services
app/Services     HoursCalculator, WeekBoundary, WorkingTimeChecker, LeaveDayCounter, LeaveService, ShiftService,
                 AttendanceService, *ImportService, ExcelIO, PayslipMatcher, PayslipService, DocumentService,
                 FileStore, AuditLogger, Notifier, Mailer, Settings, LoginThrottle, PasswordTokens
app/Views        PHP templates (Bootstrap 5.3), layouts/, partials/, one folder per module
bin/             create-admin.php, send-expiry-reminders.php, make-templates.php
database/        database.sql (33 tables), seed.sql (starting setup lists, no people), migrations/ (upgrades for existing installs)
public/          index.php front controller, .htaccess, assets (Bootstrap, icons and Chart.js self-hosted)
routes/web.php   Every route and the middleware it needs
storage/         Private: documents/, payslips/, imports/, logs/, sessions/ (denied to the web)
templates/       Attendance_Import_Template.xlsx, Shift_Import_Template.xlsx
tests/           Unit/, Feature/ (PHPUnit), e2e/ (HTTP suite), fixtures/ (fictional demo data for the E2E suite only)
```

## Key rules (summary)

- Times are stored in UTC and shown in UK time, so shifts over the clock changes count their real length.
- Weeks run Monday–Sunday (UK). A shift counts in the week it starts.
- Weekly limit: employee override → contract hours → company setting (37.5h). Going over needs an approval with a reason. Working Time checks (48h average over 17 weeks, 11h daily rest) are shown as warnings.
- Leave: the leave year runs 1 January to 31 December. Annual leave is 28 days a year for a 5-day worker, pro rata for fewer working days (28 x days a week / 5, rounded up to the half day) and for joiners and leavers; the whole year's entitlement is available from 1 January (or the start date). Bank holidays are given on top of the 28 days and are not counted when leave is booked. These rules are fixed (migration 2026_10_27_leave_rules.sql). Balances come from a ledger (not shown on screen).
- Re-importing updates what is already there. Shift import: a row for an employee and date that already has a shift updates that shift (times, break, type, location, status, notes) instead of reporting an overlap; identical rows are skipped. Attendance import: a row for an employee and work date that already has a record updates it (check in, check out, break, notes; each change is kept in the record's corrections) automatically; approved records are never overwritten. Employee import: an existing Employee ID updates that employee.
- Payslips are drafts until published; re-uploads archive the previous file; matching by filename is always confirmed by an admin.
- P60s (/p60): one current P60 per employee per UK tax year (6 April - 5 April), assigned by Employee ID, only for tax years that have ended. Duplicates are refused unless the admin ticks Replace (the old file is archived). Deleting needs a reason and keeps the record. Uploads, replacements, views, downloads and deletions are audited. Employees see only their own current P60s (/my/p60). Each uploaded or replaced P60 is emailed as a PDF attachment, from hr@menakantransport.com with the HR signature, to the email address in the employee's Basic information; the result shows in the Emailed column (migration 2026_10_15_p60_email.sql).
- ID cards (employee page > ID card tab): admins create, edit (issue/expiry date, Active or Suspended, notes), regenerate (new card number such as A001-02; the old card is archived with a reason) and revoke cards. The card shows the employee photo, full name, Employee ID, designation, department and company branding, taken live from the employee record. Employees see only their own current card at /my/id-card, view only: no edit, print or download controls, printing is blocked and the photo cannot be dragged or right-click saved (a screenshot cannot be prevented by any website). Creating, changing, regenerating, revoking and employee views are audited (migration 2026_10_15_id_cards.sql, which issues card 01 to every current employee).
- News (/news): admins write announcements (from a template or blank), save drafts, schedule them or send now to all active employees, selected employees, departments or teams (a reporting manager and their direct reports). Every News email is sent from the fixed sender Menakan Transport HR Department <hr@menakantransport.com>, which cannot be changed in the HRMS, through the SMTP server in .env. Each employee gets their own copy, so addresses stay private. Before every send the SMTP server is asked to accept the sender; a refused sign-in or sender is shown to the admin, logged to storage/logs/news-mail.log and the audit log, and nothing is marked as sent. Each announcement has a per-recipient delivery log with Retry failed. Employees read the announcements sent to them at /my/news. Scheduled sends need a Windows Task Scheduler job every 5 minutes running bin\send-scheduled-news.php (migration 2026_10_15_news.sql). Every module that sends email, and its sender, is listed in docs/EMAIL-SENDERS.md.
- News threads (/news/threads, News > Threads): admins record conversations that already took place (email, chat, meeting, phone, letter) and publish them. Every employee who sent a message, and everyone who received one (the employees named, the current members of the receiving departments, or everyone), sees the thread in My News and gets a notification when it is published; the admin can add extra readers (all active employees, selected employees, departments or teams). For each message the admin chooses the sender (a department or an employee), the receivers (everyone, departments or employees) and the original date and time (UK time, not in the future); messages are shown in date order. Nothing is emailed. The real time each message was recorded and the thread published, and by whom, is stored, shown to admins and noted to employees ("dates as recorded by HR"), and audited. Drafts can be edited or deleted; published threads can be withdrawn with a reason (hidden from employees, kept for the record). News > Threads > New email writes one email (From, To, Date, Subject, Message) and publishes it straight away; the sender's signature (name and job title, or the department name) is added under every new message. Published messages are never changed, but replies can be added. Every employee can also start a thread from My News (to colleagues or departments, not the whole company) and reply to any thread they can read; they are always the sender and their messages get the time they were sent: only admins choose the sender and the date and time. Admin-dated threads show employees a short note that the times are as recorded by HR. Employees read the threads shared with them at /my/news (migration 2026_10_26_news_threads.sql).
- Employee statuses (Settings > Employee statuses): admins add, rename, recolour, reorder and switch off statuses, and choose whether each one counts as working (the dashboard's active headcount). Active, Probation and Left are built in: they keep their code and cannot be switched off (Left is what removes someone from rotas, News and imports). A switched-off status is no longer offered, but employees who have it keep it. The headcount report has a column per status (migration 2026_10_27_employee_statuses.sql).
- Employees only ever see their own data; every query in the portal is scoped by the signed-in user's employee ID. My Profile is read only: employees cannot change any part of their record (including work experience and education); HR updates it on the employee record.
- Employee export and import (Employees page > Export / Import): the export holds every employee field (47 columns, including identity and pay data; exports are audited) for the employees in the list as filtered, plus Work Experience and Education sheets for reference. The import reads the Employees sheet in the same layout, with Employee ID as the key: an existing Employee ID updates that employee (only filled-in cells change; type CLEAR to empty a field; columns left out are untouched), an empty Employee ID adds a new employee with the next ID, and a new ID in the same format (e.g. A025) adds a new employee with that ID and moves the sequence past it. Every row goes through the employee form's validation; the preview shows each new employee and each change, nothing is saved until confirmed, and an error report lists the rows to fix (migration 2026_10_15_employee_import.sql).
- My Email (`/my-email`, sidebar after the pay items, for everyone signed in): opens the company webmail (WEBMAIL_URL in .env, default https://ns3-777.333servers.com:2096/) in a new tab. The webmail server sends X-Frame-Options: SAMEORIGIN, so it cannot be embedded in the HRMS. Staff sign in on the webmail's own login page; no email address or password passes through or is stored in the HRMS.
- Organisation chart PDF (Hierarchy > Export Hierarchy to PDF): a landscape PDF titled "Menakan Transport Ltd – Organizational Structure", generated on request from the current reporting managers with TCPDF (composer package tecnickcom/tcpdf). Company logo, generation date and page numbers on every page; each card shows only photo, name, designation and department, coloured by department, with connector lines between managers and their reports. Default paper is A4 when the whole chart fits on one page, otherwise A3 (both can be chosen). Large charts are split by team: a person whose team does not fit is marked "N reports · see page X" and their team continues on that page, so no card or line is ever cut. People not yet placed are listed on a final page. Same access as the chart (everyone signed in); every export is audited.
- Organisation chart (`/hierarchy`): a functional top-down hierarchy. The root is the company (Settings > Company name, Menakan Transport Ltd). Level 1 is every Director (anyone whose designation contains the word "Director"), side by side directly under the company. Everyone else sits under their reporting manager, joined by connector lines. Directors cannot be given a reporting manager (the employee form refuses it), so they never report to each other; a Director who already has one is still shown on Level 1 with a warning badge. A non-Director with no reporting manager who manages people is also placed under the company, marked "No reporting manager". When a manager's reports span several departments they are grouped under a coloured branch per department, and every card is coloured by department (legend above the chart). Wide charts scroll sideways inside the chart box and open centred on the top person; everyone signed in can view it (with colleagues' photos), only admins get links to records and "Change manager".
