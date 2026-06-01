# Student Insurance Tracking System

Web-based platform for students to manage beneficiary and insurance records, with an administrator dashboard for centralized tracking.

**Stack:** HTML, CSS, JavaScript, PHP (MVC), Supabase PostgreSQL + Auth. UI uses the EVSU red/cream theme (`styles.css`).

## Features

- Student & admin registration and login (Supabase Auth)
- Role-based dashboards with beneficiary CRUD
- Notifications, audit logs, login history
- Account lockout after failed logins
- CSRF protection, input sanitization, secure sessions
- Row Level Security (RLS) on Supabase

## Folder Structure

```
InsuranceTrackingSystem/
├── api/index.php              # API entry point
├── app/                       # PHP MVC application
│   ├── Config/
│   ├── Controllers/
│   ├── Middleware/
│   ├── Services/
│   └── Helpers/
├── assets/css|js/             # Frontend assets
├── db/supabase_schema.sql     # Database migration
├── public pages (index, login, register, dashboards)
├── .env.example
└── README.md
```

## Setup

### 1. Supabase project

1. Create a project at [supabase.com](https://supabase.com).
2. Open **SQL Editor** and run `db/supabase_schema.sql`.
   - For an existing database, run `db/superadmin_migration.sql` once.
3. In **Authentication > Providers**, enable **Email** sign-in.
4. Copy **Project URL**, **anon key**, and **service_role key** from **Settings > API**.
5. Register your first account, then promote it in SQL Editor:
   `UPDATE public.users SET role = 'superadmin' WHERE email = 'your-email@example.com';`

### 2. Environment

```bash
cp .env.example .env
```

Edit `.env` with your Supabase credentials and mail settings.

### Google sign-in (“Continue with Google”)

This app uses **direct Google OAuth** (you do **not** need to enable Google under Supabase Auth).

1. In [Google Cloud Console](https://console.cloud.google.com/) → **APIs & Services** → **Credentials**, create an **OAuth 2.0 Client ID** (Web application).
2. Under **Authorized redirect URIs**, add:
   `http://localhost/InsuranceTrackingSystem/auth/google-callback.php`  
   (Use your real `APP_URL` path if different.)
3. Copy the **Client ID** and **Client secret** into `.env`:
   ```
   GOOGLE_CLIENT_ID=your-client-id.apps.googleusercontent.com
   GOOGLE_CLIENT_SECRET=your-client-secret
   ```
4. Restart Apache if needed, then try **Continue with Google** on the login page.

Existing accounts sign in when the Google email matches their registered email.

### Forgot password (6-digit email code)

1. Run `db/password_reset_codes_migration.sql` in the Supabase SQL Editor (once).
2. Ensure `MAIL_HOST`, `MAIL_USERNAME`, and `MAIL_PASSWORD` in `.env` are set (Gmail App Password works).
3. Users: **Forgot password?** → email → 6-digit code → verify → new password.

### 3. PHP (XAMPP)

1. Place this folder under `htdocs` (e.g. `c:\xampp\htdocs\InsuranceTrackingSystem`).
2. Enable `mod_rewrite` in Apache.
3. PHP 8.0+ with `curl` and `json` extensions enabled.
4. Visit: `http://localhost/InsuranceTrackingSystem/`

### 4. Composer (optional)

No Composer required; the app uses plain PHP.

## Deployment

- **Web server:** Apache or Nginx with PHP-FPM
- **Document root:** project root (or `public/` if you relocate static files)
- Set `COOKIE_SECURE=true` and serve over **HTTPS**
- Set `APP_ENV=production` and `APP_DEBUG=false`
- Never commit `.env` or expose `SUPABASE_SERVICE_ROLE_KEY` to the browser

### Apache

Ensure `.htaccess` routes `/api/*` to `api/index.php`.

### Nginx (example)

```nginx
location /api {
    try_files $uri /api/index.php?$query_string;
}
```

## API Overview

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/auth/register` | Register user |
| POST | `/api/auth/login` | Login |
| POST | `/api/auth/logout` | Logout |
| GET | `/api/auth/me` | Current user |
| GET/PUT | `/api/beneficiary` | Student beneficiary |
| GET | `/api/admin/stats` | Dashboard statistics |
| GET | `/api/admin/students` | Student records (paginated) |
| GET | `/api/admin/logs/*` | Audit & login logs |
| GET/POST | `/api/notifications` | Notifications |

All mutating requests require `X-CSRF-Token` header (from `/api/csrf`).

## Security Notes

- Passwords are handled by Supabase Auth (bcrypt).
- PHP sessions expire after 15 minutes of inactivity.
- HttpOnly, Secure, SameSite cookies when configured.
- Use HTTPS in production.

## License

Educational / institutional use.
