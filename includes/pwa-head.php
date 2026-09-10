<?php
/**
 * Shared PWA metadata — include inside <head> on EVERY page.
 * Users can install from whichever page they're on (usually signin.php), so the
 * manifest, icons and theme must be identical site-wide.
 * Bump ?v= here and in manifest.json whenever the icon files change.
 */
?>
<link rel="manifest" href="/manifest.json">
<meta name="application-name" content="MoneyWise">
<meta name="theme-color" content="#7c3aed">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="MoneyWise">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/pwa-icons/apple-touch-icon.png?v=2">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/pwa-icons/favicon-32.png?v=2">
<link rel="icon" type="image/png" sizes="192x192" href="/assets/pwa-icons/icon-192.png?v=2">
<style>
/* Top of screen: the bottom nav and AI button already occupy the bottom. */
.mw-install{position:fixed;top:calc(12px + env(safe-area-inset-top));left:50%;transform:translateX(-50%);z-index:180;display:flex;align-items:center;gap:10px;padding:8px 6px 8px 10px;width:max-content;max-width:calc(100% - 24px);background:#fff;border-radius:999px;box-shadow:0 8px 28px rgba(124,58,237,.28);font:600 13px/1.2 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#1e1b2e;animation:mwInstallIn .3s ease}
.mw-install[hidden]{display:none}
.mw-install img{width:30px;height:30px;border-radius:8px;flex-shrink:0}
.mw-install-go{border:0;border-radius:999px;padding:9px 16px;background:#7c3aed;color:#fff;font:inherit;cursor:pointer;white-space:nowrap}
.mw-install-x{border:0;background:none;color:#8b85a0;font-size:20px;line-height:1;padding:4px 8px;cursor:pointer}
@keyframes mwInstallIn{from{opacity:0;transform:translate(-50%,-10px)}to{opacity:1;transform:translate(-50%,0)}}
</style>
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('/service-worker.js')
      .catch(function (err) { console.log('SW registration failed:', err); });
  });
}

// "Install MoneyWise" banner. Chrome fires beforeinstallprompt only when the app is
// installable and not already installed, so it never appears inside the installed app.
(function () {
  var promptEvent = null, banner = null;
  function hide() { if (banner) banner.hidden = true; }
  function show() {
    if (!banner) {
      banner = document.createElement('div');
      banner.className = 'mw-install';
      banner.innerHTML = '<img src="/assets/pwa-icons/icon-96.png?v=2" alt="">'
        + '<span>Get the MoneyWise app</span>'
        + '<button type="button" class="mw-install-go">Install</button>'
        + '<button type="button" class="mw-install-x" aria-label="Dismiss">&times;</button>';
      banner.querySelector('.mw-install-go').addEventListener('click', function () {
        if (!promptEvent) return;
        promptEvent.prompt();
        promptEvent.userChoice.then(function () { promptEvent = null; hide(); });
      });
      banner.querySelector('.mw-install-x').addEventListener('click', function () {
        hide();
        try { sessionStorage.setItem('mwInstallDismissed', '1'); } catch (e) {}
      });
      document.body.appendChild(banner);
    }
    banner.hidden = false;
  }
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    promptEvent = e;
    try { if (sessionStorage.getItem('mwInstallDismissed')) return; } catch (err) {}
    if (document.body) show(); else document.addEventListener('DOMContentLoaded', show);
  });
  window.addEventListener('appinstalled', function () { promptEvent = null; hide(); });
})();
</script>
