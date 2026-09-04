# 💰 MoneyWise — Expense Tracker

**MoneyWise** is a mobile-first, web-based personal & employee expense management application built with **PHP (vanilla), MySQL, and vanilla JavaScript**. It lets users track income and expenses, view statistics, download branded PDF reports, and lets an administrator manage users, salaries, and salary history.

The front end is a single-page application (SPA) rendered client-side in `index.php`, talking to a small set of JSON API endpoints in the `api/` folder. There are no frameworks — just clean PHP + a hand-written DOM helper.

---

## ✨ Features

### 👤 User Features
- **Sign Up / Sign In** with session-based authentication (passwords stored as `password_hash()` bcrypt hashes).
- **Dashboard**
  - Greeting based on time of day (`Good Morning / Afternoon / Evening`).
  - Personalized `Hello, <Name> 👑` header.
  - Available Balance for the selected period (Income − Expense).
  - **Erase Balance** eraser button — hides the displayed balance **persistently** (survives refresh / logout / browser reopen) without touching the records; the balance reappears automatically when a new transaction is added.
  - Quick **Today** and **This Month** expense tiles.
  - **Recent Transactions** (today's transactions only).
  - Quick-add buttons for Expense and Income.
- **Add Income / Expense**
  - Big amount input with `₹` symbol.
  - Categorized chip picker (dedicated emoji categories for income and expense).
  - Date picker (local-timezone safe) and optional notes.
  - Full client-side validation with friendly inline alerts.
- **Transaction List**
  - Filterable, editable, and deletable records with an "Erase Balance" option.
- **Statistics**
  - Total Income / Total Expense summary cards.
  - Month + Year filters (All Months / All Years supported).
  - Period-wise income · expense · balance blocks and category-wise breakdown.
- **PDF Reports (Reports / PDF Download)**
  - **Daily PDF** — expenses for one exact date.
  - **Monthly PDF** — expenses for one month.
  - **Yearly PDF** — expenses for one year.
  - Branded, purple MoneyWise-styled PDFs (logo, header, zebra-striped tables, category-wise summary, grand total, footer with page numbers). Generated client-side with **jsPDF** — never touches or deletes your records.
- **Profile**
  - Edit name, email, and gender.
  - Change password (requires current password).
  - Shows your current monthly salary if one is set.
- **Events (Event-Based Expense Management)**
  - **Events** module (bottom nav + handy **Event** button on the Dashboard) to manage personal events like marriages, parties, or functions.
  - **Create / Edit / Delete events** with name, type (Marriage, Party, Birthday, Religious, Graduation, Other + custom), date, location, description, and optional budget.
  - **Manage event expenses** (item, paid-to, amount, payment method, date, and optional time/notes) with automatic **total**, **item-wise** (`itemTotals`), and **payment-method-wise** (`paymentTotals`) analysis, plus budget tracking (`remaining` / `budgetExceeded`).
  - **Filter & search** events by type, month/year, or name.
  - **Event PDF report** — branded, MoneyWise-styled handout (header, table with S.No | Item | Paid To | Amount | Method | Date | Time, grand total, category & payment summary) generated client-side with jsPDF, never touching the records.
  - **Isolated module** — event expenses live only in the Events module and are never written to the main `transactions` table, so they do **not** affect the Dashboard, overall Daily / Monthly / Yearly reports, Statistics, or Recent Transactions.
- **Calculator** (bottom nav) — a simple, clean, normal calculator:
  - Basic operations `+ − × ÷`, decimals, percentage `%`, and clear/edit keys `AC`, backspace `⌫`, and `+/−` sign toggle.
  - Correct operator precedence (`2+3×4 = 14`), a live result preview, and an `=` key that only evaluates the expression.
  - Guarded input — no consecutive operators, at most one decimal point per number, and friendly errors ("Cannot divide by zero") instead of `Infinity`.
  - Physical-keyboard input, a fully responsive layout for mobile and desktop, and no private/password features of any kind.
- **AI Financial Assistant (Ask AI)** — a chat overlay, opened via a floating **Ask AI** button on every page or the **AI Assistant** row in Settings:
  - Ask natural-language questions about your own records — this month's spend, top categories, month-over-month comparison, income, biggest single expense, a specific month, a date range, or this week.
  - Server-side answers built from your real data only (`services/FinanceData.php`); the AI never receives arbitrary SQL, another user's data, your password, or API keys.
  - Uses OpenAI (Responses-style chat completions) when an `OPENAI_API_KEY` / `ai_api_key` is configured, and falls back to a deterministic offline answer otherwise — so the feature never breaks without a key.
  - Quick-question chips, typing indicator, Enter-to-send (Shift+Enter = newline), a persisted per-user conversation history with the ability to switch chats or start a new one, and strict server-side prompt-injection guarding.
- **Settings** — Profile, Change Password, Logout.

### 🛡️ Admin Features
Only visible to accounts with `is_admin = 1`:
- **User Management** — view all users, see roles, delete non-admin users (cascade deletes their records). Swipe-to-delete supported on touch devices.
- **View User Expenses** — inspect any user's income, expenses, and balance with month/year filters.
- **Change Admin Password** — update the administrator password.
- **Salary Management** — set / update employee salaries per month and year; current salary shown per employee.
- **Salary History** — full audit trail of every salary change (old → new, diff, timestamp) with employee / month / year filters.
- **Clear (persistent, view-only)** — the "Clear" button on the Status module (current month/year period), the Dashboard (Recent Transactions), the Expense/Income lists, and the "View User Expenses" screen hides the displayed entries from the view **persistently**. Hidden markers are stored server-side in the `cleared_items` table via `api/clear.php` (`action=clear`), so the cleared state survives page refresh, browser reopen, and logout/login. Original records in `transactions` are **never** deleted or modified — Reports and the Daily / Monthly / Yearly PDFs keep reading the full untouched data, and clearing one period never affects entries in other periods. New entries added after a Clear appear normally. A confirmation popup ("Are you sure you want to clear all entries?") is shown before clearing. On the "View User Expenses" screen, Clear is restricted to admin sessions (the whole screen is already server-side admin-gated via `api/admin.php`).

---

## 🧰 Tech Stack

| Layer    | Technology |
|----------|------------|
| Backend  | PHP 8+ (plain, `declare(strict_types=1)`), PDO |
| Database | MySQL / MariaDB (`utf8mb4_unicode_ci`) |
| Frontend | Vanilla JavaScript (SPA, no build step), Hand-written DOM helper |
| Styling  | Pure CSS (`assets/app.css`), Nunito/Poppins fonts, purple gradient theme |
| Icons    | Font Awesome 6.5.0 (CDN) |
| PDF      | jsPDF 2.5.1 (CDN) |

---

## 📁 Project Structure

```
MoneyManagement1/
├── index.php                 # SPA — entire app UI + client-side logic + PDF report builders
├── signin.php                # Server-rendered sign-in page
├── signup.php                # Server-rendered sign-up page
├── install.php               # CLI/browser installer + demo data seeder
├── database.sql              # DB schema (idempotent: CREATE ... IF NOT EXISTS)
├── Mlogo/
│   └── MoneywiseLOGO.png      # App logo (also embedded in generated PDFs)
├── assets/
│   └── app.css               # All styles (light purple theme)
├── api/
│   ├── config.php            # DB config + shared helpers (json_out, body, current_user, ...)
│   ├── auth.php              # session / login / register / logout
│   ├── transactions.php      # list / add / edit / delete / erase-all (own records only)
│   ├── profile.php           # update profile / change password
│   ├── salary.php            # employee list, own salary, salary history, set salary
│   ├── admin.php             # admin-only: users, view user, delete user
│   ├── clear.php             # persistent view-clear markers (cleared_items) + status module
│   ├── events.php, categories.php, translate.php, ai.php   # events, categories, translation, AI assistant
│   └── ...                   # other endpoints (profile, report, etc.)
├── services/                 # backend-only helpers
│   ├── FinanceData.php       # auth-guarded financial queries used by the AI (prepared statements only)
│   └── AIService.php         # OpenAI client (server-side key) with offline fallback
├── mobile/                   # Capacitor Android/iOS app (see "Mobile App" below)
├── resources/                # App icon/splash source assets (mobile)
├── tests/security/           # API + mobile-token security regression suites
└── moneywise_app (1).html    # Legacy static prototype (kept for reference)
```

---

## 🚀 Requirements

- **PHP 8.0+** (with `pdo_mysql` extension)
- **MySQL 5.7+ / MariaDB 10.3+**
- Any modern browser (Chrome, Edge, Firefox, Safari)

---

## 🔧 Installation

> Designed to run on a local server such as **XAMPP** (Apache + PHP + MySQL).

1. **Copy the project** into your web root, e.g. `C:\xampp\htdocs\MoneyManagement1`.
2. **Start Apache and MySQL** from the XAMPP Control Panel.
3. **Create the database & tables** — run the installer:
   - Browser: open `http://localhost/MoneyManagement1/install.php`
   - Or CLI: `php install.php` (from the project folder)
4. **Done.** Open the app at `http://localhost/MoneyManagement1/`.

### Installer modes
- `php install.php` — creates the tables if missing and seeds demo data **only** when the `users` table is empty. Existing data is never touched. On a local XAMPP it also creates the `moneywise` database on first run.
- `php install.php reset` — **drops all tables** and re-seeds fresh demo data (destructive — use with care).

> **Database configuration.** Connection settings are read in this order: environment variables (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_SSL`, `DB_CA`) → a `db-config.php` file in the project root (copy [`db-config.example.php`](db-config.example.php)) → local XAMPP defaults (`127.0.0.1` / `root` / empty password / db `moneywise`). On shared hosting like **InfinityFree**, where you cannot set server env vars, just create `db-config.php` with your hosting database credentials — local development stays untouched. `db-config.php` is git-ignored; never commit a real password.

### 🐳 Docker (optional)

The project ships a `Dockerfile` + `docker-compose.yml` (PHP 8.2 + Apache + MySQL 8):

```bash
docker compose up --build
```

- App: `http://localhost:8080/MoneyManagement1/` (Apache serves `/var/www/html` at root, so the app is at `http://localhost:8080/`)
- MySQL container `db` (root / empty password), data persisted in a named volume `db_data`.
- On startup the entrypoint waits for MySQL, runs `install.php` (idempotent), then starts Apache.
- Override DB settings via the `app` service `environment:` block in `docker-compose.yml` (`DB_HOST=db`, etc.). Remove the `.:/var/www/html` bind-mount if you want a fully self-contained image.

### ☁️ Deploying to Render

Render does **not** run your `docker-compose.yml` and has no `db` service, so the
entrypoint connects to whatever host `DB_HOST` points at. Point it at a real
MySQL database (Render only offers PostgreSQL natively — use a MySQL provider
such as **Aiven** free tier, or any managed MySQL):

1. Create a MySQL database and note its **host, port, name, user, password** (Aiven ports are often not `3306`).
2. In **Render dashboard → your web service → Environment**, add the five vars:
   `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` (Render stores the password as a secret).
3. Redeploy. The entrypoint retries for up to 3 minutes, runs `install.php`, then binds Apache to Render's `$PORT`.

A ready-to-use blueprint is included in [`render.yaml`](render.yaml) (Blueprints → New → this repo).

### 🌐 Deploying to InfinityFree (shared hosting)

InfinityFree pre-provisions your MySQL database and your MySQL user has **no
`CREATE DATABASE` privileges**, so `database.sql` deliberately contains **no
`CREATE DATABASE` / `USE` statements** — it only creates tables in whatever
database is already selected.

1. In **InfinityFree → MySQL Databases**, create/open your database (e.g.
   `if0_42622623_moneywise1`) and note the **host** (`sql313.infinityfree.com`),
   **database name**, and **username** (`if0_42622623`).
2. **Import the schema** in **phpMyAdmin**: select your existing database →
   *Import* → choose `database.sql` → Go. This creates the tables `users`,
   `transactions`, `salaries`, `salary_history`, `cleared_items`,
   `expense_items`, `categories`, `events`, `event_expenses`, `api_tokens`,
   `auth_attempts` without touching any existing tables or data.
3. **Configure the connection** in the uploaded project root: copy
   `db-config.example.php` to `db-config.php` and fill in your real values:
   ```php
   return [
       'host' => 'sql313.infinityfree.com',
       'port' => '3306',
       'name' => 'if0_42622623_moneywise1',
       'user' => 'if0_42622623',
       'pass' => 'YOUR_INFINITYFREE_MYSQL_PASSWORD',
       'ssl'  => '0',
       'ca'   => '',
   ];
   ```
   The app (`api/config.php`) and `install.php` pick this file up automatically.
   Alternatively, if your host supports it, set the `DB_HOST`/`DB_PORT`/
   `DB_NAME`/`DB_USER`/`DB_PASS` env vars instead.
4. **Seed the admin account** (optional): visit `install.php` in your browser
   (or run `php install.php`) — it connects directly to the existing database
   and seeds demo data only when the `users` table is empty.
5. **Verify**: log in at your InfinityFree site — login, registration,
   transactions, salary, dashboard all read/write the same database.

> `install.php` never runs `CREATE DATABASE` / `USE` when a `db-config.php` is
> present; it connects straight to your existing database (see
> `install.php` → *"connect directly to the configured database"*).

---

## 🗄️ Database Schema

Database: `moneywise` (local default — on shared hosts this is your pre-provisioned database name, e.g. `if0_42622623_moneywise1`; see the InfinityFree section)

| Table            | Purpose                                                    | Key columns |
|------------------|------------------------------------------------------------|-------------|
| `users`          | Accounts (name, gender, email, bcrypt password, role)      | `is_admin` flag, `password_changed_at`, unique `email` |
| `transactions`   | Income / expense records per user                          | `type`, `amount`, `category`, `notes`, `tdate`, FK `user_id` (cascade) |
| `events`         | User events (name, type, date, location, budget) — isolated module, never written to `transactions` | FK `user_id` (cascade) |
| `event_expenses` | Expenses under an event (item, paid-to, amount, method, date, time, notes) | FK `event_id` (cascade) |
| `salaries`       | One current salary per employee                            | `amount`, `month`, `year`, unique `user_id` |
| `salary_history` | Audit trail of every salary change                         | `old_salary`, `new_salary`, `month`, `year`, `updated_at` |
| `auth_attempts`  | Failed login / duplicate-registration probes (rate limiting) | `scope` (`login`/`register`), `email`, `ip`, `attempt_time`, indexed by (scope, email, ip) and (scope, ip) |
| `api_tokens`     | Mobile bearer tokens (only SHA-256 hashes stored)           | `user_id`, `token_hash`, `created_at`/`expires_at` (UTC, `DATETIME(6)`), `revoked` |
| `ai_conversations` | Per-user AI chat threads (title, timestamps)                 | FK `user_id` (cascade), index `(user_id, updated_at)` |
| `ai_messages`    | AI chat turns per conversation (user + assistant text only) | FK `conversation_id` + `user_id` (cascade); `role` (`user`/`assistant`) |

The schema is **idempotent** — `database.sql` uses `CREATE TABLE IF NOT EXISTS`, so re-running it never deletes existing data. `install.php` upgrades existing installs in place (it migrates a live `events`/`transactions` schema to add `enable_tanglish`, isolates event expenses, and creates the AI tables).

---

## 🌐 API Endpoints

All endpoints return JSON. Every endpoint (except `register`/`login`) requires an active session **or** a valid `Authorization: Bearer <token>` (the mobile app; tokens are issued with `want_token=1` on login/register).

| Endpoint | Method | Action | Access |
|----------|--------|--------|--------|
| `api/auth.php?action=session` | GET | Current logged-in user | Any |
| `api/auth.php` (action=`login`) | POST | Sign in | Public |
| `api/auth.php` (action=`register`) | POST | Create account | Public |
| `api/auth.php` (action=`logout`) | POST | Sign out | Any |
| `api/transactions.php` | GET | List with `type`/`mode`/`month`/`year` filters + summary | Logged-in (own data) |
| `api/transactions.php` | POST | Add transaction | Logged-in (own data) |
| `api/transactions.php` | PUT | Edit transaction | Logged-in (own data) |
| `api/transactions.php` | DELETE | Delete one (`?id=N`) or all (erase balance) | Logged-in (own data) |
| `api/clear.php` | GET | Hidden (cleared) transaction ids for the current user | Logged-in |
| `api/clear.php` (action=`clear`) | POST | Mark entries as hidden in this user's views (records never deleted) | Logged-in (own data) |
| `api/clear.php` (action=`restore`) | POST | Un-hide entries | Logged-in |
| `api/clear.php` (action=`clear_balance`) | POST | Persist the Dashboard "balance erased" flag (display only) | Logged-in |
| `api/clear.php` (action=`restore_balance`) | POST | Clear the Dashboard "balance erased" flag (display only) | Logged-in |
| `api/profile.php` | PUT | Update name/email/gender or change password | Logged-in |
| `api/salary.php?action=mine` | GET | Your current salary | Logged-in |
| `api/salary.php?action=list` | GET | All employees + salaries | Admin |
| `api/salary.php?action=history` | GET | Salary change history (filters: `emp`, `month`, `year`) | Admin |
| `api/salary.php?action=set` | POST | Set/update an employee's salary | Admin |
| `api/events.php?action=list` | GET | List events (filters: `type`, `month`, `year`, `name`) + totals + `availableYears` | Logged-in (own data) |
| `api/events.php?action=get` | GET | One event + its expenses + `total`/`count`/`itemTotals`/`paymentTotals`/`remaining`/`budgetExceeded` | Logged-in (own data) |
| `api/events.php?action=create` | POST | Create an event | Logged-in (own data) |
| `api/events.php?action=update` | POST | Edit an event | Logged-in (own data) |
| `api/events.php?action=delete` | POST | Delete an event (cascade-deletes its expenses) | Logged-in (own data) |
| `api/events.php?action=add_expense` | POST | Add an event expense (stored only in the event tables) | Logged-in (own data) |
| `api/events.php?action=update_expense` | POST | Edit an event expense | Logged-in (own data) |
| `api/events.php?action=delete_expense` | POST | Delete an event expense | Logged-in (own data) |
| `api/admin.php?action=users` | GET | List all users | Admin |
| `api/admin.php?action=view` | GET | A user's transactions/summary (`userId`, `month`, `year`) | Admin |
| `api/admin.php?action=delete` | GET | Delete a non-admin user | Admin |
| `api/ai.php?action=status` | GET | Whether the AI provider is configured (offline vs connected) | Logged-in |
| `api/ai.php?action=conversations` | GET | List your AI chats (newest first) with message counts | Logged-in (own data) |
| `api/ai.php?action=messages&conversation=N` | GET | Full message history for one owned chat | Logged-in (own data) |
| `api/ai.php` (action=`start`) | POST | Create a new empty chat | Logged-in |
| `api/ai.php` (action=`delete`) | POST | Delete one owned chat (cascade deletes its messages) | Logged-in (own data) |
| `api/ai.php` (action=`chat`) | POST | Answer a question from your own records (`message`, optional `conversation`) | Logged-in (own data) |

### Error handling
- `401 Not authenticated` — session missing/expired.
- `403 Admin access required` — non-admin caller on an admin endpoint (no data leaked).
- `404 / 409 / 422` — not found, conflict (e.g. duplicate email), validation errors.
- `429 Too many attempts` — login rate limit (5 failures / 15 min per account+IP, 100 / 15 min per IP) or register throttle (30 duplicate probes / 15 min per IP) reached.
- All errors are returned as `{ ok: false, error: "<message>" }`.

---

## 🧑‍🤝‍🧑 Demo Accounts

The installer seeds demo accounts (also displayed on the public sign-in screen):

| Role | Email | Password |
|------|-------|----------|
| Admin | `Admin0112@gmail.com` | `Admin@0112` |
| Staff | `priya@example.com` | `pass123` |
| Staff | `rahul@example.com` | `pass123` |
| Staff | `aisha@example.com` | `pass123` |

Demo users include sample salaries, salary-history records, and transactions so every screen is populated on first login.

> Demo credentials are for local development only. Change them before any real deployment.

---

## 📄 PDF Reports

Located under **Stats → Reports / PDF Download**:

- **Download Daily PDF** — pick a date → expenses for that exact date.
- **Download Monthly PDF** — pick a month + year → expenses for that month.
- **Download Yearly PDF** — pick a year → expenses for that year.

Each PDF contains **only expense records matching the selection** (income is excluded) and includes:
- Branded header (logo + "MoneyWise / Expense Report").
- Report title and selected period.
- **Total** for the period, **record count**.
- **Category-wise breakdown** with share percentages.
- **Itemized transactions table** (zebra-striped, purple accents, red amounts).
- Footer with generated date and "Page X of Y".

Reports are generated entirely in the browser with **jsPDF** — no server-side generation, **no records are created, modified, or deleted** when you download a report. Filenames follow `MoneyWise-<Daily|Monthly|Yearly>-Expenses-<period>.pdf`.

---

## 🔒 Security Notes

- Passwords hashed with `password_hash()` (bcrypt) — never stored in plain text; minimum length **8 characters** enforced at register, sign-in, and password change.
- Prepared statements (PDO) used throughout — no SQL injection.
- Sessions with `session_regenerate_id(true)` on login/register; logout destroys the session and clears the cookie. Session cookies are hardened (`HttpOnly`, `SameSite=Lax`, `Secure` over HTTPS).
- A password change sets `password_changed_at` and invalidates all older sessions — existing sessions are rejected on the next request.
- **Login rate limiting** (`auth_attempts` table): 5 failed attempts / 15 min per account+IP, 100 / 15 min per IP → `429`. Duplicate-email register probes are throttled at 30 / 15 min per IP (account-enumeration mitigation). All limit helpers **fail open** if the DB is unavailable.
- Server-side role checks: admin endpoints call `require_admin()`; non-admins receive `403` with no data.
- Users can only read/modify **their own** transaction rows (`WHERE user_id = ?`).
- Strict input validation: string fields via `scalar_string()` (arrays/objects/bools rejected), amounts must be numeric, finite, `> 0` and `≤ 1,000,000,000`, dates via `valid_date()`, email via `FILTER_VALIDATE_EMAIL`.
- Security response headers on every page/API response: `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, `Permissions-Policy` (no geolocation/microphone/camera), `Cache-Control: no-store`; `Strict-Transport-Security` is added automatically over HTTPS.
- `signin.php` / `signup.php` escape output with `htmlspecialchars()`.
- **AI Assistant safety**: the AI only ever receives your own structured records (produced by `services/FinanceData.php` with prepared statements and a server-bound `user_id`) — it is **never** given arbitrary SQL, other users' data, your password, or API keys. The OpenAI key is read only server-side (`OPENAI_API_KEY` env or `ai_api_key` in `db-config.php`) and never sent to the browser. Questions are guarded against prompt-injection patterns (`ai_question_guard`), the model must return a strict JSON shape, and every reply is escaped/capped before rendering. Chat turns are also rate-limited per user (`ai_chat`, fails open).

---

## 🚀 Production Deployment Notes (D1)

The app runs on plain Apache/PHP and is designed to be deployed behind TLS:

1. **TLS is required.** Terminate HTTPS at Apache (mod_ssl virtual host) or a reverse proxy (nginx/Caddy/HAProxy). `session.cookie_secure`, HSTS, and the `Secure` cookie flag are enabled **automatically** when the request arrives over HTTPS.
2. **Redirect HTTP → HTTPS.** Keep the plain-`80` vhost as a redirect only; never serve the app over cleartext.
3. **Hide server internals** (already applied in this deployment):
   - `php.ini`: `expose_php = Off`
   - Apache `httpd-default.conf`: `ServerTokens Prod`, `ServerSignature Off`
4. **Session hardening** is handled in `api/config.php` → `start_secure_session()` (`HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS, strict mode). If you also want them enforced in `php.ini`: `session.cookie_httponly=1`, `session.cookie_secure=1`, `session.use_strict_mode=1`.
5. **Rate limits are per-IP by design** (fail open if the DB is unreachable). Behind a proxy, ensure `REMOTE_ADDR` is the real client IP (e.g. nginx `proxy_set_header` + correct Apache `RemoteIP` config) — the app deliberately ignores spoofable headers like `X-Forwarded-For`.
6. **Change the seeded demo credentials** (`Admin0112@gmail.com`, `pass123` staff accounts) before any real deployment, and consider `install.php reset` on a fresh instance only.

---

## 📱 Mobile App (Android / iOS via Capacitor)

The `mobile/` folder is an installable mobile app for Android & iOS built on **Capacitor** (package `com.moneywise.app`). It is a mobile-first SPA (`mobile/www`) that talks to the same PHP API over HTTPS using **bearer tokens** instead of cookies.

### How it authenticates
- `POST api/auth.php?action=login` (or `register`) with `want_token=1` returns a long-lived `token` (random 64-hex, only its SHA-256 hash is stored server-side).
- The app sends `Authorization: Bearer <token>` on every request; `logout` revokes it; changing the password invalidates all previously issued tokens.
- Tokens are time-limited (`TOKEN_LIFETIME_DAYS`, default 180). See `api/config.php` (`issue_token`, `current_user_by_token`).

### Structure
```
mobile/
├── www/                    # Mobile SPA (index.html, css/app.css, js/*, js/vendor/jspdf)
│   └── js/config.js        # MW_API_BASE — set to your HTTPS API URL
├── resources/              # Generated icon + splash assets
├── scripts/
│   ├── set-env.mjs         # Rewrites www/js/config.js with your API URL
│   └── gen-assets.mjs      # Regenerates icons/splash from one logo image
├── android/                # Generated Capacitor Android project
├── ios/                    # Generated Capacitor iOS project
└── capacitor.config.json   # appId com.moneywise.app, androidScheme https
```

### Prerequisites
- Node.js 18+, and for Android builds: Android Studio + JDK 17; for iOS builds: macOS + Xcode + CocoaPods.
- The PHP backend reachable over **HTTPS** (the app refuses cleartext; the API already enables CORS for `https://localhost` and `capacitor://localhost`).

### 1. Point the app at your backend
```bash
cd mobile
node scripts/set-env.mjs https://your-domain.com/MoneyManagement1/api
```

### 2. Sync the native projects
```bash
cd mobile
npm run sync          # rebuild www + copy into android/ and ios/
```

### 3. Build an APK / AAB (Android)
```bash
cd mobile
npm run android:apk:debug   # -> android/app/build/outputs/apk/debug/app-debug.apk
npm run android:aab:release # -> android/app/build/outputs/bundle/release/app-release.aab
```
On a machine with Android Studio installed, open `mobile/android` and use **Build → Generate Signed Bundle/APK** for a signed release (set `applicationId com.moneywise.app`, minSdk 23, targetSdk 35 — already configured).

### 4. Build an IPA (iOS)
```bash
cd mobile
npx cap sync ios
npx cap open ios    # on macOS with Xcode: set a signing team, then Product → Archive
```
The iOS scheme (`capacitor://localhost`) is preconfigured in `capacitor.config.json`.

### Dev / e2e against a local API
For local testing the API is served at `http://localhost/MoneyManagement1/api` (CORS allows `http://localhost:8100`), so `mobile/www` can be served statically (e.g. `npx serve mobile/www -l 8100`) and used in a browser or with a dev-build of the native app.

---

## 🧪 Testing

The project is covered by two Node.js test suites that render the app against a jsdom-like stub:

- `admin_test.js` — **147 tests**: auth, transactions, profile, settings, salary, admin flows, and PDF report UI + data helpers (`txsOnDate`, `txsInMonth`, `txsInYear`, category totals, percentages).
- `render_test2.js` — **44 tests**: general render/regression suite for the SPA.

Run them with:

```
node admin_test.js
node render_test2.js
```

Both suites are generated from `gen_admin_test.js` / `gen_render_test2.js`. (These four files are not part of this checkout — see `tests/security/` for the suites that ship with it.)

### Security suites (`tests/security/`)

These run against a **live** instance and need the seeded admin account:

```
node tests/security/api_security_tests.js
node tests/security/mobile_token_tests.js
```

- `api_security_tests.js` — **86 checks**: authN/authZ, IDOR, RBAC, session, validation, SQLi, error paths.

---

## 📝 Notes

- The live deployment runs from a web server root (e.g. XAMPP `htdocs`); this repository folder is kept in sync as the source/backup.
- `moneywise_app (1).html` is a legacy standalone prototype — the current app is the PHP version in `index.php`.

---

*Made with ❤️ — keep your money wise.*
