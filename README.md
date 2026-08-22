# RMC Events — Campus Event Management System

A full-featured, server-rendered web application for managing campus events at **Regis Marie College**. Built with plain PHP, PostgreSQL, and Tailwind CSS.

## Features

### For Students
- Browse and register for approved campus events
- QR code-based check-in for attendance
- Calendar view of upcoming events
- Post-attendance feedback and ratings
- Activity history tracking

### For Organizers
- Create, edit, and cancel events with poster uploads
- Scan attendance via webcam QR scanner or manual entry
- Manage event photo galleries
- View per-event analytics and reports

### For Admins
- Approve, reject, archive, or soft-delete events
- Manage all user accounts (students, organizers, admins)
- Two-factor authentication (TOTP) support
- System-wide analytics and email logs
- Audit trail for all admin actions

### System-Wide
- Multi-language support (7 languages: EN, FIL, ES, FR, JA, KO, ZH)
- Dark mode with system/light/dark toggle
- In-app notifications and email alerts
- Automated event reminders (24h and 1h before)
- Rate limiting and brute-force protection
- Mobile-responsive design

## Tech Stack

| Layer       | Technology                  |
|-------------|-----------------------------|
| Server      | Apache (XAMPP)              |
| Backend     | PHP 8.x                    |
| Database    | PostgreSQL                  |
| CSS         | Tailwind CSS (CDN)          |
| Icons       | Font Awesome 6.6 (CDN)     |
| QR Code     | qrcodejs / html5-qrcode    |
| Email       | PHPMailer (SMTP/TLS)       |
| 2FA         | Pure-PHP TOTP (RFC 6238)   |

## Prerequisites

- [XAMPP](https://www.apachefriends.org/) (Apache + PHP 8.x)
- [PostgreSQL](https://www.postgresql.org/) (any recent version)
- A Gmail account or other SMTP provider for email features (optional)

## Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/JhonCarlBaculinao/campus-event-system-.git
   cd campus-event-system-
   ```

2. **Create the PostgreSQL database**
   ```sql
   CREATE DATABASE campus_event_db;
   ```

3. **Import the database schema**
   ```bash
   psql -U postgres -d campus_event_db -f campus_event_db.sql
   ```

4. **Configure database credentials**
   Edit `db_connect.php` or create `C:\xampp\rmc_config.php`:
   ```php
   <?php
   return [
       'db_host' => '127.0.0.1',
       'db_port' => '5432',
       'db_name' => 'campus_event_db',
       'db_user' => 'postgres',
       'db_pass' => 'your_password',
       'hmac_key' => 'your-random-secret-key',
   ];
   ```

5. **Configure SMTP (optional)**
   Create `C:\xampp\email_config.php`:
   ```php
   <?php
   return [
       'host'       => 'smtp.gmail.com',
       'port'       => 587,
       'encryption' => 'tls',
       'username'   => 'your_email@gmail.com',
       'password'   => 'your_app_password',
       'from_email' => 'your_email@gmail.com',
       'from_name'  => 'Regis Marie College Event System',
   ];
   ```

6. **Start services**
   - Start **Apache** from XAMPP Control Panel
   - Start **PostgreSQL** service

7. **Access the application**
   ```
   http://127.0.0.1/campus_event_system/
   ```

## Test Credentials

| Role      | Student ID      | Password            |
|-----------|-----------------|---------------------|
| Admin     | `admin_only`    | `AdminRMC2026!`     |
| Organizer | `org_123`       | `OrganizerRMC2026!` |
| Student   | `2026-ANAL-01`  | `Analytics2026!pass`|

## Cron Job (Event Reminders)

To enable automated reminders, run the cron job every 5–15 minutes:

**Windows (Task Scheduler):**
```
php C:\xampp\htdocs\campus_event_system\cron\send_reminders.php
```

**Linux (crontab):**
```
*/5 * * * * /usr/bin/php /path/to/campus_event_system/cron/send_reminders.php
```

## Project Structure

```
campus_event_system/
├── partials/           # Shared partials (header, sidebar, footer, head)
├── js/                 # JavaScript files
├── img/                # Uploaded images and logos
├── cron/               # Scheduled tasks
├── PHPMailer/          # Vendored PHPMailer library
├── login.php           # Login page
├── register.php        # Student registration
├── dashboard.php       # Role-aware dashboard
├── db_connect.php      # Database connection
├── auth.php            # Authentication helpers
├── csrf.php            # CSRF protection
├── lang.php            # Multi-language translations
├── totp.php            # TOTP 2FA implementation
└── ...                 # Additional pages
```

## Security

- **SQL Injection:** All queries use `pg_query_params()` prepared statements
- **XSS:** All output escaped with `htmlspecialchars()`
- **CSRF:** Token verification on all POST handlers
- **Passwords:** bcrypt hashing via `password_hash()` / `password_verify()`
- **Rate Limiting:** IP-based login throttling + per-user lockout
- **Sessions:** Regenerated on login, HttpOnly + SameSite=Lax, 30-min idle timeout, single-device enforcement

## License

This project is licensed under the MIT License — see the [LICENSE](LICENSE) file for details.

## Contributing

Contributions are welcome! Please read the [CONTRIBUTING](CONTRIBUTING.md) guidelines before submitting a pull request.

## Author

**Jhon Carl Baculinao** — [GitHub](https://github.com/JhonCarlBaculinao)
