# SMTP / Email Delivery

Simple PHP IPAM sends email for utilization alerts. By default it uses PHP's native `mail()` function, which relies on your server's local MTA (Postfix, Sendmail, etc.). Starting in v3.1.0 you can configure direct SMTP delivery via PHPMailer — useful when your server has no local MTA or when you want reliable delivery via a cloud provider.

## Configuration

Settings live in **Admin → Settings → SMTP / Email Delivery**.

| Setting | Default | Description |
|---|---|---|
| `smtp.enabled` | `false` | Enable SMTP. When off, native `mail()` is used. |
| `smtp.host` | — | SMTP server hostname (e.g. `smtp.gmail.com`) |
| `smtp.port` | `587` | TCP port — 587 (STARTTLS), 465 (SSL), 25 (plain) |
| `smtp.encryption` | `starttls` | `starttls`, `ssl`, or `none` |
| `smtp.auth_user` | — | SMTP username. Leave blank for anonymous relay. |
| `smtp.auth_pass` | — | SMTP password. Stored encrypted; never logged. |
| `smtp.from_address` | — | Envelope From address (e.g. `ipam@example.com`) |
| `smtp.from_name` | — | From display name (e.g. `IPAM Alerts`) |
| `smtp.verify_peer` | `true` | Verify TLS certificate. Disable only for dev/test. |
| `smtp.timeout_seconds` | `10` | Connection timeout in seconds. |

After saving, use the **Send test email** button on the same settings card to verify delivery. The test email is sent to the logged-in admin's email address (set in Users).

## Provider examples

### Gmail (App Password)

1. Enable 2-Factor Authentication on your Google account.
2. Create an App Password: Google Account → Security → App Passwords.
3. Settings:
   - Host: `smtp.gmail.com`
   - Port: `587`
   - Encryption: `STARTTLS`
   - Username: your Gmail address
   - Password: the 16-character App Password

### Office 365 / Microsoft 365

- Host: `smtp.office365.com`
- Port: `587`
- Encryption: `STARTTLS`
- Username: your M365 address
- Password: your M365 password (or an App Password if MFA is enforced)

### AWS SES (SMTP interface)

1. Verify a sending domain or address in the SES console.
2. Create SMTP credentials: SES → SMTP Settings → Create SMTP Credentials.
3. Settings:
   - Host: `email-smtp.<region>.amazonaws.com` (e.g. `email-smtp.us-east-1.amazonaws.com`)
   - Port: `587`
   - Encryption: `STARTTLS`
   - Username / Password: from the SMTP credentials you created

### Mailgun (SMTP API)

- Host: `smtp.mailgun.org`
- Port: `587`
- Encryption: `STARTTLS`
- Username: `postmaster@<your-domain>`
- Password: your Mailgun SMTP password (from Sending → Domain Settings → SMTP Credentials)

### Postfix (local relay)

If your server runs Postfix and you want IPAM to relay through it without authentication:

- `smtp.enabled`: `true`
- Host: `127.0.0.1`
- Port: `25`
- Encryption: `none`
- Auth username / password: leave blank
- `smtp.verify_peer`: leave `true` (no TLS negotiated on plain port 25)

Alternatively, leave `smtp.enabled` as `false` and let PHP's `mail()` hand off to Postfix directly.

## Troubleshooting

**"Send failed via smtp"** — Check the audit log (Admin → Audit) for the `mail.send_failed` entry; the `error` field contains the PHPMailer exception message. Common causes:
- Wrong host/port
- TLS certificate mismatch (try disabling `smtp.verify_peer` temporarily)
- Authentication failure (wrong username/password, app-password not generated)

**Test email never arrives** — Check spam/junk. SPF, DKIM, and DMARC records for your sending domain improve deliverability.

**Using `smtp.enabled = false` but mail() doesn't work** — Check that your server has a local MTA configured. On Debian/Ubuntu: `sudo apt install postfix`. On Alpine (Docker): install and start `opensmtpd` or use SMTP mode instead.
