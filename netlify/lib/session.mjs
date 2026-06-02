import crypto from 'crypto';
import { env, envInt } from './env.mjs';

const COOKIE_NAME = env('SESSION_NAME', 'SITS_SESSION');

function secret() {
  return env('SESSION_SECRET') || env('SUPABASE_SERVICE_ROLE_KEY') || 'change-SESSION_SECRET-in-production';
}

function parseCookies(header = '') {
  const out = {};
  for (const part of header.split(';')) {
    const [k, ...rest] = part.trim().split('=');
    if (k) out[k] = decodeURIComponent(rest.join('='));
  }
  return out;
}

function sign(data) {
  const payload = Buffer.from(JSON.stringify(data)).toString('base64url');
  const sig = crypto.createHmac('sha256', secret()).update(payload).digest('base64url');
  return `${payload}.${sig}`;
}

function unsign(value) {
  if (!value) return null;
  const i = value.lastIndexOf('.');
  if (i < 0) return null;
  const payload = value.slice(0, i);
  const sig = value.slice(i + 1);
  const expected = crypto.createHmac('sha256', secret()).update(payload).digest('base64url');
  if (sig !== expected) return null;
  try {
    const data = JSON.parse(Buffer.from(payload, 'base64url').toString());
    const timeout = envInt('SESSION_TIMEOUT', 900) * 1000;
    if (data.last_activity && Date.now() - data.last_activity > timeout) return null;
    return data;
  } catch {
    return null;
  }
}

export function getSession(event) {
  const cookies = parseCookies(event.headers.cookie || event.headers.Cookie || '');
  return unsign(cookies[COOKIE_NAME]);
}

export function sessionHeaders(session, extra = {}) {
  const secure = env('COOKIE_SECURE', 'true') === 'true';
  const sameSite = env('COOKIE_SAMESITE', 'Lax');
  const flags = [
    `${COOKIE_NAME}=${encodeURIComponent(sign(session))}`,
    'Path=/',
    'HttpOnly',
    `SameSite=${sameSite}`,
    'Max-Age=86400',
  ];
  if (secure) flags.push('Secure');
  return {
    ...extra,
    'Set-Cookie': flags.join('; '),
  };
}

export function clearSessionHeaders(extra = {}) {
  const flags = [`${COOKIE_NAME}=`, 'Path=/', 'HttpOnly', 'Max-Age=0'];
  return { ...extra, 'Set-Cookie': flags.join('; ') };
}

export function touchSession(session) {
  session.last_activity = Date.now();
  if (!session.csrf_token) {
    session.csrf_token = crypto.randomBytes(32).toString('hex');
  }
  return session;
}

export function newSession() {
  return touchSession({
    user_id: null,
    user: null,
    access_token: null,
    refresh_token: null,
    csrf_token: crypto.randomBytes(32).toString('hex'),
    google_oauth_state: null,
    last_activity: Date.now(),
  });
}

export function establishSession(profile, accessToken, refreshToken, existing = null) {
  const session = existing ? touchSession({ ...existing }) : newSession();
  session.user_id = profile.id;
  session.access_token = accessToken;
  session.refresh_token = refreshToken || '';
  session.user = publicUser(profile);
  return session;
}

export function publicUser(profile) {
  return {
    id: profile.id,
    student_id: profile.student_id ?? '',
    fullname: profile.fullname ?? '',
    email: profile.email ?? '',
    username: profile.username ?? '',
    role: profile.role ?? 'student',
    permissions: profile.permissions ?? {},
  };
}

export function roleRedirect(role) {
  if (role === 'superadmin' || role === 'super_admin') return 'superadmin/dashboard.html';
  if (role === 'admin') return 'admin/dashboard.html';
  return 'student/dashboard.html';
}
