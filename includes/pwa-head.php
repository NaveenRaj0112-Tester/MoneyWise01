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
/* In normal page flow at the top, so it never covers the sign-in card, bottom nav or AI button. */
.mw-install{position:relative;z-index:180;display:flex;align-items:center;gap:10px;margin:12px auto 0;padding:8px 6px 8px 10px;width:max-content;max-width:calc(100% - 24px);box-sizing:border-box;background:#fff;border-radius:999px;box-shadow:0 8px 28px rgba(124,58,237,.28);font:600 13px/1.2 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#1e1b2e;animation:mwFadeIn .3s ease}
.mw-install[hidden],.mw-ios-tip[hidden]{display:none}
.mw-install img{width:30px;height:30px;border-radius:8px;flex-shrink:0}
.mw-install-go{border:0;border-radius:999px;padding:9px 16px;background:#7c3aed;color:#fff;font:inherit;cursor:pointer;white-space:nowrap}
.mw-install-x{border:0;background:none;color:#8b85a0;font-size:20px;line-height:1;padding:4px 8px;cursor:pointer}
/* iOS has no install API: a hint that stays on screen and points at the browser's Share button. */
.mw-ios-tip{position:fixed;left:12px;right:12px;z-index:260;max-width:360px;margin:0 auto;box-sizing:border-box;padding:14px 40px 14px 16px;background:#1e1b2e;color:#fff;border-radius:16px;box-shadow:0 12px 32px rgba(30,27,46,.35);font:14px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
.mw-ios-tip::after{content:'';position:absolute;width:16px;height:16px;background:#1e1b2e;transform:rotate(45deg)}
.mw-ios-tip.mw-bottom{bottom:calc(16px + env(safe-area-inset-bottom));animation:mwFadeIn .25s ease,mwBobDown 1.6s ease-in-out .3s infinite}
.mw-ios-tip.mw-bottom::after{bottom:-7px;left:calc(50% - 8px)}
.mw-ios-tip.mw-top{top:calc(14px + env(safe-area-inset-top));animation:mwFadeIn .25s ease,mwBobUp 1.6s ease-in-out .3s infinite}
.mw-ios-tip.mw-top::after{top:-7px;right:24px}
.mw-ios-tip.mw-noarrow{animation:mwFadeIn .25s ease}
.mw-ios-tip.mw-noarrow::after{display:none}
.mw-ios-tip b{color:#c4b5fd}
.mw-ios-tip small{display:block;margin-top:6px;color:#b8b3cc;font-size:12px}
.mw-ios-tip svg{width:18px;height:18px;vertical-align:-3px;color:#60a5fa}
.mw-ios-tip-x{position:absolute;top:6px;right:6px;border:0;background:none;color:#b8b3cc;font-size:20px;line-height:1;padding:6px 8px;cursor:pointer}
@keyframes mwFadeIn{from{opacity:0}to{opacity:1}}
@keyframes mwBobDown{0%,100%{transform:translateY(0)}50%{transform:translateY(5px)}}
@keyframes mwBobUp{0%,100%{transform:translateY(0)}50%{transform:translateY(-5px)}}
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
// iOS: websites can't install themselves (Apple provides no API), so Install shows a hint
// pointing at the browser's Share button -> Add to Home Screen.
(function () {
  var ua = navigator.userAgent;
  var isIOS = /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
  var standalone = navigator.standalone === true || matchMedia('(display-mode: standalone)').matches;
  var promptEvent = null, banner = null, tip = null;

  var SHARE_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12M8 7l4-4 4 4"/><path d="M7 10H6a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-7a2 2 0 0 0-2-2h-1"/></svg>';

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
      hide();
      showIosTip();
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
      document.body.insertBefore(banner, document.body.firstChild);
    }
    banner.hidden = false;
  }

  function showIosTip() {
    if (!tip) {
      // In-app browsers (Instagram, Facebook, Google app) have no Add to Home Screen.
      var inAppBrowser = !/Safari\//.test(ua) || /GSA\/|FBAN|FBAV|Instagram/.test(ua);
      var otherBrowser = /CriOS|EdgiOS|FxiOS/.test(ua); // Share lives in the top address bar
      var hint = '<small>Not in the list? Scroll to the bottom, tap <b>Edit Actions</b> and add it.</small>';
      var place, html;
      if (inAppBrowser) {
        place = 'mw-top mw-noarrow';
        html = 'Open this page in <b>Safari</b> first (⋯ menu → Open in Safari), then tap Install again.';
      } else if (otherBrowser) {
        place = 'mw-top';
        html = 'Tap <b>Share</b> ' + SHARE_ICON + ' in the address bar, then <b>Add to Home Screen</b>.' + hint;
      } else {
        place = 'mw-bottom';
        html = 'Tap <b>Share</b> ' + SHARE_ICON + ' below (or <b>⋯</b> → Share), then <b>Add to Home Screen</b>.' + hint;
      }
      tip = document.createElement('div');
      tip.className = 'mw-ios-tip ' + place;
      tip.setAttribute('role', 'status');
      tip.innerHTML = html + '<button type="button" class="mw-ios-tip-x" aria-label="Close">&times;</button>';
      tip.querySelector('.mw-ios-tip-x').addEventListener('click', function () {
        tip.hidden = true;
        if (banner) banner.hidden = false;
      });
      document.body.appendChild(tip);
    }
    tip.hidden = false;
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
