import * as response from './response.mjs';
import * as session from './session.mjs';
import * as auth from './auth.mjs';
import * as admin from './admin.mjs';
import * as beneficiary from './beneficiary.mjs';
import * as db from './supabase.mjs';
import { supabaseConfigured } from './env.mjs';
import { checkCsrf, jsonBody } from './security.mjs';
import { registration, beneficiary as validateBeneficiary } from './validator.mjs';
import { touchSession, sessionHeaders, clearSessionHeaders } from './session.mjs';

const CSRF_EXEMPT = new Set([
  '/auth/register',
  '/auth/login',
  '/auth/forgot-password',
  '/auth/verify-reset-code',
  '/auth/reset-password',
  '/auth/oauth/complete',
  '/auth/confirm-email',
  '/csrf',
]);

export function resolvePath(event) {
  const q = event.queryStringParameters || {};
  if (q.route) {
    let route = String(q.route);
    const parsed = new URL(route, 'http://local');
    route = parsed.pathname || '/';
    if (!route.startsWith('/')) route = `/${route}`;
    return route === '/' ? '' : route;
  }

  let path = event.path || '';
  path = path.replace(/^\/\.netlify\/functions\/api/i, '');
  path = path.replace(/^\/api\/index\.php/i, '');
  path = path.replace(/^\/api/i, '');
  path = `/${path.replace(/^\/+/, '')}`.replace(/\/$/, '') || '';
  return path === '/' ? '' : path;
}

function requireAuth(sess, roles = null) {
  const user = sess?.user;
  if (!user?.id) {
    return { error: response.error('Not authenticated.', 401) };
  }
  if (roles) {
    const allowed = Array.isArray(roles) ? roles : [roles];
    if (!allowed.includes(user.role)) {
      return { error: response.error('Forbidden.', 403) };
    }
  }
  return { user };
}

export async function handle(event) {
  if (event.httpMethod === 'OPTIONS') {
    return { statusCode: 204, headers: {}, body: '' };
  }

  const method = event.httpMethod;
  const path = resolvePath(event);
  let sess = getSession(event) || session.newSession();
  let headers = {};

  if (path !== '' && path !== '/csrf' && !supabaseConfigured()) {
    return response.error(
      'Supabase is not configured. Set SUPABASE_URL, SUPABASE_ANON_KEY, and SUPABASE_SERVICE_ROLE_KEY in Netlify environment variables.',
      503
    );
  }

  const needsCsrf =
    !CSRF_EXEMPT.has(path) && !['GET', 'HEAD', 'OPTIONS'].includes(method);
  if (needsCsrf && !checkCsrf(sess, event)) {
    return response.error('Invalid or missing CSRF token.', 403);
  }

  try {
    // CSRF token
    if (path === '/csrf' && method === 'GET') {
      sess = touchSession(sess);
      headers = sessionHeaders(sess);
      return response.success({ token: sess.csrf_token }, 'OK', 200, headers);
    }

    // Health
    if (path === '' && method === 'GET') {
      return response.success({ status: 'ok', hint: 'Netlify Functions API' });
    }

    // Auth routes
    if (path === '/auth/register' && method === 'POST') {
      const body = jsonBody(event);
      const errors = registration(body);
      if (errors) return response.error('Validation failed.', 422, errors);
      const result = await auth.register(body);
      if (!result.success) return response.error(result.message, 400);
      return response.success(result.data ?? null, result.message, 201);
    }

    if (path === '/auth/confirm-email' && method === 'POST') {
      const body = jsonBody(event);
      const result = await auth.confirmEmail(
        String(body.token_hash || '').trim(),
        String(body.type || 'signup').trim(),
        String(body.code || '').trim()
      );
      if (!result.success) return response.error(result.message, 400);
      return response.success(null, result.message);
    }

    if (path === '/auth/login' && method === 'POST') {
      const body = jsonBody(event);
      const identifier = String(body.identifier ?? body.email ?? body.username ?? '').trim();
      const password = body.password ?? '';
      if (!identifier || !password) {
        return response.error('Email/username and password are required.');
      }
      const result = await auth.login(identifier, password, event);
      if (!result.success) return response.error(result.message, 401);
      headers = sessionHeaders(result.session);
      return response.success(result.data, result.message, 200, headers);
    }

    if (path === '/auth/logout' && method === 'POST') {
      if (sess.access_token) await db.authLogout(sess.access_token);
      return response.success(null, 'Logged out.', 200, clearSessionHeaders());
    }

    if (path === '/auth/me' && method === 'GET') {
      if (!sess.user) return response.error('Not authenticated.', 401);
      return response.success({ user: sess.user });
    }

    if (path === '/auth/forgot-password' && method === 'POST') {
      const body = jsonBody(event);
      const email = String(body.email ?? '').trim();
      if (!email) return response.error('Email address is required.');
      const result = await auth.passwordReset.sendVerificationCode(email);
      if (!result.success) return response.error(result.message, 400);
      return response.success(result.data ?? null, result.message);
    }

    if (path === '/auth/verify-reset-code' && method === 'POST') {
      const body = jsonBody(event);
      const result = await auth.passwordReset.verifyCode(body.email, body.code);
      if (!result.success) return response.error(result.message, 400);
      return response.success(result.data ?? null, result.message);
    }

    if (path === '/auth/reset-password' && method === 'POST') {
      const body = jsonBody(event);
      if (body.password !== body.confirm_password) {
        return response.error('Passwords do not match.');
      }
      const result = await auth.passwordReset.resetPassword(
        body.email,
        body.reset_token,
        body.password
      );
      if (!result.success) return response.error(result.message, 400);
      return response.success(null, result.message);
    }

    if (path === '/auth/oauth/complete' && method === 'POST') {
      const body = jsonBody(event);
      const result = await auth.loginWithOAuthTokens(body.access_token, body.refresh_token, event);
      if (!result.success) return response.error(result.message, 401);
      headers = sessionHeaders(result.session);
      return response.success(result.data, result.message, 200, headers);
    }

    if (path === '/auth/google/url' && method === 'GET') {
      const url = db.googleAuthorizeUrl();
      if (!url) return response.error('Google sign-in is not configured.', 503);
      return response.success({ url });
    }

    // Beneficiary
    if (path === '/beneficiary' && method === 'GET') {
      const authCheck = requireAuth(sess);
      if (authCheck.error) return authCheck.error;
      const result = await beneficiary.show(authCheck.user, sess.access_token, event.queryStringParameters || {});
      if (result.error) return response.error(result.error, result.status);
      return response.success(result.data);
    }

    if (path === '/beneficiary' && ['POST', 'PUT', 'PATCH'].includes(method)) {
      const authCheck = requireAuth(sess);
      if (authCheck.error) return authCheck.error;
      const body = jsonBody(event);
      const errors = validateBeneficiary(body);
      if (errors) return response.error('Validation failed.', 422, errors);
      const result = await beneficiary.save(authCheck.user, sess.access_token, body, event);
      if (result.error) return response.error(result.error, result.status);
      return response.success(result.data, result.message);
    }

    // Notifications
    if (path === '/notifications' && method === 'GET') {
      const authCheck = requireAuth(sess);
      if (authCheck.error) return authCheck.error;
      const limit = Math.min(50, Math.max(5, parseInt(event.queryStringParameters?.limit, 10) || 10));
      const res = await db.from(
        'notifications',
        'GET',
        null,
        `select=*&user_id=eq.${authCheck.user.id}&order=created_at.desc&limit=${limit}`,
        false,
        sess.access_token
      );
      return response.success({ notifications: res.data || [] });
    }

    // Admin
    if (path === '/admin/stats' && method === 'GET') {
      const authCheck = requireAuth(sess, ['admin', 'superadmin']);
      if (authCheck.error) return authCheck.error;
      const result = await admin.stats(authCheck.user);
      if (result.error) return response.error(result.error, result.status);
      return response.success(result.data);
    }

    if (path === '/admin/students' && method === 'GET') {
      const authCheck = requireAuth(sess, ['admin', 'superadmin']);
      if (authCheck.error) return authCheck.error;
      const result = await admin.students(event.queryStringParameters || {});
      return response.success(result.data);
    }

    if (path === '/admin/beneficiary-update-requests' && method === 'GET') {
      const authCheck = requireAuth(sess, ['admin', 'superadmin']);
      if (authCheck.error) return authCheck.error;
      const result = await admin.beneficiaryUpdateRequests(event.queryStringParameters || {});
      return response.success(result.data);
    }

    if (path === '/admin/beneficiary-update-request' && method === 'POST') {
      const authCheck = requireAuth(sess, ['admin', 'superadmin']);
      if (authCheck.error) return authCheck.error;
      const result = await admin.sendBeneficiaryUpdateRequest(authCheck.user, jsonBody(event), event);
      if (result.error) return response.error(result.error, result.status);
      return response.success(result.data, result.message);
    }

    if (path === '/admin/beneficiary-update-request/all' && method === 'POST') {
      const authCheck = requireAuth(sess, ['admin', 'superadmin']);
      if (authCheck.error) return authCheck.error;
      const result = await admin.sendAllBeneficiaryUpdateRequests(authCheck.user, event);
      if (result.error) return response.error(result.error, result.status);
      return response.success(result.data, result.message);
    }

    if (path === '/admin/notifications' && method === 'GET') {
      const authCheck = requireAuth(sess, ['admin', 'superadmin']);
      if (authCheck.error) return authCheck.error;
      const result = await admin.notifications(event.queryStringParameters || {});
      return response.success(result.data);
    }

    if (path === '/admin/failed-notifications' && method === 'GET') {
      const authCheck = requireAuth(sess, ['admin', 'superadmin']);
      if (authCheck.error) return authCheck.error;
      const result = await admin.failedNotifications();
      return response.success(result.data);
    }

    if (path === '/admin/retry-notification' && method === 'POST') {
      const authCheck = requireAuth(sess, ['admin', 'superadmin']);
      if (authCheck.error) return authCheck.error;
      const result = await admin.retryNotification(authCheck.user, jsonBody(event), event);
      if (result.error) return response.error(result.error, result.status);
      return response.success(null, result.message);
    }

    if (path === '/admin/login-history' && method === 'GET') {
      const authCheck = requireAuth(sess, ['admin', 'superadmin']);
      if (authCheck.error) return authCheck.error;
      const result = await admin.loginHistory(event.queryStringParameters || {});
      return response.success(result.data);
    }

    if (path === '/admin/activity-logs' && method === 'GET') {
      const authCheck = requireAuth(sess, ['admin', 'superadmin']);
      if (authCheck.error) return authCheck.error;
      const result = await admin.activityLogs(event.queryStringParameters || {});
      return response.success(result.data);
    }

    if (path === '/superadmin/users' && method === 'GET') {
      const authCheck = requireAuth(sess, 'superadmin');
      if (authCheck.error) return authCheck.error;
      const result = await admin.users(event.queryStringParameters || {});
      return response.success(result.data);
    }

    if (path === '/superadmin/user' && method === 'POST') {
      const authCheck = requireAuth(sess, 'superadmin');
      if (authCheck.error) return authCheck.error;
      const result = await admin.createUser(authCheck.user, jsonBody(event), event);
      if (result.error) return response.error(result.error, result.status);
      return response.success(result.data ?? null, result.message);
    }

    if (path === '/superadmin/user' && ['PUT', 'PATCH'].includes(method)) {
      const authCheck = requireAuth(sess, 'superadmin');
      if (authCheck.error) return authCheck.error;
      const result = await admin.updateUser(authCheck.user, jsonBody(event), event);
      if (result.error) return response.error(result.error, result.status);
      return response.success(result.data, result.message);
    }

    if (path === '/superadmin/user/reset' && method === 'POST') {
      const authCheck = requireAuth(sess, 'superadmin');
      if (authCheck.error) return authCheck.error;
      const result = await admin.resetUserPassword(authCheck.user, jsonBody(event), event);
      if (result.error) return response.error(result.error, result.status);
      return response.success(null, result.message);
    }

    return response.error(`Endpoint not found: ${path || '(empty)'}`, 404);
  } catch (e) {
    console.error(e);
    const msg = process.env.APP_DEBUG === 'true' ? e.message : 'Internal server error.';
    return response.error(msg, 500);
  }
}

function getSession(event) {
  return session.getSession(event);
}
