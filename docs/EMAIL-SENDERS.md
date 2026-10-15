# Email senders in Menakan HRMS

Last reviewed: 15/10/2026

The HRMS sends email from two kinds of code paths: the News module, whose sender is fixed in code, and the other modules, which use the default sender set in `.env`. All of them deliver through the same SMTP server and credentials in `.env`. No credential is stored in the source code, the database or the HRMS screens.

## News module (fixed sender)

| Item | Value |
| --- | --- |
| Sender address | `hr@menakantransport.com` (constant `NewsService::SENDER_EMAIL`) |
| Sender name | `Menakan Transport HR Department` (constant `NewsService::SENDER_NAME`) |
| Can it be changed in the HRMS? | No. It is not a setting, and any `from` value posted to the News forms is ignored. Changing it needs a code change. |
| Delivery | SMTP server in `.env` (`SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_ENCRYPTION`). |
| Recipient privacy | One email per employee, with only that employee in `To:`. No CC or BCC lists. |
| Sender authorisation | Before every send (and from **News > Check sender now**), the HRMS signs in to the SMTP server and asks it to accept `MAIL FROM:<hr@menakantransport.com>`, then resets the session without sending anything. If sign-in or the sender is refused, nothing is sent or marked as sent. The error is shown to the admin, written to `storage/logs/news-mail.log` and to the audit log, and every recipient is listed as failed so the send can be retried. |
| Result of the check on 15/10/2026 | Passed: `mail.menakantransport.com` signed in as `hr@menakantransport.com` and accepted it as the sender. |
| Scheduled sends | `bin/send-scheduled-news.php`. Run it every 5 minutes with Windows Task Scheduler. |
| Records | Each announcement keeps a per-recipient delivery log (sent, failed, skipped because there is no email address). The audit log records create, edit, schedule, send, failure, template changes and sender checks. |

## Other modules (default sender from `.env`)

These modules send from `MAIL_FROM` and `MAIL_FROM_NAME` in `.env`. Today those are `hr@menakantransport.com` and `Menakan Transport HR`. They were not changed by the News requirement.

| Module | What is sent | To whom | When | Code |
| --- | --- | --- | --- | --- |
| Payslips | Payslip PDF attached | The employee (email in Basic information) | When an admin uploads a payslip (single or bulk) | `PayslipService::emailToEmployee` |
| P60 | P60 PDF attached | The employee (email in Basic information) | When an admin uploads or replaces a P60 | `P60Service::emailToEmployee` |
| User accounts | One-time link to activate an account or reset a password | The portal user | When an admin creates an account or sends a reset link | `PasswordTokens::issue` |
| Document expiry reminders | Summary of documents expiring soon or already expired | Active admin users | Daily job `bin/send-expiry-reminders.php` | `bin/send-expiry-reminders.php` |
| Test email | A one-line test message | The address given on the command line | Manually: `php bin/test-mail.php you@example.com` | `bin/test-mail.php` |

All emails, in every module, end with the HR signature from **Settings > Email signature**.

To give these modules the same fixed sender as News, set `MAIL_FROM=hr@menakantransport.com` and `MAIL_FROM_NAME="Menakan Transport HR Department"` in `.env`. Only the News module checks sender authorisation before sending. When the SMTP server refuses a payslip or P60 email, the upload is still saved, the failure is shown to the admin and recorded on that payslip or P60.

## Test mode

When `MAIL_DRIVER` is not `smtp`, or `SMTP_HOST` or `SMTP_PASSWORD` is empty, no email is delivered. Every message is written to `storage/logs/mail.log` instead, and the screens say "test mode". News deliveries made in test mode are recorded as `logged`, not `sent`.
