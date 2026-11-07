/**
 * Client for the CodeIgniter API at /RigCraft/api.
 *
 *   api.get('units', { q: 'rtx' })
 *   api.post('units/RC-3070TI-8941/sell', { sold_price: 19999 })
 *   api.put(...), api.del(...)
 *
 * Resolves with the parsed JSON ({ ok: true, ... }) or throws ApiError.
 * State-changing calls send the session's CSRF token; if it has gone stale
 * (403 csrf) the session is refreshed and the call retried once.
 * A 401 sends the visitor to the matching sign-in page and returns to the
 * current page afterwards.
 */
import { session, applySession } from './session.js';

export const API_BASE = `${import.meta.env.BASE_URL}api/`;

export class ApiError extends Error {
  constructor(message, status = 0, fields = null, data = null) {
    super(message);
    this.status = status;   // HTTP status (0 = network failure)
    this.fields = fields;   // per-field validation errors, if any
    this.data   = data;     // the full error response (e.g. checkout's `unavailable` list)
  }
}

let router = null;
/** Called once from main.js so 401s can redirect through the router. */
export function useRouterForAuth(r) { router = r; }

async function request(method, path, { params, body } = {}, retried = false) {
  const url = new URL(API_BASE + path.replace(/^\//, ''), location.origin);
  for (const [k, v] of Object.entries(params || {})) {
    if (v !== '' && v !== null && v !== undefined) url.searchParams.set(k, v);
  }

  const headers = { Accept: 'application/json' };
  if (method !== 'GET') {
    headers['Content-Type'] = 'application/json';
    headers['X-CSRF-Token'] = session.csrf;
  }

  let res;
  try {
    res = await fetch(url, {
      method, headers, credentials: 'same-origin',
      body: method === 'GET' ? undefined : JSON.stringify(body ?? {}),
    });
  } catch {
    throw new ApiError('Cannot reach the RigCraft server. Is Apache running?', 0);
  }

  let data;
  try { data = await res.json(); }
  catch { throw new ApiError(`Unexpected server response (HTTP ${res.status}).`, res.status); }

  // Stale CSRF token (e.g. session expired in another tab): refresh once and retry.
  if (res.status === 403 && data.csrf && !retried) {
    await loadSession();
    return request(method, path, { params, body }, true);
  }

  if (res.status === 401 && data.auth && router) {
    const here = router.currentRoute.value.fullPath;
    if (data.auth === 'customer') {
      applySession({ ...snapshot(), customer: null, can_shop: false });
      router.push({ name: 'signin', query: { next: here } });
    } else {
      applySession({ ...snapshot(), user: null });
      router.push({ name: 'staff-login', query: { next: here, expired: 1 } });
    }
    return new Promise(() => {});   // never resolves; the page is navigating away
  }

  if (!res.ok || !data.ok) {
    throw new ApiError(data.error || `Request failed (HTTP ${res.status}).`, res.status, data.fields || null, data);
  }
  return data;
}

const snapshot = () => ({ user: session.user, customer: session.customer, can_shop: session.canShop });

export const api = {
  get:  (path, params)  => request('GET', path, { params }),
  post: (path, body)    => request('POST', path, { body }),
  put:  (path, body)    => request('PUT', path, { body }),
  del:  (path, body)    => request('DELETE', path, { body }),
};

/** (Re)load who is signed in + the CSRF token. */
export async function loadSession() {
  const s = await request('GET', 'session');
  applySession(s);
  return s;
}

/** Run a sign-in/out call and apply the fresh session it returns. */
export async function authCall(path, body) {
  const s = await api.post(path, body);
  applySession(s);
  return s;
}
