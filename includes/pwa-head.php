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
<!-- "default", not black-translucent: pages don't pad for the notch/Dynamic Island. -->
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="MoneyWise">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/pwa-icons/apple-touch-icon.png?v=2">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/pwa-icons/favicon-32.png?v=2">
<link rel="icon" type="image/png" sizes="192x192" href="/assets/pwa-icons/icon-192.png?v=2">
<style>
/* Top of screen: the bottom nav and AI button already occupy the bottom. */
.mw-install{position:fixed;top:calc(12px + env(safe-area-inset-top));left:50%;transform:translateX(-50%);z-index:180;display:flex;align-items:center;gap:10px;padding:8px 6px 8px 10px;width:max-content;max-width:calc(100% - 24px);background:#fff;border-radius:999px;box-shadow:0 8px 28px rgba(124,58,237,.28);font:600 13px/1.2 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#1e1b2e;animation:mwInstallIn .3s ease}
.mw-install[hidden],.mw-ios-sheet[hidden]{display:none}
.mw-install img{width:30px;height:30px;border-radius:8px;flex-shrink:0}
.mw-install-go{border:0;border-radius:999px;padding:9px 16px;background:#7c3aed;color:#fff;font:inherit;cursor:pointer;white-space:nowrap}
.mw-install-x{border:0;background:none;color:#8b85a0;font-size:20px;line-height:1;padding:4px 8px;cursor:pointer}
@keyframes mwInstallIn{from{opacity:0;transform:translate(-50%,-10px)}to{opacity:1;transform:translate(-50%,0)}}
/* iOS has no install prompt API, so iPhone/iPad users get Add to Home Screen steps. */
.mw-ios-sheet{position:fixed;inset:0;z-index:260;display:flex;align-items:flex-end;justify-content:center;background:rgba(30,27,46,.5);font:14px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#1e1b2e}
.mw-ios-card{width:100%;max-width:430px;box-sizing:border-box;background:#fff;border-radius:22px 22px 0 0;padding:22px 22px calc(22px + env(safe-area-inset-bottom))}
.mw-ios-card h3{margin:0 0 2px;font-size:17px}
.mw-ios-card p{margin:0 0 12px;color:#6b6585}
.mw-ios-card ol{margin:0;padding:0;list-style:none;counter-reset:mwstep}
.mw-ios-card li{display:flex;align-items:center;gap:10px;padding:11px 0;border-top:1px solid #f0ebff}
.mw-ios-card li::before{counter-increment:mwstep;content:counter(mwstep);flex-shrink:0;width:24px;height:24px;border-radius:50%;background:#ede9fe;color:#7c3aed;font-weight:700;font-size:12px;display:flex;align-items:center;justify-content:center}
.mw-ios-card svg{width:22px;height:22px;flex-shrink:0;color:#007aff}
.mw-ios-ok{margin-top:12px;width:100%;border:0;border-radius:999px;padding:12px;background:#7c3aed;color:#fff;font:600 15px system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;cursor:pointer}
</style>
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('/service-worker.js')
      .catch(function (err) { console.log('SW registration failed:', err); });
  });
}

// "Install MoneyWise" banner.
// Android/desktop Chrome: fires beforeinstallprompt only when installable and not installed.
// iOS: no install API exists, so the banner opens Add to Home Screen instructions instead.
(function () {
  var ua = navigator.userAgent;
  var isIOS = /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
  var standalone = navigator.standalone === true || matchMedia('(display-mode: standalone)').matches;
  var promptEvent = null, banner = null, sheet = null;

  var SHARE_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12M8 7l4-4 4 4"/><path d="M7 10H6a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-7a2 2 0 0 0-2-2h-1"/></svg>';
  var ADD_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="4"/><path d="M12 8v8M8 12h8"/></svg>';

  function dismissed() {
    try { return !!sessionStorage.getItem('mwInstallDismissed'); } catch (e) { return false; }
  }
  function whenReady(fn) {
    if (document.body) fn(); else document.addEventListener('DOMContentLoaded', fn);
  }
  function hide() { if (banner) banner.hidden = true; }

  function install() {
    if (promptEvent) {
      promptEvent.prompt();
      promptEvent.userChoice.then(function () { promptEvent = null; hide(); });
    } else if (isIOS) {
      showIosSteps();
    }
  }

  function showBanner() {
    if (standalone || dismissed()) return;
    if (!banner) {
      banner = document.createElement('div');
      banner.className = 'mw-install';
      banner.innerHTML = '<img src="/assets/pwa-icons/icon-96.png?v=2" alt="">'
        + '<span>Get the MoneyWise app</span>'
        + '<button type="button" class="mw-install-go">Install</button>'
        + '<button type="button" class="mw-install-x" aria-label="Dismiss">&times;</button>';
      banner.querySelector('.mw-install-go').addEventListener('click', install);
      banner.querySelector('.mw-install-x').addEventListener('click', function () {
        hide();
        try { sessionStorage.setItem('mwInstallDismissed', '1'); } catch (e) {}
      });
      document.body.appendChild(banner);
    }
    banner.hidden = false;
  }

  function showIosSteps() {
    if (!sheet) {
      // In-app browsers (Instagram, Facebook, Google app) can't add to Home Screen.
      var inAppBrowser = !/Safari\//.test(ua) || /GSA\/|FBAN|FBAV|Instagram/.test(ua);
      var shareWhere = /CriOS|EdgiOS|FxiOS/.test(ua)
        ? 'in the address bar'
        : 'in Safari’s toolbar (or under the ⋯ button)';
      sheet = document.createElement('div');
      sheet.className = 'mw-ios-sheet';
      sheet.setAttribute('role', 'dialog');
      sheet.setAttribute('aria-label', 'Install MoneyWise');
      sheet.innerHTML = '<div class="mw-ios-card">'
        + '<h3>Install MoneyWise</h3>'
        + '<p>Add it to your Home Screen to open it like an app.</p>'
        + '<ol>'
        + (inAppBrowser ? '<li><span>Open this page in <b>Safari</b> first (⋯ menu → Open in Safari).</span></li>' : '')
        + '<li><span>Tap <b>Share</b> ' + shareWhere + '.</span>' + SHARE_ICON + '</li>'
        + '<li><span>Scroll down and tap <b>Add to Home Screen</b>.</span>' + ADD_ICON + '</li>'
        + '<li><span>Keep <b>Open as Web App</b> on if shown, then tap <b>Add</b>.</span></li>'
        + '</ol>'
        + '<button type="button" class="mw-ios-ok">Got it</button>'
        + '</div>';
      sheet.addEventListener('click', function (e) {
        if (e.target === sheet || e.target.classList.contains('mw-ios-ok')) sheet.hidden = true;
      });
      document.body.appendChild(sheet);
    }
    sheet.hidden = false;
  }

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    promptEvent = e;
    whenReady(showBanner);
  });
  window.addEventListener('appinstalled', function () { promptEvent = null; hide(); });
  if (isIOS) whenReady(showBanner);
})();
</script>
