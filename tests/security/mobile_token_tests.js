/**
 * MoneyWise - Mobile Token Authentication Security Suite
 * ------------------------------------------------------------------
 * Verifies the bearer-token flow the Capacitor app uses:
 *   - tokens are minted only on request (web app behavior unchanged)
 *   - tokens grant access only to the owner's own data (IDOR)
 *   - admin endpoints still require an admin token (RBAC)
 *   - logout revokes the token; password change invalidates tokens
 *   - CORS preflight allows the Capacitor WebView origins
 *   - malformed tokens are ignored
 *
 * Run:  node tests/security/mobile_token_tests.js
 * Exit: 0 if no FAIL/ERROR, 1 otherwise.
 */

'use strict';

const BASE = 'http://localhost/MoneyManagement1';
const ADMIN = { email: 'Admin0112@gmail.com', password: 'Admin@0112' };

const results = [];
let nPass = 0, nFail = 0, nErr = 0;
const TS = Date.now();
const PW = 'MobPass#123';

async function api(path, { method = 'GET', body, headers } = {}) {
  const opts = { method, headers: Object.assign({}, headers, { 'Content-Type': 'application/json' }) };
  if (body !== undefined) opts.body = JSON.stringify(body);
  const res = await fetch(BASE + path, opts);
  let data = null;
  try { data = await res.json(); } catch (e) { /* non-JSON */ }
  return { status: res.status, data, headers: res.headers };
}

async function t(id, name, fn) {
  let verdict, detail;
  try {
    const r = await fn();
    if (r === true) { verdict = true; }
    else { verdict = false; detail = typeof r === 'string' ? r : undefined; }
  } catch (e) {
    nErr++; results.push({ id, name, status: 'ERROR', detail: e.message }); return;
  }
  if (verdict) { nPass++; results.push({ id, name, status: 'PASS' }); }
  else { nFail++; results.push({ id, name, status: 'FAIL', detail }); }
}

(async () => {
  const a = { email: 'mob_a_' + TS + '@test.local', token: null };
  const b = { email: 'mob_b_' + TS + '@test.local', token: null };
  let adminToken = null;
  let txA = null;

  await t('MT-01', 'Register user A with want_token=1 returns a 64-hex token', async () => {
    const r = await api('/api/auth.php?action=register&want_token=1', { method: 'POST', body: { name: 'Token A', email: a.email, password: PW } });
    if (r.status !== 201) return 'expected 201, got ' + r.status;
    if (!r.data.token) return 'no token in response';
    if (!/^[a-f0-9]{64}$/.test(r.data.token)) return 'token not 64 hex chars';
    a.token = r.data.token;
    return true;
  });

  await t('MT-02', 'Register without want_token leaks no token (backward compat)', async () => {
    const r = await api('/api/auth.php?action=register', { method: 'POST', body: { name: 'Token B', email: b.email, password: PW } });
    if (r.status !== 201) return 'expected 201, got ' + r.status;
    if ('token' in r.data) return 'token leaked when not requested';
    return true;
  });

  await t('MT-03', 'Admin login with want_token=1 mints an admin token', async () => {
    const r = await api('/api/auth.php?action=login&want_token=1', { method: 'POST', body: { email: ADMIN.email, password: ADMIN.password } });
    if (r.status !== 200 || !r.data.token) return 'no token';
    if (r.data.user.is_admin !== 1) return 'not admin';
    adminToken = r.data.token;
    return true;
  });

  await t('MT-04', 'User B login mints a token', async () => {
    const r = await api('/api/auth.php?action=login&want_token=1', { method: 'POST', body: { email: b.email, password: PW } });
    if (r.status !== 200 || !r.data.token) return 'no token';
    b.token = r.data.token;
    return true;
  });

  const authA = { Authorization: 'Bearer ' + a.token };
  const authB = { Authorization: 'Bearer ' + b.token };

  await t('MT-05', 'Bearer token authenticates the right user (session)', async () => {
    const r = await api('/api/auth.php?action=session', { headers: authA });
    return (r.status === 200 && r.data.user && r.data.user.email === a.email) ? true : 'wrong user';
  });

  await t('MT-06', 'No cookie + no token => 401 on protected endpoint', async () => {
    const r = await api('/api/transactions.php');
    return r.status === 401 ? true : 'expected 401, got ' + r.status;
  });

  await t('MT-07', 'Malformed Authorization header is ignored (401)', async () => {
    const r = await api('/api/transactions.php', { headers: { Authorization: 'Bearer not-a-valid-hex-token!' } });
    return r.status === 401 ? true : 'expected 401, got ' + r.status;
  });

  await t('MT-08', 'User A token can add a transaction owned by A', async () => {
    const r = await api('/api/transactions.php', { method: 'POST', headers: authA, body: { type: 'expense', amount: 123.45, category: 'Groceries', notes: 'token test', date: '2026-08-10' } });
    if (r.status !== 201) return 'expected 201, got ' + r.status;
    txA = r.data.id;
    return true;
  });

  await t('MT-09', 'User B token cannot edit user A transaction (IDOR)', async () => {
    const r = await api('/api/transactions.php', { method: 'PUT', headers: authB, body: { id: txA, type: 'expense', amount: 1, category: 'X', date: '2026-08-10' } });
    return r.status === 404 ? true : 'expected 404, got ' + r.status;
  });

  await t('MT-10', 'User B token cannot delete user A transaction (IDOR)', async () => {
    const r = await api('/api/transactions.php?mode=all', { method: 'DELETE', headers: authB, body: {} });
    // No id given => user B erases their OWN (empty) list; A's record must survive.
    await new Promise(r2 => setTimeout(r2, 200));
    const chk = await api('/api/transactions.php', { headers: authA });
    const mine = (chk.data.transactions || []).some(t2 => t2.id === txA);
    return mine ? true : 'user A transaction was lost';
  });

  await t('MT-11', 'Non-admin token is rejected on admin endpoint (RBAC 403)', async () => {
    const r = await api('/api/admin.php?action=users', { headers: authA });
    return r.status === 403 ? true : 'expected 403, got ' + r.status;
  });

  await t('MT-12', 'Admin token is accepted on admin endpoint', async () => {
    const r = await api('/api/admin.php?action=users', { headers: { Authorization: 'Bearer ' + adminToken } });
    return (r.status === 200 && Array.isArray(r.data.users)) ? true : 'expected user list';
  });

  await t('MT-13', 'Admin token can delete the disposable user B', async () => {
    const users = (await api('/api/admin.php?action=users', { headers: { Authorization: 'Bearer ' + adminToken } })).data.users;
    const target = users.find(u => u.email === b.email);
    if (!target) return 'user B not found';
    const r = await api('/api/admin.php?action=delete&userId=' + target.id, { headers: { Authorization: 'Bearer ' + adminToken } });
    return r.status === 200 ? true : 'expected 200, got ' + r.status;
  });

  await t('MT-14', 'Logout revokes the token (401 afterwards)', async () => {
    await api('/api/auth.php?action=logout', { method: 'POST', headers: authA });
    const r = await api('/api/transactions.php', { headers: authA });
    return r.status === 401 ? true : 'expected 401, got ' + r.status;
  });

  await t('MT-15', 'CORS preflight (OPTIONS) allows the Android WebView origin', async () => {
    const res = await fetch(BASE + '/api/transactions.php', {
      method: 'OPTIONS',
      headers: { Origin: 'https://localhost', 'Access-Control-Request-Method': 'GET', 'Access-Control-Request-Headers': 'authorization,content-type' }
    });
    if (res.status !== 204) return 'expected 204, got ' + res.status;
    const allow = res.headers.get('Access-Control-Allow-Origin');
    return allow === 'https://localhost' ? true : 'missing/mismatched allow-origin: ' + allow;
  });

  await t('MT-16', 'CORS allows the iOS WebView origin', async () => {
    const res = await fetch(BASE + '/api/auth.php?action=session', {
      method: 'GET',
      headers: { Origin: 'capacitor://localhost' }
    });
    const allow = res.headers.get('Access-Control-Allow-Origin');
    return allow === 'capacitor://localhost' ? true : 'missing/mismatched allow-origin: ' + allow;
  });

  await t('MT-17', 'Password change invalidates previously issued tokens', async () => {
    const t2 = (await api('/api/auth.php?action=login&want_token=1', { method: 'POST', body: { email: a.email, password: PW } })).data.token;
    const h2 = { Authorization: 'Bearer ' + t2 };
    const pc = await api('/api/profile.php', { method: 'PUT', headers: h2, body: { action: 'password', current: PW, new: 'NewMobPass#456' } });
    if (pc.status !== 200) return 'password change failed: ' + pc.status;
    const r = await api('/api/transactions.php', { headers: h2 });
    return r.status === 401 ? true : 'old token still works after password change';
  });

  await t('MT-18', 'Cleanup: delete disposable user A', async () => {
    const users = (await api('/api/admin.php?action=users', { headers: { Authorization: 'Bearer ' + adminToken } })).data.users;
    const target = users.find(u => u.email === a.email);
    if (!target) return 'user A not found (already gone?)';
    const r = await api('/api/admin.php?action=delete&userId=' + target.id, { headers: { Authorization: 'Bearer ' + adminToken } });
    return r.status === 200 ? true : 'expected 200, got ' + r.status;
  });

  // ── report ────────────────────────────────────────────────────────────
  console.log('\n=== MoneyWise Mobile Token Auth Security Suite ===\n');
  results.forEach(r => console.log('  ' + r.status.padEnd(5) + '  ' + r.id + '  ' + r.name + (r.detail ? '\n          -> ' + r.detail : '')));
  console.log('\n  PASS ' + nPass + '  FAIL ' + nFail + '  ERROR ' + nErr + '  (total ' + results.length + ')');
  if (nFail || nErr) { console.log('\nRESULT: FAIL'); process.exit(1); }
  console.log('\nRESULT: PASS');
})();
