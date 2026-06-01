/**
 * API client - auto-detects project base path; works without Apache rewrite.
 */
const API = (() => {
  /** e.g. /InsuranceTrackingSystem/api/index.php */
  const BASE = (() => {
    const pathname = window.location.pathname;
    const marker = '/InsuranceTrackingSystem';
    if (pathname.includes(marker)) {
      return marker + '/api/index.php';
    }
    // student/dashboard.html or admin/dashboard.html -> go up to project root
    const match = pathname.match(/^(.+?)\/(student|admin)\//);
    if (match) {
      return match[1] + '/api/index.php';
    }
    const dir = pathname.replace(/\/[^/]*$/, '') || '';
    return (dir || '') + '/api/index.php';
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
      // Log raw response preview to help debug when HTML or PHP warnings are returned
      const preview = text.replace(/\s+/g, ' ').slice(0, 120);
      console.error('API.parseResponse: failed to parse JSON. Raw response preview:', preview);
      throw new Error(
        'Server returned HTML instead of JSON. Check that Apache/PHP is running and the API URL is correct. Response: ' + preview
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
