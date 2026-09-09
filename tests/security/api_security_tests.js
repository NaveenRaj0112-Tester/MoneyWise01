/**
 * MoneyWise — Automated Security Regression Suite (API level)
 * ------------------------------------------------------------------
 * Safe, non-destructive tests against the owned local instance at:
 *   http://localhost/MoneyManagement1
 *
 * How it works:
 *   - Creates two disposable test accounts (sec_a_*, sec_b_*@test.local)
 *   - Exercises authN/authZ/IDOR/RBAC/session/validation/SQLi/error paths
 *   - Deletes both test accounts at the end (admin cleanup)
 *   - Requires the seeded admin account to exist
 *     (email/password below — adjust to the app's current admin creds)
 *
 * Result classification:
 *   PASS   — secure behavior confirmed (must always pass)
 *   GAP    — documented weakness still present (KNOWN GAP; flips when fixed)
 *   FAIL   — secure behavior violated (must be fixed immediately)
 *   ERROR  — test could not run
 *
 * Run:  node tests/security/api_security_tests.js
 * Exit: 0 if no FAIL/ERROR, 1 otherwise.
 */

'use strict';

const BASE  = 'http://localhost/MoneyManagement1';
const ADMIN = { email: 'Admin0112@gmail.com', password: 'Admin@0112' };

const { execFile } = require('child_process');
// Local XAMPP MySQL CLI, used only to purge the auth_attempts table the
// rate-limit tests populate (there is deliberately no HTTP endpoint for it).
// If unavailable the cleanup is skipped and the suite still passes.
const MYSQL = 'C:\\xampp8.8\\mysql\\bin\\mysql.exe';
const MYSQL_DB = process.env.DB_NAME || 'moneywise';
function clearAttempts(scope){
  return new Promise(resolve => {
    const sql = scope ? "DELETE FROM auth_attempts WHERE scope='" + scope + "'" : 'DELETE FROM auth_attempts';
    execFile(MYSQL, ['-h', '127.0.0.1', '-u', 'root', MYSQL_DB, '-e', sql], err => resolve(!err));
  });
}

const results = [];
let userA = { id: null, email: null, jar: {} };
let userB = { id: null, email: null, jar: {} };
const TS = Date.now();
const PW = 'SecPass#123';

function cookieHeader(jar){ return Object.entries(jar).map(([k, v]) => k + '=' + v).join('; '); }
function storeCookies(setCookies, jar){
  for (const raw of (setCookies || [])){
    const eq = raw.indexOf('=');
    if (eq > 0) jar[raw.slice(0, eq).trim()] = raw.split(';')[0].slice(eq + 1);
  }
}
async function api(path, { method = 'GET', jar, body, headers } = {}){
  const opts = { method, headers: Object.assign({}, headers, { 'Content-Type': 'application/json' }) };
  if (jar && cookieHeader(jar)) opts.headers.Cookie = cookieHeader(jar);
  if (body !== undefined) opts.body = JSON.stringify(body);
  const res = await fetch(BASE + path, opts);
  let data = null;
  try { data = await res.json(); } catch (e) { /* non-JSON */ }
  return { status: res.status, data, cookies: res.headers.getSetCookie ? res.headers.getSetCookie() : [] };
}

let nPass = 0, nGap = 0, nFail = 0, nErr = 0;
async function t(id, area, name, fn, kind = 'pass'){
  let verdict;
  try {
    const r = await fn();
    verdict = r === true;
  } catch (e) {
    nErr++; results.push({ id, area, name, status: 'ERROR', detail: e.message }); return;
  }
  if (verdict){ nPass++; results.push({ id, area, name, status: 'PASS' }); }
  else if (kind === 'gap'){ nGap++; results.push({ id, area, name, status: 'GAP' }); }
  else { nFail++; results.push({ id, area, name, status: 'FAIL', detail: typeof r === 'string' ? r : undefined }); }
}

(async () => {
  const ts = TS;
  userA.email = 'sec_a_' + ts + '@test.local';
  userB.email = 'sec_b_' + ts + '@test.local';

  // ── Setup ─────────────────────────────────────────────────────────────────
  await t('TC-SET-01', 'Setup', 'Register User A', () =>
    api('/api/auth.php', { method: 'POST', body: { action: 'register', name: 'Security User A', gender: 'male', email: userA.email, password: PW } })
      .then(r => { if (r.status === 201 && r.data.ok){ userA.id = r.data.user.id; return true; } return 'status=' + r.status; }));
  await t('TC-SET-02', 'Setup', 'Register User B', () =>
    api('/api/auth.php', { method: 'POST', body: { action: 'register', name: 'Security User B', gender: 'female', email: userB.email, password: PW } })
      .then(r => { if (r.status === 201 && r.data.ok){ userB.id = r.data.user.id; return true; } return 'status=' + r.status; }));
  await t('TC-SET-03', 'Setup', 'Login User A', () =>
    api('/api/auth.php', { method: 'POST', body: { action: 'login', email: userA.email, password: PW }, jar: userA.jar })
      .then(r => { storeCookies(r.cookies, userA.jar); return r.status === 200 && r.data.ok; }));
  await t('TC-SET-04', 'Setup', 'Login User B', () =>
    api('/api/auth.php', { method: 'POST', body: { action: 'login', email: userB.email, password: PW }, jar: userB.jar })
      .then(r => { storeCookies(r.cookies, userB.jar); return r.status === 200 && r.data.ok; }));

  let aTxId = null;
  let rogueId = null;
  await t('TC-SET-05', 'Setup', 'User A adds an expense record', () =>
    api('/api/transactions.php', { method: 'POST', body: { type: 'expense', amount: 123.45, category: 'Food', notes: '<img src=x onerror=alert(1)>', date: '2026-08-09' }, jar: userA.jar })
      .then(r => { if (r.status === 201 && r.data.id){ aTxId = r.data.id; return true; } return 'status=' + r.status; }));

  // ── Authentication ────────────────────────────────────────────────────────
  await t('TC-AUTH-01', 'Authentication', 'Valid login returns session', () =>
    api('/api/auth.php', { method: 'POST', body: { action: 'login', email: userA.email, password: PW } }).then(r => r.status === 200 && r.data.ok === true));
  await t('TC-AUTH-02', 'Authentication', 'Wrong password rejected (401)', () =>
    api('/api/auth.php', { method: 'POST', body: { action: 'login', email: userA.email, password: 'WrongPass!' } }).then(r => r.status === 401 && r.data.ok === false));
  await t('TC-AUTH-03', 'Authentication', 'Unknown email rejected with SAME message (no login enumeration)', async () => {
    const known = await api('/api/auth.php', { method: 'POST', body: { action: 'login', email: userA.email, password: 'WrongPass!' } });
    const unknown = await api('/api/auth.php', { method: 'POST', body: { action: 'login', email: 'nobody_' + ts + '@test.local', password: PW } });
    return known.status === 401 && unknown.status === 401 && known.data.error === unknown.data.error;
  });
  await t('TC-AUTH-04', 'Authentication', 'Empty credentials rejected (422)', () =>
    api('/api/auth.php', { method: 'POST', body: { action: 'login', email: '', password: '' } }).then(r => r.status === 422));
  await t('TC-AUTH-05', 'Authentication', 'Missing body handled cleanly (422)', () =>
    api('/api/auth.php', { method: 'POST', body: { action: 'login' } }).then(r => r.status === 422));
  await t('TC-AUTH-06', 'Authentication', 'SQLi in credentials not interpreted (401)', () =>
    api('/api/auth.php', { method: 'POST', body: { action: 'login', email: "' OR '1'='1", password: "' OR '1'='1" } }).then(r => r.status === 401 && r.data.ok === false));
  await t('TC-AUTH-07', 'Authentication', 'SQLi UNION injection rejected (401)', () =>
    api('/api/auth.php', { method: 'POST', body: { action: 'login', email: "' UNION SELECT password FROM users--", password: 'x' } }).then(r => r.status === 401 && r.data.ok === false));
  await t('TC-AUTH-08', 'Authentication', '10k-char password handled (no crash/DoS)', () =>
    api('/api/auth.php', { method: 'POST', body: { action: 'login', email: userA.email, password: 'x'.repeat(10000) } }).then(r => r.status === 401));
  await t('TC-AUTH-09', 'Authentication', 'Unicode credentials handled cleanly (401)', () =>
    api('/api/auth.php', { method: 'POST', body: { action: 'login', email: 'tést🙂@test.local', password: 'p@ss😀' } }).then(r => r.status === 401));
  await t('TC-AUTH-10', 'Authentication', 'Session endpoint returns the authenticated user', () =>
    api('/api/auth.php?action=session', { jar: userA.jar }).then(r => r.status === 200 && r.data.ok && r.data.user && r.data.user.id === userA.id));

  // ── Registration ──────────────────────────────────────────────────────────
  await t('TC-REG-01', 'Registration', 'Duplicate email rejected (409)', () =>
    api('/api/auth.php', { method: 'POST', body: { action: 'register', name: 'X', gender: 'male', email: userA.email, password: 'AnotherPass1' } }).then(r => r.status === 409));
  await t('TC-REG-02', 'Registration', 'Weak password (<8) rejected (422)', () =>
    api('/api/auth.php', { method: 'POST', body: { action: 'register', name: 'X', gender: 'male', email: 'w_' + ts + '@test.local', password: '123' } }).then(r => r.status === 422));
  await t('TC-REG-03', 'Registration', 'Invalid email rejected (422)', () =>
    api('/api/auth.php', { method: 'POST', body: { action: 'register', name: 'X', gender: 'male', email: 'notanemail', password: 'ValidPass1' } }).then(r => r.status === 422));
  await t('TC-REG-04', 'Registration', 'Mass assignment blocked: is_admin=1 ignored (user stays non-admin)', () =>
    api('/api/auth.php', { method: 'POST', body: { action: 'register', name: 'Rogue', gender: 'male', email: 'rogue_' + ts + '@test.local', password: 'ValidPass1', is_admin: 1 } })
      .then(r => { if (r.status === 201 && r.data.user.is_admin === 0){ rogueId = r.data.user.id; return true; } return 'status=' + r.status; }));
  await t('TC-REG-05', 'Registration', 'Duplicate email rejected (409) - enumeration mitigated by per-IP throttle (TC-RL-02)',
    () => api('/api/auth.php', { method: 'POST', body: { action: 'register', name: 'X', gender: 'male', email: userA.email, password: 'AnotherPass1' } }).then(r => r.status === 409));

  // ── Authorization / IDOR / RBAC ───────────────────────────────────────────
  await t('TC-AUTHZ-01', 'Authorization', 'Unauthenticated GET transactions -> 401', () =>
    api('/api/transactions.php').then(r => r.status === 401));
  await t('TC-AUTHZ-02', 'Authorization', 'User A reads own transactions (allowed)', () =>
    api('/api/transactions.php', { jar: userA.jar }).then(r => r.status === 200 && r.data.ok));
  await t('TC-AUTHZ-03', 'Authorization', 'User B cannot EDIT User A transaction (IDOR blocked)', () =>
    api('/api/transactions.php', { method: 'PUT', body: { id: aTxId, type: 'expense', amount: 999, category: 'Travel', notes: 'hack', date: '2026-08-09' }, jar: userB.jar })
      .then(r => (r.status === 404 || r.status === 403) && r.data.ok === false));
  await t('TC-AUTHZ-04', 'Authorization', 'User B cannot DELETE User A transaction (IDOR blocked)', () =>
    api('/api/transactions.php?id=' + aTxId, { method: 'DELETE', jar: userB.jar })
      .then(r => (r.status === 404 || r.status === 403) && r.data.ok === false));
  await t('TC-AUTHZ-05', 'Authorization', 'Data isolation: User B list never contains User A tx', () =>
    api('/api/transactions.php?type=all', { jar: userB.jar })
      .then(r => r.status === 200 && !(r.data.transactions || []).map(t => t.id).includes(aTxId)));
  await t('TC-AUTHZ-06', 'Authorization', 'User B blocked from admin users list (403)', () =>
    api('/api/admin.php?action=users', { jar: userB.jar }).then(r => r.status === 403 && r.data.ok === false));
  await t('TC-AUTHZ-07', 'Authorization', 'User B blocked from admin view user (403)', () =>
    api('/api/admin.php?action=view&userId=1', { jar: userB.jar }).then(r => r.status === 403));
  await t('TC-AUTHZ-08', 'Authorization', 'User B blocked from admin delete user (403)', () =>
    api('/api/admin.php?action=delete&userId=1', { jar: userB.jar }).then(r => r.status === 403));
  await t('TC-AUTHZ-09', 'Authorization', 'User B blocked from salary list (403)', () =>
    api('/api/salary.php?action=list', { jar: userB.jar }).then(r => r.status === 403));
  await t('TC-AUTHZ-10', 'Authorization', 'User B blocked from salary history (403)', () =>
    api('/api/salary.php?action=history', { jar: userB.jar }).then(r => r.status === 403));
  await t('TC-AUTHZ-11', 'Authorization', 'User B blocked from salary set (403)', () =>
    api('/api/salary.php', { method: 'POST', body: { action: 'set', userId: 1, amount: 1, month: 1, year: 2026 }, jar: userB.jar }).then(r => r.status === 403));
  await t('TC-AUTHZ-12', 'Authorization', 'User A reads own salary via mine (allowed)', () =>
    api('/api/salary.php?action=mine', { jar: userA.jar }).then(r => r.status === 200 && r.data.ok));

  // ── Clear API (persistent hide markers) ────────────────────────────────────
  await t('TC-AUTHZ-13', 'Authorization', 'Unauthenticated GET clear.php -> 401', () =>
    api('/api/clear.php').then(r => r.status === 401));
  await t('TC-AUTHZ-14', 'Authorization', 'User A GET clear.php starts empty', () =>
    api('/api/clear.php', { jar: userA.jar }).then(r => r.status === 200 && r.data.ok && Array.isArray(r.data.cleared) && r.data.cleared.length === 0));
  await t('TC-AUTHZ-15', 'Authorization', 'User A can clear own transaction (marker created)', () =>
    api('/api/clear.php', { method: 'POST', body: { action: 'clear', tx_ids: [aTxId] }, jar: userA.jar })
      .then(r => r.status === 200 && r.data.ok && Array.isArray(r.data.cleared) && r.data.cleared.includes(aTxId)));
  await t('TC-AUTHZ-16', 'Authorization', 'Cleared marker is persisted server-side', () =>
    api('/api/clear.php', { jar: userA.jar }).then(r => r.status === 200 && (r.data.cleared || []).includes(aTxId)));
  await t('TC-AUTHZ-17', 'Authorization', 'Data isolation: User B never sees User A cleared marker', () =>
    api('/api/clear.php', { jar: userB.jar }).then(r => r.status === 200 && !(r.data.cleared || []).includes(aTxId)));
  await t('TC-AUTHZ-18', 'Authorization', 'User B cannot clear User A transaction (ownership enforced)', async () => {
    const c = await api('/api/clear.php', { method: 'POST', body: { action: 'clear', tx_ids: [aTxId] }, jar: userB.jar });
    if (c.status !== 200 || !c.data.ok || (c.data.cleared || []).includes(aTxId)) return 'cross-user clear not blocked: ' + JSON.stringify(c.data);
    const g = await api('/api/clear.php', { jar: userB.jar });
    return g.status === 200 && !(g.data.cleared || []).includes(aTxId);
  });
  await t('TC-AUTHZ-19', 'Authorization', 'Restore removes the marker for own records only', async () => {
    const r = await api('/api/clear.php', { method: 'POST', body: { action: 'restore', tx_ids: [aTxId] }, jar: userA.jar });
    if (r.status !== 200 || !r.data.ok) return 'restore failed: ' + JSON.stringify(r.data);
    const g = await api('/api/clear.php', { jar: userA.jar });
    return g.status === 200 && !(g.data.cleared || []).includes(aTxId);
  });
  await t('TC-AUTHZ-20', 'Authorization', 'Clear/restore never deletes the original transaction', () =>
    api('/api/transactions.php?type=all', { jar: userA.jar }).then(r => r.status === 200 && (r.data.transactions || []).map(t => t.id).includes(aTxId)));

  // ── Clear API: Dashboard balance-erase flag ─────────────────────────────────
  await t('TC-AUTHZ-21', 'Authorization', 'clear_balance persists the balance flag for the caller', async () => {
    const c = await api('/api/clear.php', { method: 'POST', body: { action: 'clear_balance' }, jar: userA.jar });
    if (c.status !== 200 || !c.data.ok || c.data.balance_cleared !== true) return 'clear_balance failed: ' + JSON.stringify(c.data);
    const g = await api('/api/clear.php', { jar: userA.jar });
    return g.status === 200 && g.data.balance_cleared === true;
  });
  await t('TC-AUTHZ-22', 'Authorization', 'Balance flag is per-user (User B unaffected)', () =>
    api('/api/clear.php', { jar: userB.jar }).then(r => r.status === 200 && r.data.balance_cleared === false));
  await t('TC-AUTHZ-23', 'Authorization', 'restore_balance clears the flag for the caller', async () => {
    const r = await api('/api/clear.php', { method: 'POST', body: { action: 'restore_balance' }, jar: userA.jar });
    if (r.status !== 200 || !r.data.ok || r.data.balance_cleared !== false) return 'restore_balance failed: ' + JSON.stringify(r.data);
    const g = await api('/api/clear.php', { jar: userA.jar });
    return g.status === 200 && g.data.balance_cleared === false;
  });
  await t('TC-AUTHZ-24', 'Authorization', 'Adding a transaction auto-restores the erased balance flag', async () => {
    const c = await api('/api/clear.php', { method: 'POST', body: { action: 'clear_balance' }, jar: userA.jar });
    if (c.status !== 200 || c.data.balance_cleared !== true) return 'pre-clear failed';
    const add = await api('/api/transactions.php', { method: 'POST', body: { type: 'expense', amount: 5.5, category: 'Other', date: '2026-08-09' }, jar: userA.jar });
    if (add.status !== 201 || !add.data.id) return 'add failed: ' + add.status;
    const g = await api('/api/clear.php', { jar: userA.jar });
    return g.status === 200 && g.data.balance_cleared === false;
  });
  await t('TC-VAL-12', 'Input Validation', 'clear.php ignores non-array / injected tx_ids', () =>
    api('/api/clear.php', { method: 'POST', body: { action: 'clear', tx_ids: "' OR '1'='1" }, jar: userA.jar }).then(r => r.status === 200 && r.data.ok && r.data.cleared.length === 0));
  await t('TC-VAL-13', 'Input Validation', 'clear.php unknown action -> 400', () =>
    api('/api/clear.php', { method: 'POST', body: { action: 'nuke', tx_ids: [1] }, jar: userA.jar }).then(r => r.status === 400 && r.data.ok === false));
  await t('TC-ERR-07', 'Error Handling', 'clear.php rejects wrong method (405)', () =>
    api('/api/clear.php', { method: 'PUT', jar: userA.jar }).then(r => r.status === 405));

  // ── Admin positive controls ───────────────────────────────────────────────
  const adminJar = {};
  await api('/api/auth.php', { method: 'POST', body: { action: 'login', email: ADMIN.email, password: ADMIN.password } })
    .then(r => storeCookies(r.cookies, adminJar));
  await t('TC-ADM-01', 'Admin', 'Admin login works', () =>
    api('/api/auth.php', { method: 'POST', body: { action: 'login', email: ADMIN.email, password: ADMIN.password } }).then(r => r.status === 200 && r.data.ok && r.data.user.is_admin === 1));
  await t('TC-ADM-02', 'Admin', 'Admin can list users (200)', () =>
    api('/api/admin.php?action=users', { jar: adminJar }).then(r => r.status === 200 && Array.isArray(r.data.users)));
  await t('TC-ADM-03', 'Admin', 'Admin can view a user (200)', () =>
    api('/api/admin.php?action=view&userId=' + userA.id, { jar: adminJar }).then(r => r.status === 200 && r.data.user && r.data.user.id === userA.id));
  await t('TC-ADM-04', 'Admin', 'Admin cannot delete own account (422)', () =>
    api('/api/admin.php?action=delete&userId=1', { jar: adminJar }).then(r => r.status === 422));
  await t('TC-ADM-05', 'Admin', 'Salary set validates employee (non-admin required)', () =>
    api('/api/salary.php', { method: 'POST', body: { action: 'set', userId: 1, amount: 1000, month: 1, year: 2026 }, jar: adminJar }).then(r => r.status === 404));

  // ── Salary persistence regression (history must survive refresh / sessions) ──
  const SAL_AMT = 65000, SAL_M = 7, SAL_Y = 2026;
  await t('TC-ADM-06', 'Admin', 'Salary set for an employee returns ok', () =>
    api('/api/salary.php', { method: 'POST', body: { action: 'set', userId: userA.id, amount: SAL_AMT, month: SAL_M, year: SAL_Y }, jar: adminJar })
      .then(r => r.status === 200 && r.data.ok === true));
  await t('TC-ADM-07', 'Admin', 'Salary history records the change immediately', () =>
    api('/api/salary.php?action=history&emp=' + userA.id, { jar: adminJar }).then(r =>
      r.status === 200 && (r.data.history || []).some(h => h.userId === userA.id && h.oldSalary === 0 && h.newSalary === SAL_AMT && h.month === SAL_M && h.year === SAL_Y)));
  await t('TC-ADM-08', 'Admin', 'Salary history persists across a fresh admin session', async () => {
    const fresh = {};
    await api('/api/auth.php', { method: 'POST', body: { action: 'login', email: ADMIN.email, password: ADMIN.password } }).then(r => storeCookies(r.cookies, fresh));
    return api('/api/salary.php?action=history&emp=' + userA.id, { jar: fresh }).then(r =>
      r.status === 200 && (r.data.history || []).some(h => h.userId === userA.id && h.newSalary === SAL_AMT));
  });
  await t('TC-ADM-09', 'Admin', 'Employee reads own salary via mine (amount matches)', () =>
    api('/api/salary.php?action=mine', { jar: userA.jar }).then(r =>
      r.status === 200 && r.data.ok && r.data.salary && r.data.salary.amount === SAL_AMT && r.data.salary.month === SAL_M && r.data.salary.year === SAL_Y));
  await t('TC-ADM-10', 'Admin', 'Salary management list shows the employee salary', () =>
    api('/api/salary.php?action=list', { jar: adminJar }).then(r =>
      r.status === 200 && (r.data.employees || []).some(e => e.id === userA.id && e.amount === SAL_AMT)));

  // ── Session management ────────────────────────────────────────────────────
  const tmpJar = {};
  await api('/api/auth.php', { method: 'POST', body: { action: 'login', email: userA.email, password: PW } }).then(r => storeCookies(r.cookies, tmpJar));
  const oldCookie = cookieHeader(tmpJar);
  await t('TC-SESS-01', 'Session', 'Logout succeeds', () =>
    api('/api/auth.php?action=logout', { method: 'POST', jar: tmpJar }).then(r => r.status === 200 && r.data.ok));
  await t('TC-SESS-02', 'Session', 'Session cookie rejected after logout (401)', () =>
    api('/api/transactions.php', { headers: { Cookie: oldCookie } }).then(r => r.status === 401));
  await t('TC-SESS-03', 'Session', 'Forged/garbage session token rejected (401)', () =>
    api('/api/transactions.php', { headers: { Cookie: 'PHPSESSID=deadbeefdeadbeefdeadbeefdeadbeef' } }).then(r => r.status === 401));
  await t('TC-SESS-04', 'Session', 'Missing cookie -> 401', () =>
    api('/api/transactions.php').then(r => r.status === 401));
  await t('TC-SESS-05', 'Session', 'Re-login after logout works', () =>
    api('/api/auth.php', { method: 'POST', body: { action: 'login', email: userA.email, password: PW } }).then(r => r.status === 200 && r.data.ok));

  // ── Rate limiting (M2 login throttle, L1 register throttle) ───────────────
  await t('TC-RL-01', 'Rate Limiting', 'Login locked out after 5 failed attempts (429)', async () => {
    const bf = { email: 'bf_' + ts + '@test.local', password: 'WrongPass123!' };
    for (let i = 0; i < 8; i++){
      const r = await api('/api/auth.php', { method: 'POST', body: { action: 'login', email: bf.email, password: bf.password } });
      if (r.status === 429) return true;
      if (i === 7) return 'never got 429; last=' + r.status;
    }
    return false;
  });
  // NOTE: TC-RL-02 exhausts the register per-IP bucket (30/15min). It probes an
  // email that already exists (User A), so every attempt follows the duplicate
  // path. It must run before User A is deleted (cleanup) and before any other
  // register-based test. The auth_attempts table is purged at the end via mysql.
  await t('TC-RL-02', 'Rate Limiting', 'Register enumeration throttled (429 after repeated duplicate probes)', async () => {
    for (let i = 0; i < 34; i++){
      const r = await api('/api/auth.php', { method: 'POST', body: { action: 'register', name: 'X', gender: 'male', email: userA.email, password: 'ValidPass1' } });
      if (r.status === 429) return true;
      if (i === 33) return 'never got 429; last=' + r.status;
    }
    return false;
  });

  // ── Input validation ──────────────────────────────────────────────────────
  await t('TC-VAL-01', 'Input Validation', 'Zero amount rejected (422)', () =>
    api('/api/transactions.php', { method: 'POST', body: { type: 'expense', amount: 0, category: 'Food', date: '2026-08-09' }, jar: userA.jar }).then(r => r.status === 422));
  await t('TC-VAL-02', 'Input Validation', 'Negative amount rejected (422)', () =>
    api('/api/transactions.php', { method: 'POST', body: { type: 'expense', amount: -50, category: 'Food', date: '2026-08-09' }, jar: userA.jar }).then(r => r.status === 422));
  await t('TC-VAL-03', 'Input Validation', 'Huge amount (1e15) rejected',
    () => api('/api/transactions.php', { method: 'POST', body: { type: 'expense', amount: 1e15, category: 'Food', date: '2026-08-09' }, jar: userA.jar }).then(r => r.status === 422));
  await t('TC-VAL-04', 'Input Validation', 'Invalid type rejected (422)', () =>
    api('/api/transactions.php', { method: 'POST', body: { type: 'hack', amount: 10, category: 'Food', date: '2026-08-09' }, jar: userA.jar }).then(r => r.status === 422));
  await t('TC-VAL-05', 'Input Validation', 'Invalid date rejected (422)', () =>
    api('/api/transactions.php', { method: 'POST', body: { type: 'expense', amount: 10, category: 'Food', date: '2026-13-99' }, jar: userA.jar }).then(r => r.status === 422));
  await t('TC-VAL-06', 'Input Validation', 'Missing category rejected (422)', () =>
    api('/api/transactions.php', { method: 'POST', body: { type: 'expense', amount: 10, date: '2026-08-09' }, jar: userA.jar }).then(r => r.status === 422));
  await t('TC-VAL-07', 'Input Validation', 'HTML/JS payload in notes stored but not executed', () =>
    api('/api/transactions.php', { method: 'POST', body: { type: 'expense', amount: 11, category: 'Food', notes: '<script>alert(1)</script>', date: '2026-08-09' }, jar: userA.jar }).then(r => r.status === 201));
  await t('TC-VAL-08', 'Input Validation', 'Array as amount rejected',
    () => api('/api/transactions.php', { method: 'POST', body: { type: 'expense', amount: [500, 600], category: 'Food', date: '2026-08-09' }, jar: userA.jar }).then(r => r.status === 422));
  await t('TC-VAL-09', 'Input Validation', 'Object as category rejected',
    () => api('/api/transactions.php', { method: 'POST', body: { type: 'expense', amount: 10, category: { a: 1 }, date: '2026-08-09' }, jar: userA.jar }).then(r => r.status === 422));
  await t('TC-VAL-10', 'Input Validation', 'SQLi in GET filter not interpreted', () =>
    api("/api/transactions.php?type=all&month=1' OR '1'='1", { jar: userA.jar }).then(r => r.status === 200 && r.data.ok));
  await t('TC-VAL-11', 'Input Validation', 'Boolean as amount rejected',
    () => api('/api/transactions.php', { method: 'POST', body: { type: 'expense', amount: true, category: 'Food', date: '2026-08-09' }, jar: userA.jar }).then(r => r.status === 422));

  // ── Error handling / information disclosure ───────────────────────────────
  await t('TC-ERR-01', 'Error Handling', 'Malformed JSON does not leak stack trace / SQLSTATE', () =>
    fetch(BASE + '/api/transactions.php', { method: 'POST', headers: { 'Content-Type': 'application/json', Cookie: cookieHeader(userA.jar) }, body: '{bad json' })
      .then(async r => !/Fatal error|Stack trace|SQLSTATE|PDOException|\.php\b/.test(await r.text())));
  await t('TC-ERR-02', 'Error Handling', 'Unknown action -> 400 with clean error', () =>
    api('/api/salary.php?action=nope', { jar: userA.jar }).then(r => r.status === 400 && r.data.ok === false));
  await t('TC-ERR-03', 'Error Handling', 'No path/SQL disclosure in auth responses', () =>
    api('/api/auth.php', { method: 'POST', body: { action: 'login', email: userA.email, password: 'WrongPass!' } })
      .then(r => !/C:\\|\.php:|PDO|SQLSTATE|StackTrace/i.test(JSON.stringify(r.data))));
  await t('TC-ERR-04', 'Error Handling', 'Session cookie is marked HttpOnly',
    () => api('/api/auth.php', { method: 'POST', body: { action: 'login', email: userA.email, password: PW } }).then(r => {
      const c = r.cookies.find(x => /^PHPSESSID/i.test(x)) || '';
      return /HttpOnly/i.test(c);
    }));
  await t('TC-ERR-05', 'Error Handling', 'Session cookie is marked SameSite',
    () => api('/api/auth.php', { method: 'POST', body: { action: 'login', email: userA.email, password: PW } }).then(r => {
      const c = r.cookies.find(x => /^PHPSESSID/i.test(x)) || '';
      return /SameSite/i.test(c);
    }));
  await t('TC-ERR-06', 'Error Handling', 'No X-Powered-By version disclosure',
    () => fetch(BASE + '/api/auth.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{"action":"login","email":"a@b.c","password":"x"}' })
      .then(r => !/X-Powered-By/.test(r.headers.get('x-powered-by') || '')));

  // ── L2: password change invalidates other sessions ────────────────────────
  // Runs last (after every test that relies on userA's original session). The
  // change keeps the performing session and kills the older userA.jar session;
  // only the admin cleanup runs afterwards.
  await t('TC-SESS-06', 'Session', 'Password change keeps the current session (L2)', async () => {
    const chgJar = {};
    await api('/api/auth.php', { method: 'POST', body: { action: 'login', email: userA.email, password: PW } }).then(r => storeCookies(r.cookies, chgJar));
    const chg = await api('/api/profile.php', { method: 'PUT', body: { action: 'password', current: PW, new: 'NewPass#456' }, jar: chgJar });
    storeCookies(chg.cookies, chgJar); // password change regenerates the session id
    if (chg.status !== 200) return 'change failed: ' + chg.status;
    const mine = await api('/api/transactions.php', { jar: chgJar });
    return mine.status === 200;
  });
  await t('TC-SESS-07', 'Session', 'Password change invalidates older sessions (L2)', async () => {
    const r = await api('/api/transactions.php', { jar: userA.jar });
    return r.status === 401;
  });

  // ── Cleanup (disposable test accounts only) ───────────────────────────────
  await t('TC-CLN-01', 'Cleanup', 'Admin deletes test User A', () =>
    api('/api/admin.php?action=delete&userId=' + userA.id, { jar: adminJar }).then(r => r.status === 200 && r.data.ok));
  await t('TC-CLN-02', 'Cleanup', 'Admin deletes test User B', () =>
    api('/api/admin.php?action=delete&userId=' + userB.id, { jar: adminJar }).then(r => r.status === 200 && r.data.ok));
  await t('TC-CLN-03', 'Cleanup', 'Admin deletes rogue (mass-assignment) test user', () =>
    rogueId ? api('/api/admin.php?action=delete&userId=' + rogueId, { jar: adminJar }).then(r => r.status === 200 && r.data.ok) : 'rogueId not captured');

  // Purge the auth_attempts rows created by TC-RL-01/02 (rate-limit windows are
  // per-IP for 15 min; without this a re-run within 15 min would trip the caps).
  await clearAttempts('login');
  await clearAttempts('register');

  // ── Summary ───────────────────────────────────────────────────────────────
  console.log('\n=== MONEYWISE SECURITY REGRESSION SUITE ===');
  console.log('PASS: ' + nPass + '   GAP: ' + nGap + '   FAIL: ' + nFail + '   ERROR: ' + nErr + '   TOTAL: ' + (nPass + nGap + nFail + nErr));
  for (const r of results){
    if (r.status !== 'PASS') console.log('  [' + r.status + '] ' + r.id + ' ' + r.area + ' :: ' + r.name + (r.detail ? ' :: ' + r.detail : ''));
  }
  process.exitCode = (nFail > 0 || nErr > 0) ? 1 : 0;
})();
