# MoneyWise — Security Audit Report

**Application:** MoneyWise — Personal/Employee Money Manager (PHP 8.2 + MySQL + vanilla-JS SPA)
**Audit date:** 2026-08-09
**Remediation verified:** 2026-08-09 (all findings fixed; regression suite re-run)
**Target:** `http://localhost/MoneyManagement1/` (local XAMPP 8.8 instance, owned/authorized)
**Audit type:** White-box security review (static code review + black-box API testing)
**Method:** All tests were safe, non-destructive, and executed against disposable test accounts that were deleted after the run.

---

## 1. Executive Summary

MoneyWise was audited across authentication, authorization (RBAC/IDOR/BOLA), session management, input validation, SQL injection, error handling, transport security, and cookie/header configuration.

**Result:** No critical or high-severity exploitable vulnerabilities were found in the application logic. All authorization boundaries (cross-user transaction access, admin escalation, salary controls) enforce server-side checks correctly, and all SQL is parameterized. The findings were concentrated in **server/deployment configuration** (cookie flags, security headers, rate limiting) and **input type-coercion** edge cases — all rated Medium or Low, none exploitable for privilege escalation or data exposure on the audited instance.

All findings from this audit (3 Medium, 5 Low) were remediated on 2026-08-09 and re-verified by the regression suite (**66 PASS · 0 GAP · 0 FAIL · 0 ERROR**) plus live header/HTTP checks. The remaining deployment requirement is TLS, which is documented in README § Production Deployment Notes (D1) and cannot be enabled on a plain-HTTP localhost target.

| Severity | Count |
|----------|-------|
| Critical | 0 |
| High | 0 |
| Medium | 0 (3 found → all fixed) |
| Low | 0 (5 found → all fixed) |
| Informational / verified-control | 12 |

**Overall security readiness: HARDENED (localhost baseline).** Application layer secure by design; all audit findings fixed. Production deployment still requires TLS termination (see D1).

---

## 2. Scope & Environment

| Item | Value |
|------|-------|
| OS / stack | Windows, XAMPP 8.8, Apache 2.4.58, PHP 8.2.12, MySQL 8.x |
| DB | `moneywise` (`127.0.0.1:3306`, root) |
| App entry points | `signin.php`, `signup.php`, `index.php` (SPA), `install.php` |
| API surface | `api/config.php`, `api/auth.php`, `api/transactions.php`, `api/profile.php`, `api/salary.php`, `api/admin.php`, `api/events.php` |
| Test tooling | Node.js v22.14.0 `fetch` + custom cookie-jar harness (in-repo: `tests/security/api_security_tests.js`) |

### Application inventory

| Path | Purpose | Auth required |
|------|---------|---------------|
| `signin.php` | Server-rendered login page (FA eye-toggle, no demo creds) | — |
| `signup.php` | Server-rendered registration page | — |
| `index.php` | SPA (dashboard, add/edit, PDF reports, admin console) | — |
| `install.php` | DB installer + demo seed (`reset` = destructive by design) | — |
| `api/auth.php` | `session`, `login`, `register`, `logout` | login/register public |
| `api/transactions.php` | GET (filters), POST add, PUT edit, DELETE (own or erase-all) | `require_login` |
| `api/profile.php` | `profile` (name/gender/email), `password` | `require_login` |
| `api/salary.php` | `mine` (own), `list`/`history`/`set` (admin) | `require_login` + `require_admin` where applicable |
| `api/admin.php` | `users`, `view`, `delete` (admin user mgmt) | `require_admin` |
| `api/events.php` | event + event-expense CRUD (`list`/`get`/`create`/`update`/`delete`/`add_expense`/`update_expense`/`delete_expense`); a SEPARATE module — expenses live only in the `events`/`event_expenses` tables and are never written to `transactions`, so they don't affect the Dashboard/overall reports | `require_login` + per-row `WHERE user_id = ?` ownership |

---

## 3. Automated Test Plan & Results

Executed with `tests/security/api_security_tests.js`. Creates two disposable users (A/B), exercises controls, deletes them.

**Post-fix result: 66 checks — 66 PASS · 0 GAP · 0 FAIL · 0 ERROR.** (Pre-fix: 61 checks — 55 PASS · 6 GAP · 0 FAIL · 0 ERROR.)

| ID | Area | Check | Result |
|----|------|-------|--------|
| TC-SET-01..05 | Setup | Register A/B, login, A adds expense | PASS |
| TC-AUTH-01 | Auth | Valid login | PASS |
| TC-AUTH-02 | Auth | Wrong password → 401 | PASS |
| TC-AUTH-03 | Auth | **No login enumeration** (identical error for known/unknown email) | PASS |
| TC-AUTH-04/05 | Auth | Empty / missing credentials → 422 | PASS |
| TC-AUTH-06/07 | Auth | SQLi (`' OR '1'='1`, UNION) not interpreted → 401 | PASS |
| TC-AUTH-08/09 | Auth | 10k-char password, unicode input handled | PASS |
| TC-AUTH-10 | Auth | Session endpoint returns own user | PASS |
| TC-REG-01 | Reg | Duplicate email → 409 | PASS |
| TC-REG-02/03 | Reg | Weak password (<8) / invalid email → 422 | PASS |
| TC-REG-04 | Reg | **Mass assignment blocked** (`is_admin=1` ignored) | PASS |
| TC-REG-05 | Reg | Duplicate email → 409, enumeration throttled by per-IP rate limit (see TC-RL-02) | PASS (fixed) |
| TC-AUTHZ-01 | AuthZ | Unauthenticated API → 401 | PASS |
| TC-AUTHZ-02 | AuthZ | Own data readable | PASS |
| TC-AUTHZ-03/04 | AuthZ | **IDOR blocked**: B cannot edit/delete A's tx | PASS |
| TC-AUTHZ-05 | AuthZ | **Data isolation**: A's tx never in B's list | PASS |
| TC-AUTHZ-06..11 | AuthZ | **RBAC**: user B blocked from all admin endpoints (403) | PASS |
| TC-AUTHZ-12 | AuthZ | Own salary via `mine` works | PASS |
| TC-ADM-01..04 | Admin | Admin login, list, view; self-delete blocked (422) | PASS |
| TC-ADM-05 | Admin | Salary `set` rejects admin target (404) | PASS |
| TC-SESS-01..04 | Session | Logout invalidates session; forged cookie → 401 | PASS |
| TC-SESS-05 | Session | Re-login after logout works | PASS |
| TC-SESS-06 | Session | **Password change keeps the performing session (L2)** | PASS (new) |
| TC-SESS-07 | Session | **Password change invalidates older sessions (L2)** | PASS (new) |
| TC-RL-01 | Rate limit | **Login locked out → 429 after 5 failures** | PASS (new) |
| TC-RL-02 | Rate limit | **Register enumeration throttled → 429 after repeated duplicate probes** | PASS (new) |
| TC-VAL-01/02 | Validation | Zero / negative amount → 422 | PASS |
| TC-VAL-03 | Validation | Huge amount (1e15) rejected → 422 | PASS (fixed) |
| TC-VAL-04..06 | Validation | Bad type / bad date / missing category → 422 | PASS |
| TC-VAL-07 | Validation | HTML/JS payload stored, never executed | PASS |
| TC-VAL-08/09/11 | Validation | Array / object / boolean amount/category rejected → 422 | PASS (fixed) |
| TC-VAL-10 | Validation | SQLi in GET filter not interpreted | PASS |
| TC-ERR-01..03 | Errors | No stack/SQLSTATE/path disclosure; clean 400s | PASS |
| TC-ERR-04 | Headers | **Session cookie marked HttpOnly** | PASS (fixed) |
| TC-ERR-05 | Headers | **Session cookie marked SameSite** | PASS (fixed) |
| TC-ERR-06 | Headers | **No `X-Powered-By` version disclosure** | PASS (fixed) |
| TC-CLN-01/02 | Cleanup | Test users deleted | PASS |
| TC-CLN-03 | Cleanup | Rogue (mass-assignment) test user deleted | PASS (new) |

---

## 4. Findings (OWASP Top 10 2021 mapping)

All findings below are **FIXED** (remediated 2026-08-09 and re-verified). The original observation and fix applied are noted per item.

### Medium

**M1 — Session cookie flags not hardened** *(OWASP A05:2021 Security Misconfiguration; ASVS 3.x Session Mgmt)*
- Observed: `Set-Cookie: PHPSESSID=h73lj65omf866gfsmjtffqqtv4; path=/` — no `HttpOnly`, no `Secure`, no `SameSite`.
- Root cause: `C:\xampp8.8\php\php.ini` — `session.cookie_httponly=` (empty), `session.cookie_secure=` (commented), `session.cookie_samesite=` (empty).
- Impact: Without `HttpOnly`, any future XSS could read the session cookie (`document.cookie`). Without `SameSite`, CSRF protection relies on browser default (Lax). Without `Secure`, cookie is sent in cleartext over HTTP.
- Exploitability on current build: **Low** — no XSS sink exists (all rendering uses `createTextNode`), and modern browsers default cookies to SameSite=Lax.
- **Status: FIXED.** `start_secure_session()` in `api/config.php` now calls `session_set_cookie_params()` (`HttpOnly=true`, `SameSite=Lax`, `Secure` over HTTPS) and sets `session.use_strict_mode=1`; it is called by every entry point. Verified live: `Set-Cookie: PHPSESSID=...; path=/; HttpOnly; SameSite=Lax`. Covered by TC-ERR-04/05.

**M2 — No login rate limiting / account lockout** *(OWASP A07:2021 Identification & Auth Failures; ASVS 2.2.6)*
- Observed: 25 consecutive wrong-password attempts in 2.5 s — all returned 401, no throttle, no lockout, no CAPTCHA.
- Impact: Online brute-force of weak passwords possible. Mitigated by bcrypt (`PASSWORD_DEFAULT`, cost 12) and the generic error message, but a dictionary/credential-stuffing attack is feasible.
- **Status: FIXED.** New `auth_attempts` table + rate-limit helpers in `api/config.php`; `api/auth.php` login rejects with `429` after 5 failed attempts / 15 min per account+IP (100 / 15 min per IP), failures recorded, reset on success. Helpers fail open if the DB is unavailable. Verified live: attempt #6 → `429`. Covered by TC-RL-01.

**M3 — Missing security headers** *(OWASP A05:2021; ASVS 14.4/14.5)*
- Observed: No `Content-Security-Policy`, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, or `Strict-Transport-Security` on any response (verified on `index.php` and API responses).
- Impact: Clickjacking of the "Add Expense"/profile forms is possible inside an attacker iframe; MIME-sniffing attacks; referrer leakage (the app contains no external links, so limited).
- **Status: FIXED.** `send_security_headers()` in `api/config.php` (loaded by every entry point) emits `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, `Permissions-Policy: geolocation=(), microphone=(), camera=()`, and HSTS over HTTPS. Verified live on `index.php` and API responses. A full CSP remains out of scope (the SPA uses inline scripts/styles).

### Low

**L1 — Account enumeration via registration** *(OWASP A07:2021)* — `register` returns 409 "Email already in use", confirming account existence. Common industry practice; if stricter, return a generic message or rate-limit the register endpoint. Login endpoint correctly returns a **generic** message (verified — no login enumeration).
- **Status: FIXED (mitigated).** Duplicate-email register probes are now rate-limited per IP (30 / 15 min → `429 "Too many registration attempts."`); the 409 is retained for usability (a first duplicate attempt is indistinguishable from normal use). Verified live and covered by TC-RL-02; TC-REG-05 now asserts 409 + documents the throttle.

**L2 — Password change does not invalidate other sessions** *(OWASP A07:2021; ASVS 2.5.3)* — `api/profile.php` `password` action updates the hash but never regenerates the session or destroys other sessions for that user. A stolen session remains valid after the password is changed. Fix: regenerate session + optionally track a "password changed at" epoch and revalidate on `current_user()`.
- **Status: FIXED.** `users.password_changed_at` is set on every password change; the change regenerates the session and refreshes `auth_time`. `current_user()` in `api/config.php` rejects any session whose `auth_time` is missing or older than the last password change, forcing re-login on all devices. Schema change applied to the live DB (`database.sql` + `install.php` updated). **Note:** during verification a timezone mismatch was found and fixed — MySQL `NOW()` stored the system wall-clock (Asia/Calcutta) but PHP parsed it as Europe/Berlin, which would have immediately invalidated the performing session; the fix stores the timestamp as UTC (`UTC_TIMESTAMP()`) and parses it as UTC (`strtotime($val . ' UTC')`), making the comparison timezone-independent. Covered by TC-SESS-06/07.

**L3 — Weak password policy** — minimum length 6 (`api/auth.php` and `api/profile.php`). NIST SP 800-63B recommends ≥ 8, ideally ≥ 12 with breach-list screening; add a composition/breach check.
- **Status: FIXED.** Minimum password length raised to **8** in `api/auth.php`, `api/profile.php`, and the sign-up/login UI (`signup.php`, `index.php`) with matching messages/placeholders. Verified by TC-REG-02 (`<8` rejected → 422).

**L4 — Loose type coercion on numeric inputs** *(OWASP A04:2021 Insecure Design — data integrity)* — `transactions.php` and `salary.php` use `(float)param(...)`, so `amount: true` → 1.0, `amount: [500,600]` → 1.0, and `1e15` is accepted (no upper bound). Category objects/arrays are cast to strings. No security boundary is bypassed (all queries parameterized and user-scoped) but data integrity and financial-report correctness are at risk. Fix: validate `is_float/is_int` + `> 0` + an upper bound (e.g. ≤ 1e9) before casting.
- **Status: FIXED.** `transactions.php` (POST/PUT/DELETE/GET filters) and `salary.php` (set/history) now validate raw values with `is_numeric()` + `is_finite()` + `> 0` + `≤ 1e9`, reject non-strings via `scalar_string()`, and return 422. Verified live; covered by TC-VAL-03/08/09/11.

**L5 — Version disclosure** — `Server: Apache/2.4.58 (Win64) OpenSSL/3.1.3 PHP/8.2.12` and `X-Powered-By: PHP/8.2.12`. Low risk; disable with `ServerTokens Prod`/`ServerSignature Off` and `expose_php = Off`.
- **Status: FIXED.** `C:\xampp8.8\php\php.ini` → `expose_php = Off`; `C:\xampp8.8\apache\conf\extra\httpd-default.conf` → `ServerTokens Prod` + `ServerSignature Off`; Apache restarted and config validated (`httpd.exe -t` → Syntax OK). Verified live: `Server: Apache`, no `X-Powered-By`. Covered by TC-ERR-06.

### Conditional / deployment

**D1 — No HTTPS** *(OWASP A02:2021 Cryptographic Failures)* — the app is served over plain HTTP. Acceptable for localhost development; any production deployment **must** enable TLS, redirect HTTP→HTTPS, and set `session.cookie_secure=1` + HSTS. Rated High **only** for a production deployment; not applicable to the localhost audit target.
- **Status: PARTIAL (deployment-time requirement).** The app now auto-enables `Secure` cookies, HSTS, and no-store caching when a request arrives over HTTPS, and a full deployment runbook was added to `README.md` § Production Deployment Notes (D1) (TLS termination, HTTP→HTTPS redirect, real-client-IP configuration behind proxies, demo credential rotation). Enabling TLS on the localhost target is out of scope and not required.

---

## 5. Verified Controls (no issues found)

| Control | Evidence |
|---------|----------|
| SQL injection | All queries use PDO prepared statements with `ATTR_EMULATE_PREPARES=false`; GET filters validated/ranged; hostile payloads (`' OR '1'='1`, UNION, quote-in-filter) all rejected/neutralized |
| Broken Object-Level Authorization (IDOR) | Every transaction read/write/delete is scoped by `WHERE user_id = ?`; cross-user PUT/DELETE returns 403/404 |
| Broken Function-Level Authorization (RBAC) | `require_admin()` gate on all admin endpoints; normal user → 403; salary `set` targets non-admin employees only |
| Privilege escalation / mass assignment | `register` INSERT hardcodes `is_admin = 0`; `profile` UPDATE whitelists fields; `is_admin` never read from user input |
| Session fixation | `session_regenerate_id(true)` on login and register |
| Session termination | `logout` clears `$_SESSION`, expires the cookie, `session_destroy()`; stale cookie → 401 |
| Password storage | bcrypt via `password_hash(PASSWORD_DEFAULT)`; change requires current password |
| XSS (stored/reflected/DOM) | SPA renders all user data through `h()` → `document.createTextNode`; no user-data `innerHTML` sinks (all `innerHTML` uses are static icon strings or clears); `setAttribute('value',…)` is not HTML-parsing |
| Error handling | Malformed JSON, unknown actions, DB failures return clean JSON; no stack traces, SQLSTATE, or filesystem paths leaked |
| Brute-force resistance (passwords) | Generic login error + bcrypt cost 12 (partial — see M2) |
| Admin safety | Cannot delete own account; admin accounts cannot be deleted |

---

## 6. Security Readiness Status

- [ ] **CRITICAL** issues outstanding
- [ ] **HIGH** issues outstanding
- [ ] **MEDIUM** issues outstanding — all 3 fixed (cookie flags, rate limiting, security headers)
- [ ] **LOW** issues outstanding — all 5 fixed (enumeration, session invalidation, password policy, type coercion, version disclosure)
- [x] **PRODUCTION TLS** deployment requirement (D1) — documented; requires TLS termination at the deployment edge

**SECURITY STATUS: HARDENED (localhost baseline).**
The application layer is secure against common web attacks (SQLi, IDOR, RBAC bypass, XSS, session theft via fixation). All audit findings were remediated on 2026-08-09 and re-verified: regression suite **66 PASS / 0 GAP / 0 FAIL / 0 ERROR**, live HTTP checks confirm hardened cookies, security headers, no version disclosure, and working 429 rate limits. The only remaining item is enabling TLS on the production host (D1) — required before any internet-facing deployment.

---

## 7. Regression Automation

- Suite: `tests/security/api_security_tests.js` (this repo) — Node ≥ 18 (`fetch`).
- Run: `node tests/security/api_security_tests.js`
- Behavior: creates disposable accounts, verifies controls, deletes accounts. Exits 0 when no FAIL/ERROR; prints PASS/GAP/FAIL/ERROR summary. GAP = documented weakness still present (flips to FAIL if a fix removes the expected weak behavior, prompting report update). Rate-limit tests (TC-RL-01/02) purge the `auth_attempts` rows they create via the local MySQL CLI so re-runs within the 15-minute window stay clean.
- Coverage: authentication, enumeration, mass assignment, IDOR/BOLA, RBAC, session lifecycle, input validation, SQLi, error disclosure, cookie flags (HttpOnly/SameSite), version disclosure, login/register rate limiting (429s).
- Note: update `ADMIN.email/password` in the suite header if the seeded admin credentials change.

## 8. Non-Security Fix Applied (2026-08-09)

**Corrupted icon/currency text in `index.php`.** The UI showed mojibake (`â‚¹`, `ðŸ…`, `â€¦`) instead of ₹ and emoji icons.

- **Root cause:** one level of double-encoding. The file's original UTF-8 bytes were decoded as WHATWG/HTML5 `windows-1252` (so `0xE2 0x82 0xB9` ₹ became `U+20AC/0x9A/0xB9` → `â‚¬š¹`) and then re-encoded as UTF-8, yielding sequences like `C3 A2 E2 80 9A C2 B9`.
- **Scope confirmed by scan:** `index.php` was the only file affected (all other PHP/HTML/CSS/JS pages, the database, and its `utf8mb4` columns were already clean).
- **Fix:** a lossless reverse mapping (HTML5 cp1252 codepoints → original bytes) restored the file: **0 unmapped codepoints, 0 pure-ASCII lines changed**, `php -l` clean. Applied to the repo and the live htdocs copy (MD5 parity verified), then confirmed over HTTP that the served page now contains the correct `₹`, `👑`, `🛒`, `🍔`, `💼`, and `—` UTF-8 bytes with no mojibake markers, and that the security regression suite still passes **66/0/0/0**.
