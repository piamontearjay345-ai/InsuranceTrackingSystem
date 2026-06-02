export function sanitizeEmail(email) {
  return String(email || '')
    .trim()
    .toLowerCase()
    .replace(/[\r\n<>]/g, '');
}

export function sanitizeString(s) {
  return String(s || '')
    .trim()
    .replace(/[\r\n<>]/g, '');
}

export function jsonBody(event) {
  if (!event.body) return {};
  try {
    return JSON.parse(event.body);
  } catch {
    return {};
  }
}

export function clientIp(event) {
  return (
    event.headers['x-forwarded-for']?.split(',')[0]?.trim() ||
    event.headers['client-ip'] ||
    event.headers['x-nf-client-connection-ip'] ||
    ''
  );
}

export function browserInfo(event) {
  return (event.headers['user-agent'] || '').slice(0, 255);
}

export function deviceInfo() {
  return 'web';
}

export function checkCsrf(session, event) {
  const header = event.headers['x-csrf-token'] || event.headers['X-CSRF-Token'] || '';
  return header && session?.csrf_token && header === session.csrf_token;
}
