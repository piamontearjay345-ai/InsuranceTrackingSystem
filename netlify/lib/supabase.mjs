import { env } from './env.mjs';

export function getUrl() {
  return env('SUPABASE_URL').replace(/\/$/, '');
}

async function request(method, path, body, apiKey, bearer = null) {
  const base = getUrl();
  if (!base || !apiKey) {
    return { ok: false, status: 0, error: 'Supabase URL or API key is not configured.', data: null };
  }
  const headers = {
    apikey: apiKey,
    'Content-Type': 'application/json',
  };
  if (bearer) headers.Authorization = `Bearer ${bearer}`;

  const opts = { method, headers };
  if (body != null && method !== 'GET') {
    opts.body = JSON.stringify(body);
    if (['POST', 'PATCH', 'PUT'].includes(method)) {
      headers.Prefer = 'return=representation';
    }
  }

  try {
    const res = await fetch(`${base}${path}`, opts);
    const text = await res.text();
    let data = null;
    if (text) {
      try {
        data = JSON.parse(text);
      } catch {
        data = text;
      }
    }
    const ok = res.status >= 200 && res.status < 300;
    const errMsg =
      data?.msg || data?.error_description || data?.message || data?.error || 'Request failed';
    return { ok, status: res.status, error: ok ? null : errMsg, data };
  } catch (e) {
    return { ok: false, status: 0, error: e.message || 'Request failed', data: null };
  }
}

export function from(table, method = 'GET', body = null, query = null, useService = false, userToken = null) {
  const anon = env('SUPABASE_ANON_KEY');
  const service = env('SUPABASE_SERVICE_ROLE_KEY');
  const key = useService ? service : anon;
  const token = useService ? service : userToken || anon;
  const path = `/rest/v1/${table}${query ? `?${query}` : ''}`;
  return request(method, path, body, key, token);
}

export function authSignup(payload) {
  return request('POST', '/auth/v1/signup', payload, env('SUPABASE_ANON_KEY'));
}

export function authLogin(email, password) {
  return request('POST', '/auth/v1/token?grant_type=password', { email, password }, env('SUPABASE_ANON_KEY'));
}

export function authLogout(accessToken) {
  return request('POST', '/auth/v1/logout', {}, env('SUPABASE_ANON_KEY'), accessToken);
}

export function authGetUser(accessToken) {
  return request('GET', '/auth/v1/user', null, env('SUPABASE_ANON_KEY'), accessToken);
}

export function authSignInWithIdToken(provider, idToken) {
  return request(
    'POST',
    '/auth/v1/token?grant_type=id_token',
    { provider, id_token: idToken },
    env('SUPABASE_ANON_KEY')
  );
}

export function authVerify(type, tokenHash) {
  return request('POST', '/auth/v1/verify', { type, token_hash: tokenHash }, env('SUPABASE_ANON_KEY'));
}

export function adminCreateUser(payload) {
  const key = env('SUPABASE_SERVICE_ROLE_KEY');
  return request('POST', '/auth/v1/admin/users', payload, key, key);
}

export function adminGenerateLink(type, email) {
  const key = env('SUPABASE_SERVICE_ROLE_KEY');
  return request('POST', '/auth/v1/admin/generate_link', { type, email }, key, key);
}

export function adminUpdateUser(userId, payload) {
  const key = env('SUPABASE_SERVICE_ROLE_KEY');
  return request('PUT', `/auth/v1/admin/users/${encodeURIComponent(userId)}`, payload, key, key);
}

export function googleAuthorizeUrl() {
  const base = getUrl();
  const callback = `${appUrlFromEnv()}/auth/oauth-callback.html`;
  if (!base) return '';
  const q = new URLSearchParams({ provider: 'google', redirect_to: callback });
  return `${base}/auth/v1/authorize?${q}`;
}

function appUrlFromEnv() {
  return env('URL', env('APP_URL', '')).replace(/\/$/, '');
}
