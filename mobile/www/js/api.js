/**
 * Money Wise — API client.
 *
 * Talks to the MoneyWise PHP REST API (api/config.php) over HTTPS using a
 * long-lived bearer token (minted at login/register, revoked at logout).
 * The token is stored locally; only its SHA-256 hash ever lives on the server.
 *
 * Handles: JSON errors from the server, 401 (session gone -> app shows login),
 * offline detection, and a small localStorage cache so the last-loaded screens
 * stay viewable without a connection.
 */
(function () {
  'use strict';

  var TOKEN_KEY = 'mw_token';
  var USER_KEY = 'mw_user';
  var CACHE_KEY = 'mw_cache';

  function store(k, v) {
    try { localStorage.setItem(k, v); } catch (e) { /* private mode */ }
  }
  function unstore(k) {
    try { localStorage.removeItem(k); } catch (e) { /* ignore */ }
  }
  function read(k) {
    try { return localStorage.getItem(k); } catch (e) { return null; }
  }
  function readJSON(k) {
    try { return JSON.parse(read(k) || 'null'); } catch (e) { return null; }
  }

  function OfflineError() {
    var e = new Error('You are offline.');
    e.offline = true;
    return e;
  }

  function ApiError(message, status) {
    var e = new Error(message);
    e.status = status;
    e.apiError = true;
    if (status === 401) e.unauthorized = true;
    return e;
  }

  function isOnline() {
    return typeof navigator !== 'undefined' && navigator.onLine;
  }

  function getToken() { return read(TOKEN_KEY); }
  function setToken(t) { t ? store(TOKEN_KEY, t) : unstore(TOKEN_KEY); }
  function getUser() { return readJSON(USER_KEY); }
  function setUser(u) { u ? store(USER_KEY, JSON.stringify(u)) : unstore(USER_KEY); }

  function cachePut(key, data) {
    try {
      var all = readJSON(CACHE_KEY) || {};
      all[key] = { t: Date.now(), data: data };
      store(CACHE_KEY, JSON.stringify(all));
    } catch (e) { /* ignore */ }
  }
  function cacheGet(key) {
    try {
      var all = readJSON(CACHE_KEY) || {};
      return all[key] ? all[key].data : null;
    } catch (e) { return null; }
  }

  function queryString(params) {
    var parts = [];
    for (var k in params) {
      if (params[k] !== undefined && params[k] !== null && params[k] !== '') {
        parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k]));
      }
    }
    return parts.length ? '?' + parts.join('&') : '';
  }

  async function request(method, path, params, data, opts) {
    opts = opts || {};
    var url = MW_API_BASE + path + queryString(params || {});
    var cacheKey = opts.cacheKey || (method + ' ' + url);

    if (method === 'GET' && !isOnline()) {
      var cached = cacheGet(cacheKey);
      if (cached) return cached;
      throw OfflineError();
    }

    var req = { method: method, headers: {} };
    var token = getToken();
    if (token) req.headers['Authorization'] = 'Bearer ' + token;
    if (data !== undefined) {
      req.headers['Content-Type'] = 'application/json';
      req.body = JSON.stringify(data);
    }

    var res;
    try {
      res = await fetch(url, req);
    } catch (e) {
      if (!isOnline()) throw OfflineError();
      throw new Error('Cannot reach the server. Check your connection.');
    }

    var json = null;
    try { json = await res.json(); } catch (e) { /* non-JSON */ }

    if (!res.ok) {
      var msg = (json && json.error) || 'Request failed (' + res.status + ').';
      var err = ApiError(msg, res.status);
      // A 401 only means "session expired" when we were actually sending a token;
      // e.g. a bad login (401) must show the server's own message instead.
      err.unauthorized = !!token && res.status === 401;
      throw err;
    }
    if (json && json.ok === false) {
      throw ApiError(json.error || 'Request failed.', res.status);
    }
    if (method === 'GET' && opts.cache) cachePut(cacheKey, json);
    return json;
  }

  // Public client
  window.MWAPI = {
    getToken: getToken,
    getUser: getUser,
    setUser: setUser,
    isOnline: isOnline,
    OfflineError: OfflineError,
    ApiError: ApiError,

    // auth --------------------------------------------------------------
    session: function () {
      return request('GET', '/auth.php', { action: 'session' });
    },
    login: async function (email, password) {
      var r = await request('POST', '/auth.php', { action: 'login' }, {
        email: email, password: password, want_token: '1'
      }, { cache: false });
      setToken(r.token);
      setUser(r.user);
      return r;
    },
    register: async function (name, email, password) {
      var r = await request('POST', '/auth.php', { action: 'register' }, {
        name: name, email: email, password: password, gender: 'male', want_token: '1'
      }, { cache: false });
      setToken(r.token);
      setUser(r.user);
      return r;
    },
    logout: async function () {
      try {
        await request('POST', '/auth.php', { action: 'logout' }, {});
      } catch (e) { /* ignore — token is revoked best-effort */ }
      setToken(null);
      setUser(null);
    },

    // transactions --------------------------------------------------------
    txList: function (params) {
      return request('GET', '/transactions.php', params, undefined,
        { cache: true, cacheKey: 'tx ' + JSON.stringify(params) });
    },
    txAdd: function (data) {
      return request('POST', '/transactions.php', {}, data, { cache: false });
    },
    txUpdate: function (data) {
      return request('PUT', '/transactions.php', {}, data, { cache: false });
    },
    txDelete: function (id) {
      return request('DELETE', '/transactions.php', { id: id }, undefined, { cache: false });
    },
    txEraseAll: function () {
      return request('DELETE', '/transactions.php', {}, undefined, { cache: false });
    },

    // clear (persistent hide) --------------------------------------------
    clearGet: function () {
      return request('GET', '/clear.php', {}, undefined, { cache: true, cacheKey: 'clear' });
    },
    clearHide: function (txIds) {
      return request('POST', '/clear.php', {}, { action: 'clear', tx_ids: txIds }, { cache: false });
    },
    clearRestore: function (txIds) {
      return request('POST', '/clear.php', {}, { action: 'restore', tx_ids: txIds }, { cache: false });
    },
    clearBalance: function (on) {
      return request('POST', '/clear.php', {}, { action: on ? 'clear_balance' : 'restore_balance' }, { cache: false });
    },

    // profile --------------------------------------------------------------
    profileUpdate: function (data) {
      return request('PUT', '/profile.php', {}, { action: 'profile', name: data.name, email: data.email, gender: data.gender }, { cache: false });
    },
    passwordChange: function (current, next) {
      return request('PUT', '/profile.php', {}, { action: 'password', current: current, new: next }, { cache: false });
    },

    // salary ---------------------------------------------------------------
    salaryMine: function () {
      return request('GET', '/salary.php', { action: 'mine' }, undefined, { cache: true, cacheKey: 'salary-mine' });
    },
    salaryList: function () {
      return request('GET', '/salary.php', { action: 'list' }, undefined, { cache: true, cacheKey: 'salary-list' });
    },
    salaryHistory: function (params) {
      return request('GET', '/salary.php', Object.assign({ action: 'history' }, params), undefined, { cache: true, cacheKey: 'salary-hist ' + JSON.stringify(params) });
    },
    salarySet: function (data) {
      return request('POST', '/salary.php', { action: 'set' }, data, { cache: false });
    },

    // admin ----------------------------------------------------------------
    adminUsers: function () {
      return request('GET', '/admin.php', { action: 'users' }, undefined, { cache: true, cacheKey: 'admin-users' });
    },
    adminView: function (params) {
      return request('GET', '/admin.php', Object.assign({ action: 'view' }, params), undefined, { cache: true, cacheKey: 'admin-view ' + JSON.stringify(params) });
    },
    adminDelete: function (userId) {
      return request('DELETE', '/admin.php', { action: 'delete', userId: userId }, undefined, { cache: false });
    }
  };
})();
