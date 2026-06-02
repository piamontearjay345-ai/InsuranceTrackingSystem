export function env(key, fallback = '') {
  return (process.env[key] ?? fallback).toString();
}

export function envInt(key, fallback) {
  const v = parseInt(env(key, ''), 10);
  return Number.isFinite(v) ? v : fallback;
}

export function envBool(key, fallback = false) {
  const v = env(key, '').toLowerCase();
  if (v === 'true' || v === '1') return true;
  if (v === 'false' || v === '0') return false;
  return fallback;
}

export function appUrl() {
  return env('URL', env('APP_URL', '')).replace(/\/$/, '');
}

export function supabaseConfigured() {
  const url = env('SUPABASE_URL');
  const key = env('SUPABASE_ANON_KEY');
  return Boolean(url && key && !url.includes('your-project'));
}
