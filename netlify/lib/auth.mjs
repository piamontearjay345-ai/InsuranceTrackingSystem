import crypto from 'crypto';
import * as db from './supabase.mjs';
import * as logs from './logs.mjs';
import * as passwordReset from './passwordReset.mjs';
import { sanitizeEmail, sanitizeString } from './security.mjs';
import { establishSession, publicUser, roleRedirect, touchSession } from './session.mjs';
import { env, envInt } from './env.mjs';

export async function register(data) {
  const email = sanitizeEmail(data.email);
  const username = sanitizeString(data.username);
  const role = 'student';

  const existing = await db.from(
    'users',
    'GET',
    null,
    `select=id&or=(email.eq.${encodeURIComponent(email)},username.eq.${encodeURIComponent(username)})&is_deleted=eq.false`,
    true
  );
  if (existing.ok && existing.data?.length) {
    return { success: false, message: 'Email or username already exists.' };
  }

  const signup = await db.authSignup({
    email,
    password: data.password,
    data: { fullname: data.fullname, student_id: data.student_id, username, role },
  });
  if (!signup.ok) {
    let msg = signup.error || 'Registration failed.';
    if (String(msg).toLowerCase().includes('already')) msg = 'Email already registered.';
    return { success: false, message: msg };
  }

  const userId = signup.data?.id || signup.data?.user?.id;
  if (userId) {
    await db.from('users', 'PATCH', {
      student_id: data.student_id,
      fullname: data.fullname,
      email,
      username,
      role,
      is_deleted: false,
    }, `id=eq.${userId}`, true);
  }

  return { success: true, message: 'Registration successful. Please sign in.' };
}

export async function registerRole(data, role) {
  const email = sanitizeEmail(data.email);
  const username = sanitizeString(data.username);
  const r = role === 'superadmin' ? 'superadmin' : 'admin';

  const existing = await db.from(
    'users',
    'GET',
    null,
    `select=id&or=(email.eq.${encodeURIComponent(email)},username.eq.${encodeURIComponent(username)})&is_deleted=eq.false`,
    true
  );
  if (existing.ok && existing.data?.length) {
    return { success: false, message: 'Email or username already exists.' };
  }

  const signup = await db.authSignup({
    email,
    password: data.password,
    data: {
      fullname: data.fullname,
      student_id: data.student_id || '',
      username,
      role: r,
    },
  });
  if (!signup.ok) {
    let msg = signup.error || 'User creation failed.';
    if (String(msg).toLowerCase().includes('already')) msg = 'Email already registered.';
    return { success: false, message: msg };
  }

  const userId = signup.data?.id || signup.data?.user?.id;
  if (userId) {
    await db.from('users', 'PATCH', {
      student_id: data.student_id || '',
      fullname: data.fullname,
      email,
      username,
      role: r,
      is_deleted: false,
    }, `id=eq.${userId}`, true);
  }

  return { success: true, message: 'User account created successfully.' };
}

export async function login(identifier, password, event) {
  identifier = String(identifier || '').trim();
  const maxAttempts = envInt('LOGIN_MAX_ATTEMPTS', 5);
  const lockMinutes = envInt('LOGIN_LOCKOUT_MINUTES', 15);
  const isEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(identifier);
  const profile = await resolveProfile(identifier);
  const email = profile?.email
    ? sanitizeEmail(profile.email)
    : isEmail
      ? sanitizeEmail(identifier)
      : null;

  if (!email) {
    await logs.recordLogin(null, identifier, 'failed', profile?.role, event);
    return { success: false, message: 'Invalid email/username or password.' };
  }

  const locked = profile?.locked_until && new Date(profile.locked_until) > new Date();
  const auth = await db.authLogin(email, password);
  if (!auth.ok) {
    if (locked) {
      await logs.recordLogin(profile?.id, email, 'locked', profile?.role, event);
      return { success: false, message: 'Account temporarily locked. Try again later.' };
    }
    await handleFailedLogin(profile, maxAttempts, lockMinutes);
    await logs.recordLogin(profile?.id, email, 'failed', profile?.role, event);
    return { success: false, message: auth.error || 'Invalid email/username or password.' };
  }

  const accessToken = auth.data?.access_token || '';
  const refreshToken = auth.data?.refresh_token || '';
  const user = auth.data?.user || {};

  if (profile?.id) {
    await db.from('users', 'PATCH', { failed_login_attempts: 0, locked_until: null }, `id=eq.${profile.id}`, true);
  }

  const authUserId = String(user.id || profile?.id || '');
  let fullProfile =
    authUserId && profile?.id === authUserId
      ? profile
      : await getProfileById(authUserId, accessToken);
  if (!fullProfile?.id && authUserId) {
    fullProfile = await ensureProfileFromAuthUser(user, accessToken);
  }

  await logs.recordLogin(fullProfile?.id, email, 'success', fullProfile?.role, event);

  const session = establishSession(fullProfile, accessToken, refreshToken);
  return {
    success: true,
    message: 'Login successful.',
    session,
    data: {
      role: fullProfile.role,
      redirect: roleRedirect(fullProfile.role),
      user: publicUser(fullProfile),
    },
  };
}

export async function loginWithOAuthTokens(accessToken, refreshToken, event) {
  accessToken = String(accessToken || '').trim();
  if (!accessToken) {
    return { success: false, message: 'Google sign-in did not return a valid session.' };
  }

  const authUser = await db.authGetUser(accessToken);
  if (!authUser.ok || !authUser.data?.id) {
    return { success: false, message: 'Could not verify Google account.' };
  }

  const user = authUser.data;
  let profile = await getProfileById(user.id, accessToken);
  if (!profile) profile = await ensureProfileFromAuthUser(user, accessToken);
  if (profile?.is_deleted) return { success: false, message: 'This account is disabled.' };

  const email = sanitizeEmail(profile.email || user.email || '');
  await logs.recordLogin(profile.id, email, 'success', profile.role || 'student', event);

  const session = establishSession(profile, accessToken, refreshToken);
  return {
    success: true,
    message: 'Login successful.',
    session,
    data: {
      role: profile.role || 'student',
      redirect: roleRedirect(profile.role),
      user: publicUser(profile),
    },
  };
}

export async function completeGoogleOAuth(code, state, session) {
  const expected = session?.google_oauth_state || '';
  if (!expected || expected !== state) {
    return { success: false, message: 'Google sign-in session expired. Please try again.' };
  }

  const tokens = await exchangeGoogleCode(code);
  if (!tokens.ok) return { success: false, message: tokens.error || 'Could not complete Google sign-in.' };

  const claims = await verifyGoogleIdToken(tokens.id_token);
  if (!claims.ok) return { success: false, message: claims.error || 'Could not verify Google account.' };

  const email = sanitizeEmail(claims.email);
  if (!email) return { success: false, message: 'Google account did not provide an email address.' };

  if (tokens.id_token) {
    const supabase = await db.authSignInWithIdToken('google', tokens.id_token);
    if (supabase.ok && supabase.data?.access_token) {
      return finishAuthTokenResponse(supabase.data, email, session);
    }
  }

  return loginWithGoogleEmailViaAdmin(email, claims.name || '', session);
}

export function buildGoogleOAuthUrl(session) {
  const clientId = env('GOOGLE_CLIENT_ID').trim();
  const clientSecret = env('GOOGLE_CLIENT_SECRET').trim();
  if (!clientId || !clientSecret) return '';

  const redirectUri = `${env('URL', env('APP_URL', '')).replace(/\/$/, '')}/auth/google-callback.php`;
  const state = crypto.randomBytes(16).toString('hex');
  session.google_oauth_state = state;
  touchSession(session);

  const q = new URLSearchParams({
    client_id: clientId,
    redirect_uri: redirectUri,
    response_type: 'code',
    scope: 'openid email profile',
    state,
    access_type: 'online',
    prompt: 'select_account',
  });
  return { url: `https://accounts.google.com/o/oauth2/v2/auth?${q}`, session };
}

async function exchangeGoogleCode(code) {
  const clientId = env('GOOGLE_CLIENT_ID').trim();
  const clientSecret = env('GOOGLE_CLIENT_SECRET').trim();
  const redirectUri = `${env('URL', env('APP_URL', '')).replace(/\/$/, '')}/auth/google-callback.php`;
  if (!clientId || !clientSecret) {
    return { ok: false, error: 'Google OAuth is not configured on the server.' };
  }

  const res = await fetch('https://oauth2.googleapis.com/token', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      code,
      client_id: clientId,
      client_secret: clientSecret,
      redirect_uri: redirectUri,
      grant_type: 'authorization_code',
    }),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok || !data.id_token) {
    return { ok: false, error: data.error_description || data.error || 'Google token exchange failed.' };
  }
  return { ok: true, id_token: data.id_token };
}

async function verifyGoogleIdToken(idToken) {
  const res = await fetch(`https://oauth2.googleapis.com/tokeninfo?id_token=${encodeURIComponent(idToken)}`);
  const data = await res.json().catch(() => ({}));
  if (!res.ok) return { ok: false, error: 'Invalid Google ID token.' };
  const clientId = env('GOOGLE_CLIENT_ID').trim();
  if (clientId && data.aud !== clientId) return { ok: false, error: 'Google token audience mismatch.' };
  if (data.email_verified !== 'true' && data.email_verified !== true) {
    return { ok: false, error: 'Google email is not verified.' };
  }
  return { ok: true, email: data.email, name: data.name };
}

async function loginWithGoogleEmailViaAdmin(email, fullName, session) {
  let profile = await resolveProfile(email);
  if (!profile?.id) {
    const username = (email.split('@')[0] || 'user').replace(/[^A-Za-z0-9_]/g, '_').slice(0, 20);
    const password = crypto.randomBytes(24).toString('hex');
    const created = await db.adminCreateUser({
      email,
      password,
      email_confirm: true,
      user_metadata: { fullname: fullName || email, username, role: 'student' },
    });
    if (created.ok) {
      const userId = created.data?.id || created.data?.user?.id;
      if (userId) {
        await db.from('users', 'PATCH', {
          fullname: fullName || email,
          email,
          username,
          role: 'student',
          is_deleted: false,
        }, `id=eq.${userId}`, true);
      }
    }
  }

  const sessionRes = await createSupabaseSessionForEmail(email);
  if (!sessionRes.ok) {
    return { success: false, message: sessionRes.error || 'Could not start session after Google sign-in.' };
  }
  return finishAuthTokenResponse(sessionRes.data, email, session);
}

async function createSupabaseSessionForEmail(email) {
  const link = await db.adminGenerateLink('magiclink', email);
  if (!link.ok) return { ok: false, error: link.error || 'Could not sign in with Google.' };
  const tokenHash = link.data?.hashed_token || '';
  if (!tokenHash) return { ok: false, error: 'Could not create sign-in session.' };
  const verify = await db.authVerify('magiclink', tokenHash);
  if (!verify.ok || !verify.data?.access_token) {
    return { ok: false, error: verify.error || 'Could not verify Google sign-in session.' };
  }
  return { ok: true, data: verify.data };
}

async function finishAuthTokenResponse(tokenData, email, existingSession) {
  const accessToken = tokenData.access_token || '';
  const refreshToken = tokenData.refresh_token || '';
  if (!accessToken) return { success: false, message: 'Sign-in did not return a valid session.' };

  const authUser = await db.authGetUser(accessToken);
  if (!authUser.ok || !authUser.data?.id) {
    return { success: false, message: 'Could not load account after Google sign-in.' };
  }

  let profile = await getProfileById(authUser.data.id, accessToken);
  if (!profile) profile = await ensureProfileFromAuthUser(authUser.data, accessToken);
  if (profile?.is_deleted) return { success: false, message: 'This account is disabled.' };

  const session = establishSession(profile, accessToken, refreshToken, existingSession || undefined);
  return {
    success: true,
    message: 'Login successful.',
    session,
    data: {
      role: profile.role || 'student',
      redirect: roleRedirect(profile.role),
      user: publicUser(profile),
    },
  };
}

async function resolveProfile(identifier) {
  const isEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(identifier);
  const enc = encodeURIComponent(identifier);
  let res = await db.from(
    'users',
    'GET',
    null,
    `select=*&or=(email.eq.${enc},username.eq.${enc})&is_deleted=eq.false`,
    true
  );
  if (res.ok && res.data?.[0]) return res.data[0];
  if (!isEmail) {
    res = await db.from('users', 'GET', null, `select=*&username.ilike.${enc}&is_deleted=eq.false`, true);
    if (res.ok && res.data?.[0]) return res.data[0];
  }
  return {};
}

async function getProfileById(id, token) {
  if (!id) return null;
  const res = await db.from('users', 'GET', null, `select=*&id=eq.${id}`, false, token);
  return res.ok && res.data?.[0] ? res.data[0] : null;
}

async function handleFailedLogin(profile, maxAttempts, lockMinutes) {
  if (!profile?.id) return;
  const attempts = (profile.failed_login_attempts || 0) + 1;
  const patch = { failed_login_attempts: attempts };
  if (attempts >= maxAttempts) {
    patch.locked_until = new Date(Date.now() + lockMinutes * 60 * 1000).toISOString();
    patch.failed_login_attempts = 0;
  }
  await db.from('users', 'PATCH', patch, `id=eq.${profile.id}`, true);
}

async function ensureProfileFromAuthUser(user, accessToken) {
  const meta = user.user_metadata || {};
  const email = sanitizeEmail(user.email || '');
  let username = sanitizeString(meta.username || '');
  if (!username && email) {
    username = (email.split('@')[0] || 'user').replace(/[^A-Za-z0-9_]/g, '_').slice(0, 20);
  }
  const profile = {
    id: user.id,
    student_id: meta.student_id || '',
    fullname: meta.fullname || meta.full_name || user.email || 'User',
    email,
    username,
    role: meta.role || 'student',
    permissions: {},
    is_deleted: false,
  };
  const payload = {
    student_id: profile.student_id,
    fullname: profile.fullname,
    email: profile.email,
    username: profile.username,
    role: profile.role,
    is_deleted: false,
  };
  await db.from('users', 'PATCH', payload, `id=eq.${encodeURIComponent(user.id)}`, true);
  const loaded = await getProfileById(user.id, accessToken);
  if (loaded) return loaded;
  await db.from('users', 'POST', { id: user.id, ...payload }, null, true);
  return (await getProfileById(user.id, accessToken)) || profile;
}

export { passwordReset };
