/**
 * Money Wise — build-time environment setup.
 *
 * Rewrites www/js/config.js with the API base URL from the MW_API_BASE
 * environment variable (falls back to the file's current value). Run this
 * before `npx cap sync` / building an APK or IPA so the app talks to the
 * correct production API:
 *
 *   PowerShell:  $env:MW_API_BASE="https://moneywise.example.com/api"; npm run build:www
 *   bash:        MW_API_BASE=https://moneywise.example.com/api npm run build:www
 *
 * The default (no env var) is the local XAMPP dev server. The DB credentials
 * are NEVER bundled into the app — the API server owns them.
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = resolve(fileURLToPath(import.meta.url), '..');
const file = resolve(__dirname, '..', 'www', 'js', 'config.js');

const base = process.env.MW_API_BASE;
if (!base) {
  console.log('[set-env] MW_API_BASE not set — leaving config.js unchanged.');
  process.exit(0);
}
const url = base.replace(/\/+$/, '');
const src = readFileSync(file, 'utf8');
const out = src.replace(/var MW_API_BASE = '[^']*';/, `var MW_API_BASE = '${url}';`);
if (out === src) {
  console.error('[set-env] Could not find MW_API_BASE in config.js');
  process.exit(1);
}
writeFileSync(file, out);
console.log('[set-env] API base set to:', url);
