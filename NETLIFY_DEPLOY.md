# Deploy to Netlify

This project runs on **Netlify** with:
- **Static files** — HTML, CSS, JS (site root)
- **Netlify Functions** — Node.js API (replaces PHP `api/index.php`)
- **Supabase** — database (unchanged)

## 1. Push to GitHub

Do **not** commit `.env`. Secrets go in Netlify only.

## 2. Connect Netlify

1. [app.netlify.com](https://app.netlify.com) → **Add new site** → **Import from Git**
2. Build settings (auto from `netlify.toml`):
   - **Build command:** `npm install`
   - **Publish directory:** `.` (project root)
   - **Functions directory:** `netlify/functions`

## 3. Environment variables

In **Site settings → Environment variables**, add:

| Variable | Description |
|----------|-------------|
| `SUPABASE_URL` | Supabase project URL |
| `SUPABASE_ANON_KEY` | Anon public key |
| `SUPABASE_SERVICE_ROLE_KEY` | Service role (secret) |
| `APP_URL` | `https://YOUR-SITE.netlify.app` (no trailing slash) |
| `URL` | Same as `APP_URL` (Netlify sets `URL` on deploy — you can rely on that) |
| `SESSION_SECRET` | Random 32+ char string (cookie signing) |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `MAIL_HOST` | `smtp.gmail.com` |
| `MAIL_PORT` | `587` |
| `MAIL_USERNAME` | Gmail address |
| `MAIL_PASSWORD` | Gmail App Password |
| `MAIL_FROM_ADDRESS` | Same Gmail |
| `MAIL_FROM_NAME` | `Insurance Tracking System` |
| `MAIL_ENCRYPTION` | `tls` |
| `GOOGLE_CLIENT_ID` | Optional — Google OAuth |
| `GOOGLE_CLIENT_SECRET` | Optional |
| `COOKIE_SECURE` | `true` |
| `COOKIE_SAMESITE` | `Lax` |

## 4. Supabase Auth URLs (email confirmation)

In **Supabase Dashboard → Authentication → URL Configuration**:

| Setting | Value |
|---------|--------|
| **Site URL** | `https://YOUR-SITE.netlify.app` (not `localhost`) |
| **Redirect URLs** | `https://YOUR-SITE.netlify.app/auth/email-confirmed.html` |

For local XAMPP testing, also add:

`http://localhost/InsuranceTrackingSystem/auth/email-confirmed.html`

Set **`APP_URL`** in Netlify (and `.env` locally) to the same base URL with **no trailing slash**. New signups get confirmation links to `/auth/email-confirmed.html`, which shows “You can now log in to the system.”

## 5. Supabase SQL

Run once in Supabase SQL Editor:

- `db/supabase_schema.sql`
- `db/superadmin_migration.sql`
- `db/beneficiary_update_request_status_migration.sql`
- `db/password_reset_codes_migration.sql`

## 6. Google OAuth (optional)

**Authorized redirect URI:**

```text
https://YOUR-SITE.netlify.app/auth/google-callback.php
```

(Netlify redirects that path to the Google callback function.)

## 7. Promote superadmin

1. Register on the live site.
2. Supabase SQL:

```sql
UPDATE public.users SET role = 'superadmin' WHERE email = 'your@email.com';
```

3. Open: `https://YOUR-SITE.netlify.app/superadmin/dashboard.html`

## 8. Test locally with Netlify Dev

```bash
npm install
npx netlify dev
```

Open the URL shown (usually `http://localhost:8888`).

## Local XAMPP (PHP) still works

If the URL contains `/InsuranceTrackingSystem`, the frontend uses PHP `api/index.php` automatically.

## API endpoints

Same routes as before, e.g.:

- `/.netlify/functions/api?route=/csrf`
- `/.netlify/functions/api?route=/auth/login`
