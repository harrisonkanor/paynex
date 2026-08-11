# payNex — PHP + MySQL Backend

This is the original payNex landing page rebuilt as a working PHP application
with a full MySQL-backed system: user accounts, a task marketplace, wallets,
withdrawals, and an admin panel. The visual design is unchanged from the
original HTML/CSS/JS — it has simply been split into reusable PHP
files and wired to a real database.

## Requirements
- PHP 8.0+ with the **pdo_mysql** and **mbstring** extensions (both are enabled
  by default on almost every host and in XAMPP/MAMP/WAMP)
- MySQL or MariaDB 5.7+/10.3+

## Setup

1. **Create the database and import the schema:**
   ```
   mysql -u root -p -e "CREATE DATABASE paynex CHARACTER SET utf8mb4;"
   mysql -u root -p paynex < database/schema.sql
   ```
   This also seeds one default admin account:
   - Email: `admin@paynex.local`
   - Password: `ChangeMe123!`
   **Change this password immediately after your first login** (there's no
   in-app "change password" screen yet — update it directly in the database
   with `password_hash()` if needed, or add one).

2. **Set your database credentials** in `config/db.php`:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'paynex');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   ```

3. **Point your web server** (Apache/Nginx/PHP's built-in server) at this
   folder. For quick local testing:
   ```
   php -S localhost:8000
   ```
   then visit `http://localhost:8000/index.php`.

4. If you deploy the app in a sub-folder (e.g. `example.com/paynex/`), set
   `BASE_URL` in `config/config.php` to `/paynex` so links resolve correctly.

## What's included

- **Public site:** `index.php` — the original marketing page, with the three
  stat counters now pulled live from the database instead of hardcoded demo
  numbers.
- **Auth:** `signup.php`, `login.php`, `logout.php` — password hashing
  (bcrypt via `password_hash`), CSRF tokens on every form, and basic
  rate-limiting on login attempts (5 attempts / 15 minutes per email).
- **Earner flow:** `dashboard.php` (browse open tasks, view submissions),
  `task.php` (view a task and submit proof), `withdraw.php` (request a
  payout and see withdrawal history).
- **Creator flow:** `creator-dashboard.php`, `post-task.php` (create a task),
  `my-tasks.php` + `review-submission.php` (approve/reject submissions —
  approving automatically credits the earner's wallet).
- **Admin panel:** `admin/` — overview stats, user management (suspend /
  reactivate), withdrawal processing (approve → mark paid, or reject with
  automatic refund), and a full activity log.
- **Shared code:** `config/` (DB connection + bootstrap), `includes/`
  (header/footer templates and helper functions), `assets/` (the original
  CSS/JS/logo, plus `app.css` — an additive stylesheet for the new
  functional pages that reuses the original design tokens).

## Security measures implemented
- All database queries use **PDO prepared statements** (no string-concatenated SQL).
- All output is escaped with `htmlspecialchars()` via the `e()` helper (XSS protection).
- Every state-changing form includes a **CSRF token**, verified server-side.
- Passwords are hashed with `password_hash()` / verified with `password_verify()`.
- Sessions use `httponly` + `SameSite=Lax` cookies and are regenerated on login.
- Login attempts are rate-limited per email address.
- Every significant action (signup, login, logout, task creation, submission
  review, withdrawal request/approval, admin actions) is written to
  `activity_logs` for an auditable trail.

## Notes / things you'll likely want to add next
- Email verification on signup (the security section of the original design
  mentions it; the schema and forms are ready for it, but sending real email
  requires an SMTP/mail provider, which wasn't configured here).
- A "forgot password" flow.
- File upload support for task proof (currently proof is submitted as text).
- Pagination on the earner/creator task lists once volume grows.
