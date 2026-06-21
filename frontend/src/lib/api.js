const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || '';
const TOKEN_KEY = 'secaspi_token';

export function setAuthToken(token) {
  if (token) {
    localStorage.setItem(TOKEN_KEY, token);
  } else {
    localStorage.removeItem(TOKEN_KEY);
  }
}

export function getAuthToken() {
  return localStorage.getItem(TOKEN_KEY);
}

async function request(path, { method = 'GET', body, headers = {} } = {}) {
  const url = `${API_BASE_URL}${path}`;
  const token = getAuthToken();
  const isFormData = body instanceof FormData;

  const res = await fetch(url, {
    method,
    headers: {
      Accept: 'application/json',
      ...(isFormData ? {} : { 'Content-Type': 'application/json' }),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...headers,
    },
    body: isFormData ? body : (body ? JSON.stringify(body) : undefined),
  });

  const contentType = res.headers.get('content-type') || '';
  const data = contentType.includes('application/json') ? await res.json() : await res.text();

  if (!res.ok) {
    const message = (data && data.message) ? data.message : 'Request failed';
    throw new Error(message);
  }

  return data;
}

export const api = {
  get: (path) => request(path, { method: 'GET' }),
  post: (path, body) => request(path, { method: 'POST', body }),
  put: (path, body) => request(path, { method: 'PUT', body }),
  delete: (path) => request(path, { method: 'DELETE' }),
};

// Sanctum auth is a Bearer header, not a cookie, so a plain <a href> download
// link can't carry it — fetch the file ourselves (with the header) and hand
// back a Blob the caller can save via a temporary object URL.
export async function downloadFile(path) {
  const token = getAuthToken();
  const res = await fetch(`${API_BASE_URL}${path}`, {
    headers: {
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  });

  if (!res.ok) {
    let message = 'Export failed';
    try {
      const data = await res.json();
      message = data?.message || message;
    } catch {
      // response wasn't JSON; keep the default message
    }
    throw new Error(message);
  }

  const disposition = res.headers.get('content-disposition') || '';
  const match = disposition.match(/filename="?([^"]+)"?/);
  const filename = match ? match[1] : 'export';
  const blob = await res.blob();

  return { blob, filename };
}

