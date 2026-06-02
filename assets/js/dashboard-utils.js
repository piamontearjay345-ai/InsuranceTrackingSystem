/**
 * Shared helpers for dashboard list rendering (student, admin, superadmin).
 */
(function (global) {
  function asList(value) {
    return Array.isArray(value) ? value : [];
  }

  function listFrom(res, key) {
    const data = res?.data;
    if (key != null && key !== '') {
      return asList(data?.[key]);
    }
    return asList(data);
  }

  global.asList = asList;
  global.listFrom = listFrom;
})(typeof window !== 'undefined' ? window : globalThis);
