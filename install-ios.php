<?php
/**
 * iPhone/iPad installer: a configuration profile containing one full-screen Web Clip.
 *
 * For iPhones whose Share sheet has no "Add to Home Screen". Open in Safari → Allow →
 * Settings → Profile Downloaded → Install. It only adds the MoneyWise Home Screen icon.
 * The profile is unsigned, so iOS labels it "Not Verified".
 */
declare(strict_types=1);

$host = (string)($_SERVER['HTTP_HOST'] ?? '');
if (!preg_match('/^[A-Za-z0-9.-]+(:\d{1,5})?$/', $host)) {
    http_response_code(400);
    exit('Invalid host');
}
// Render terminates TLS at its proxy, so Apache itself only sees plain HTTP.
$https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
$appUrl = htmlspecialchars(($https ? 'https' : 'http') . '://' . $host . '/index.php', ENT_XML1 | ENT_QUOTES, 'UTF-8');

$iconFile = __DIR__ . '/assets/pwa-icons/apple-touch-icon.png';
$icon = is_file($iconFile) ? base64_encode((string)file_get_contents($iconFile)) : '';

// Stable per-host identifiers: reinstalling replaces the profile instead of adding a duplicate icon.
function stable_uuid(string $seed): string
{
    $h = md5($seed);
    return strtoupper(substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12));
}
$hostId = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($host)), '-');
$profileUuid = stable_uuid('moneywise-profile:' . $host);
$clipUuid = stable_uuid('moneywise-webclip:' . $host);
$iconXml = $icon !== '' ? "<key>Icon</key>\n      <data>{$icon}</data>" : '';

header('Content-Type: application/x-apple-aspen-config');
header('Content-Disposition: attachment; filename="MoneyWise.mobileconfig"');
header('Cache-Control: no-store');

echo <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>PayloadContent</key>
  <array>
    <dict>
      <key>FullScreen</key>
      <true/>
      {$iconXml}
      <key>IsRemovable</key>
      <true/>
      <key>Label</key>
      <string>MoneyWise</string>
      <key>PayloadDescription</key>
      <string>Adds the MoneyWise app icon to your Home Screen.</string>
      <key>PayloadDisplayName</key>
      <string>MoneyWise</string>
      <key>PayloadIdentifier</key>
      <string>com.moneywise.webclip.{$hostId}.clip</string>
      <key>PayloadType</key>
      <string>com.apple.webClip.managed</string>
      <key>PayloadUUID</key>
      <string>{$clipUuid}</string>
      <key>PayloadVersion</key>
      <integer>1</integer>
      <key>Precomposed</key>
      <true/>
      <key>URL</key>
      <string>{$appUrl}</string>
    </dict>
  </array>
  <key>PayloadDescription</key>
  <string>Installs the MoneyWise app on your Home Screen. It only adds an app icon and changes no other settings.</string>
  <key>PayloadDisplayName</key>
  <string>MoneyWise App</string>
  <key>PayloadIdentifier</key>
  <string>com.moneywise.webclip.{$hostId}</string>
  <key>PayloadOrganization</key>
  <string>MoneyWise</string>
  <key>PayloadRemovalDisallowed</key>
  <false/>
  <key>PayloadType</key>
  <string>Configuration</string>
  <key>PayloadUUID</key>
  <string>{$profileUuid}</string>
  <key>PayloadVersion</key>
  <integer>1</integer>
</dict>
</plist>
XML;
