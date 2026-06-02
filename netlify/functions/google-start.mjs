import { getSession, newSession, touchSession, sessionHeaders } from '../lib/session.mjs';
import { buildGoogleOAuthUrl } from '../lib/auth.mjs';
import { redirect } from '../lib/response.mjs';
import { appUrl } from '../lib/env.mjs';

export async function handler(event) {
  let sess = getSession(event) || newSession();
  const built = buildGoogleOAuthUrl(sess);
  if (!built || !built.url) {
    const base = appUrl() || '';
    return redirect(`${base}/login.html?error=${encodeURIComponent('Google sign-in is not configured. Add GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET to Netlify environment variables.')}`, sessionHeaders(sess));
  }
  return redirect(built.url, sessionHeaders(built.session));
}
