# Fix email confirmation links (localhost / “site can’t be reached”)

If the confirmation email opens **`localhost:3000`** or **`localhost`** on your phone, the link cannot work on mobile. Fix **Supabase** and your **environment variables**, then register again (or resend confirmation).

## 1. Supabase Dashboard (required)

Open [Supabase](https://supabase.com/dashboard) → your project → **Authentication** → **URL Configuration**.

| Setting | Set to |
|---------|--------|
| **Site URL** | `https://insurancetrackingsystem.netlify.app` |
| **Redirect URLs** | `https://insurancetrackingsystem.netlify.app/auth/email-confirmed.html` |

**Remove** `http://localhost:3000` from Site URL and Redirect URLs unless you only test on your PC.

Optional for XAMPP on your PC:

`http://localhost/InsuranceTrackingSystem/auth/email-confirmed.html`

Click **Save**.

## 2. Environment variables

### Local `.env` (XAMPP)

```env
APP_URL=http://localhost/InsuranceTrackingSystem
PUBLIC_APP_URL=https://insurancetrackingsystem.netlify.app
```

`PUBLIC_APP_URL` is the link put in confirmation emails so phones open your **live** site.

### Netlify

In **Site settings → Environment variables**, set:

- `APP_URL` = `https://insurancetrackingsystem.netlify.app`
- `PUBLIC_APP_URL` = same (recommended)

Redeploy after changing variables.

## 3. Success page

After a valid link, users should see:

**https://insurancetrackingsystem.netlify.app/auth/email-confirmed.html**

Message: **“You can now Login to the System.”**

## 4. Test again

1. Apply Supabase settings above.
2. Deploy / restart Apache if you changed `.env`.
3. **Register a new account** (old emails may still point at localhost).
4. On your phone, open the new email and confirm.

If it still fails, check the link in the email — the host must be `insurancetrackingsystem.netlify.app`, not `localhost`.
