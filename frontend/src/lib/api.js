// src/lib/api.js — CZium Distribution API client
const BASE = window.location.origin.includes('localhost')
  ? (window.location.pathname.includes('czium') ? '/czium/CZiumDistribution/api' : '/api')
  : '/api';

const Api = {
  token: localStorage.getItem('czium_token') || '',
  user:  JSON.parse(localStorage.getItem('czium_user') || 'null'),

  setToken(t) { this.token = t; localStorage.setItem('czium_token', t); },
  setUser(u)  { this.user = u;  localStorage.setItem('czium_user', JSON.stringify(u)); },
  clearAuth() {
    this.token = ''; this.user = null;
    localStorage.removeItem('czium_token'); localStorage.removeItem('czium_user');
  },

  async req(method, path, body, query) {
    const url = new URL(`${BASE}/${path.replace(/^\/+/, '')}`);
    if (query) Object.entries(query).forEach(([k, v]) => v != null && url.searchParams.set(k, v));
    const headers = { 'Content-Type': 'application/json' };
    if (this.token) headers['Authorization'] = `Bearer ${this.token}`;
    const opts = { method, headers };
    if (body && method !== 'GET') opts.body = JSON.stringify(body);
    const res  = await fetch(url.toString(), opts);
    const ct   = res.headers.get('content-type') || '';
    const data = ct.includes('json') ? await res.json() : await res.text();
    if (!res.ok) { const err = new Error(data?.message || `HTTP ${res.status}`); err.status = res.status; err.data = data; throw err; }
    return data;
  },

  get:    (p, q) => Api.req('GET',    p, null, q),
  post:   (p, b) => Api.req('POST',   p, b),
  put:    (p, b) => Api.req('PUT',    p, b),
  patch:  (p, b) => Api.req('PATCH',  p, b),
  delete: (p)    => Api.req('DELETE', p),

  // Direct URL for links/iframes that the browser fetches itself (e.g. PDF
  // preview/download) — auth rides on the dos_token cookie the backend sets
  // at login, not the Bearer header, since <iframe>/<a> can't attach one.
  url: (p) => `${BASE}/${p.replace(/^\/+/, '')}`,
};

export default Api;
