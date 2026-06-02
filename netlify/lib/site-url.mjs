import { env } from './env.mjs';

function isLocalHost(url) {
  return /^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?/i.test(url);
}

/** Base URL for email links — must work on mobile (not localhost). */
export function publicAppBase() {
  const pub = env('PUBLIC_APP_URL', '').replace(/\/$/, '');
  if (pub) return pub;

  const url = env('URL', env('APP_URL', '')).replace(/\/$/, '');
  if (url && !isLocalHost(url)) return url;
  return url;
}

export function emailConfirmRedirectUrl() {
  const base = publicAppBase();
  return base ? `${base}/auth/email-confirmed.html` : '/auth/email-confirmed.html';
}
