/**
 * Money Wise — asset generator.
 * Creates @capacitor/assets source images from Mlogo/MoneywiseLOGO.png:
 *   resources/icon-only.png       1024x1024 app icon
 *   resources/icon-foreground.png 1024x1024 (adaptive foreground, safe-zone scaled)
 *   resources/icon-background.png 1024x1024 solid purple
 *   resources/splash.png          2732x2732 light splash
 *   resources/splash-dark.png     2732x2732 dark splash
 * Run:  node scripts/gen-assets.mjs
 */
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import sharp from 'sharp';

const __dirname = resolve(fileURLToPath(import.meta.url), '..');
const root = resolve(__dirname, '..');
const logo = resolve(root, 'www', 'img', 'logo.png');
const out = resolve(root, 'resources');
mkdirSync(out, { recursive: true });

const PURPLE = '#7c3aed';
const DARK = '#1e1b2e';

await sharp(logo).resize(1024, 1024).png().toFile(resolve(out, 'icon-only.png'));
console.log('[gen-assets] icon-only.png');

// Adaptive icon foreground: logo scaled into the 66% safe zone on transparency.
await sharp(logo)
  .resize(680, 680, { fit: 'inside' })
  .resize(1024, 1024, { fit: 'contain', background: { r: 0, g: 0, b: 0, alpha: 0 } })
  .png()
  .toFile(resolve(out, 'icon-foreground.png'));
console.log('[gen-assets] icon-foreground.png');

await sharp({ create: { width: 1024, height: 1024, channels: 4, background: PURPLE } })
  .png()
  .toFile(resolve(out, 'icon-background.png'));
console.log('[gen-assets] icon-background.png');

async function splash(bg, dest) {
  const base = await sharp({ create: { width: 2732, height: 2732, channels: 4, background: bg } })
    .composite([{ input: logo, gravity: 'centre' }]).png().toBuffer();
  // Splash must be flattened (no alpha) for Android.
  return sharp(base).flatten({ background: bg }).png().toFile(resolve(out, dest));
}

await splash(PURPLE, 'splash.png');
console.log('[gen-assets] splash.png');
await splash(DARK, 'splash-dark.png');
console.log('[gen-assets] splash-dark.png');
