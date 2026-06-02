import { getSession, newSession, sessionHeaders } from '../lib/session.mjs';
import { completeGoogleOAuth } from '../lib/auth.mjs';
import { redirect } from '../lib/response.mjs';
import { appUrl } from '../lib/env.mjs';

export async function handler(event) {
  const base = appUrl() || '';
  const q = event.queryStringParameters || {};
  const code = q.code || '';
  const state = q.state || '';
  const oauthError = q.error;

  if (oauthError) {
    return redirect(`${base}/login.html?error=${encodeURIComponent(oauthError)}`);
  }

  if (!code || !state) {
    return redirect(`${base}/login.html?error=${encodeURIComponent('Google sign-in was cancelled or incomplete.')}`);
  }

  let sess = getSession(event) || newSession();
  const result = await completeGoogleOAuth(code, state, sess);
  if (!result.success) {
    return redirect(`${base}/login.html?error=${encodeURIComponent(result.message)}`, sessionHeaders(sess));
  }

  const target = `${base}/${result.data.redirect}`;
  return redirect(target, sessionHeaders(result.session));
}
