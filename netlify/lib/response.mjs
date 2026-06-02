export function json(statusCode, body, extraHeaders = {}) {
  return {
    statusCode,
    headers: {
      'Content-Type': 'application/json; charset=utf-8',
      'Access-Control-Allow-Credentials': 'true',
      'Access-Control-Allow-Methods': 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type, X-CSRF-Token',
      ...extraHeaders,
    },
    body: JSON.stringify(body),
  };
}

export function success(data = null, message = 'OK', status = 200, headers = {}) {
  return json(status, { success: true, message, data }, headers);
}

export function error(message, status = 400, errors = null, headers = {}) {
  const body = { success: false, message };
  if (errors) body.errors = errors;
  return json(status, body, headers);
}

export function redirect(location, headers = {}) {
  return {
    statusCode: 302,
    headers: { Location: location, ...headers },
    body: '',
  };
}
