/**
 * API client — XAMPP (PHP) or Netlify Functions.
 */
const API = (() => {
  const BASE = (() => {
    const pathname = window.location.pathname;
    const marker = '/InsuranceTrackingSystem';
    if (pathname.includes(marker)) {
      return marker + '/api/index.php';
    }
    // Netlify (site root or nested deploy)
    const host = window.location.hostname;
    if (host.endsWith('.netlify.app') || host.endsWith('.netlify.live')) {
      return '/.netlify/functions/api';
    }
    if (window.location.port === '8888' || window.location.port === '8889') {
      return '/.netlify/functions/api';
    }
    return '/.netlify/functions/api';
  })();

  let csrfToken = null;

  function buildUrl(route) {
    const r = String(route).startsWith('/') ? String(route) : '/' + String(route);
    return `${BASE}?route=${encodeURIComponent(r)}`;
  }

  async function parseResponse(res) {
    const text = await res.text();
    if (!text) {
      return { success: false, message: 'Empty response from server.' };
    }
    try {
      return JSON.parse(text);
    } catch (e) {
      const preview = text.replace(/\s+/g, ' ').slice(0, 120);
      console.error('API.parseResponse: failed to parse JSON. Raw response preview:', preview);
      throw new Error(
        'Server returned HTML instead of JSON. Check API / environment variables. Response: ' + preview
      );
    }
  }

  async function fetchCsrf() {
    if (csrfToken) return csrfToken;
    const res = await fetch(buildUrl('/csrf'), { credentials: 'include' });
    const json = await parseResponse(res);
    if (json.success && json.data?.token) {
      csrfToken = json.data.token;
    }
    return csrfToken;
  }

  async function request(path, options = {}) {
    const method = (options.method || 'GET').toUpperCase();
    if (!csrfToken && method !== 'GET' && method !== 'HEAD') {
      await fetchCsrf();
    }

    const headers = {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      ...(options.headers || {}),
    };
    if (csrfToken && method !== 'GET') {
      headers['X-CSRF-Token'] = csrfToken;
    }

    const res = await fetch(buildUrl(path), {
      ...options,
      method,
      headers,
      credentials: 'include',
      body: options.body ? JSON.stringify(options.body) : undefined,
    });

    const json = await parseResponse(res);
    if (!res.ok || json.success === false) {
      const err = new Error(json.message || 'Request failed');
      err.status = res.status;
      err.errors = json.errors;
      throw err;
    }
    return json;
  }

  return {
    get: (path) => request(path, { method: 'GET' }),
    post: (path, body) => request(path, { method: 'POST', body }),
    put: (path, body) => request(path, { method: 'PUT', body }),
    init: fetchCsrf,
    hasCsrf: () => !!csrfToken,
    baseUrl: BASE,
  };
})();
