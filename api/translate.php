<?php
/**
 * English → Tamil translation endpoint, used by the Event module's Tamil toggle.
 *
 * The client already handles Tanglish (romanized Tamil) locally and only asks the
 * server to translate genuine English phrases. This endpoint:
 *   - passes already-Tamil / non-Latin text straight through unchanged,
 *   - translates English→Tamil via an external engine,
 *   - caches results on disk so repeated phrases don't hit the network,
 *   - falls back gracefully (local dictionary → return the original text) when the
 *     external service is unreachable, so the app never breaks.
 *
 * Outbound key handling: the preferred Google Cloud Translation key is read from the
 * TRANSLATE_API_KEY environment variable (or db-config.php `translateKey`) — NEVER from
 * frontend code. When no key is configured we fall back to the keyless Google "gtx"
 * endpoint and then MyMemory, both of which work without a key.
 *
 * POST api/translate.php   { "text": "water bottle" }
 *   -> { ok: true, text: "தண்ணீர் பாட்டில்", source: "google"|"mymemory"|"dict"|"passthrough" }
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';
$u = require_login();

$root = dirname(__DIR__);
// Optional env / db-config.php override for a real API key (kept server-side only).
$translateKey = getenv('TRANSLATE_API_KEY') ?: '';
if ($translateKey === '' && is_file($root . '/db-config.php')) {
    $cfg = (array)require $root . '/db-config.php';
    $translateKey = (string)($cfg['translateKey'] ?? '');
}

/* ---------------------------------------------------------------- cache --- */

function tl_cache_dir(string $root): string
{
    $dir = $root . '/cache';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        // Fall back to sys temp so caching never hard-fails.
        $dir = sys_get_temp_dir() . '/moneywise_translate';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
    }
    return $dir;
}

function tl_cache_read(string $root, string $key): ?string
{
    $f = tl_cache_dir($root) . '/tr_' . $key . '.json';
    if (!is_file($f)) return null;
    // Cache validity = 90 days.
    if (time() - (int)@filemtime($f) > 90 * 86400) {
        @unlink($f);
        return null;
    }
    $d = json_decode((string)@file_get_contents($f), true);
    return (is_array($d) && isset($d['t']) && is_string($d['t'])) ? $d['t'] : null;
}

function tl_cache_write(string $root, string $key, string $text): void
{
    @file_put_contents(
        tl_cache_dir($root) . '/tr_' . $key . '.json',
        json_encode(['t' => $text], JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

/* ---------------------------------------------------------- providers ----- */

function tl_http_get(string $url, int $timeout = 6): ?string
{
    $ctx = stream_context_create([
        'http' => [
            'timeout'        => $timeout,
            'ignore_errors'  => true,
            'user_agent'     => 'MoneyWise/1.0',
            'header'         => "Accept: application/json\r\n",
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    return ($resp === false || $resp === '') ? null : $resp;
}

// Google Cloud Translation API v2 (requires key). Highest quality.
function tl_google_key(string $text, string $key): ?string
{
    $url = 'https://translation.googleapis.com/language/translate/v2'
        . '?key='          . rawurlencode($key)
        . '&source=en&target=ta&format=text&q=' . rawurlencode($text);
    $resp = tl_http_get($url);
    if ($resp === null) return null;
    $json = json_decode($resp, true);
    $txt = $json['data']['translations'][0]['translatedText'] ?? null;
    return (is_string($txt) && trim($txt) !== '') ? trim($txt) : null;
}

// Keyless Google "gtx" endpoint (unofficial but reliable & free).
function tl_google_gtx(string $text): ?string
{
    $url = 'https://translate.googleapis.com/translate_a/single'
        . '?client=gtx&sl=en&tl=ta&dt=t&q=' . rawurlencode($text);
    $resp = tl_http_get($url);
    if ($resp === null) return null;
    $json = json_decode($resp, true);
    if (!is_array($json) || !isset($json[0]) || !is_array($json[0])) return null;
    $parts = [];
    foreach ($json[0] as $seg) {
        if (is_array($seg) && isset($seg[0]) && is_string($seg[0])) {
            $parts[] = $seg[0];
        }
    }
    $out = trim(implode('', $parts));
    return ($out === '' || $out === $text) ? null : $out;
}

// MyMemory free tier (secondary fallback).
function tl_mymemory(string $text): ?string
{
    $url = 'https://api.mymemory.translated.net/get?langpair=en%7Cta&q=' . rawurlencode($text);
    $resp = tl_http_get($url);
    if ($resp === null) return null;
    $json = json_decode($resp, true);
    $txt = $json['responseData']['translatedText'] ?? null;
    if (!is_string($txt)) return null;
    // Guard against known junk / echo-back responses.
    $txt = trim($txt);
    if ($txt === '' || $txt === $text || stripos($txt, 'my memory translate') !== false) return null;
    return $txt;
}

// Tiny offline English→Tamil dictionary for very common expense/finance words so
// the most frequent cases still translate when the network is down.
function tl_dict(string $text): ?string
{
    $D = [
        'book'=>'புத்தகம்','books'=>'புத்தகங்கள்','water'=>'தண்ணீர்','bottle'=>'பாட்டில்',
        'food'=>'உணவு','foods'=>'உணவு','lunch'=>'மதிய உணவு','breakfast'=>'காலை உணவு',
        'dinner'=>'இரவு உணவு','tea'=>'தேநீர்','coffee'=>'காபி','milk'=>'பால்',
        'medical'=>'மருத்துவ','expense'=>'செலவு','expenses'=>'செலவுகள்','house'=>'வீடு','home'=>'வீடு',
        'rent'=>'வாடகை','vegetables'=>'காய்கறிகள்','vegetable'=>'காய்கறி','school'=>'பள்ளி',
        'fees'=>'கட்டணம்','fee'=>'கட்டணம்','groceries'=>'மளிகை','grocery'=>'மளிகை',
        'shop'=>'கடை','store'=>'கடை','clothes'=>'ஆடைகள்','clothing'=>'ஆடை','dress'=>'ஆடை',
        'transport'=>'போக்குவரத்து','travel'=>'பயணம்','ticket'=>'டிக்கெட்','gift'=>'பரிசு',
        'gifts'=>'பரிசு','birthday'=>'பிறந்த நாள்','wedding'=>'திருமணம்','marriage'=>'திருமணம்',
        'party'=>'விருந்து','celebration'=>'கொண்டாட்டம்','festival'=>'பண்டிகை','repair'=>'பழுது பார்ப்பு',
        'electricity'=>'மின்சாரம்','internet'=>'இணையம்','mobile'=>'மொபைல்','phone'=>'போன்',
        'medicine'=>'மருந்து','medicines'=>'மருந்து','doctor'=>'மருத்துவர்','hospital'=>'மருத்துவமனை',
        'fuel'=>'எரிபொருள்','petrol'=>'பெட்ரோல்','diesel'=>'டீசல்','fruits'=>'பழங்கள்','fruit'=>'பழம்',
        'mango'=>'மாம்பழம்','apple'=>'ஆப்பிள்','banana'=>'வாழைப்பழம்','rice'=>'அரிசி','oil'=>'எண்ணெய்',
        'sugar'=>'சர்க்கரை','salt'=>'உப்பு','soap'=>'சோப்பு','shampoo'=>'ஷாம்பு','soap'=>'சோப்பு',
        'cleaning'=>'சுத்தம்','wash'=>'சலவை','clothes'=>'ஆடைகள்','shoes'=>'காலணிகள்','bag'=>'பை',
        'laptop'=>'லேப்டாப்','charger'=>'சார்ஜர்','cable'=>'கேபிள்','electric'=>'மின்',
        'furniture'=>'மரச்சாமான்கள்','table'=>'மேசை','chair'=>'நாற்காலி','light'=>'விளக்கு',
        'fan'=>'மின்விசிறி','repairs'=>'பழுது பார்ப்பு','service'=>'சேவை','taxi'=>'டாக்சி',
        'auto'=>'ஆட்டோ','bus'=>'பேருந்து','train'=>'ரயில்','car'=>'கார்','parking'=>'பார்க்கிங்',
        'gift'=>'பரிசு','donation'=>'நன்கொடை','charity'=>'அறக்கொடை','insurance'=>'காப்பீடு',
        'payment'=>'கட்டணம்','payments'=>'கட்டணங்கள்','salary'=>'சம்பளம்','market'=>'சந்தை',
        'bills'=>'பில்கள்','bill'=>'பில்','cable'=>'கேபிள்','vegetable'=>'காய்கறி','fees'=>'கட்டணம்',
        'snacks'=>'தின்பண்டங்கள்','juice'=>'பழச்சாறு','cake'=>'கேக்','sweets'=>'இனிப்புகள்',
    ];
    $out = [];
    $lower = mb_strtolower(trim($text));
    $parts = preg_split('/\s+/u', $lower) ?: [];
    $allKnown = count($parts) > 0;
    foreach ($parts as $w) {
        $key = preg_replace('/[^a-z]/u', '', $w) ?: $w;
        if (isset($D[$key])) { $out[] = $D[$key]; }
        else { $allKnown = false; $out[] = $w; }
    }
    $r = implode(' ', $out);
    return $allKnown ? $r : null;
}

/* ------------------------------------------------------------- pipeline ---- */

$text = is_string(body()['text'] ?? null) ? trim((string)body()['text']) : '';
if ($text === '') {
    json_out(['ok' => true, 'text' => '', 'source' => 'passthrough']);
}
if (mb_strlen($text) > 500) {
    json_out(['ok' => false, 'error' => 'Text is too long to translate.'], 422);
}

// Already Tamil (has Tamil Unicode) or has no Latin letters → keep unchanged.
if (preg_match('/[\x{0B80}-\x{0BFF}]/u', $text)) {
    json_out(['ok' => true, 'text' => $text, 'source' => 'passthrough']);
}
if (!preg_match('/[a-zA-Z]/', $text)) {
    json_out(['ok' => true, 'text' => $text, 'source' => 'passthrough']);
}

// Cache lookup.
$cacheKey = md5('en|ta|' . strtolower($text));
$cached = tl_cache_read($root, $cacheKey);
if ($cached !== null) {
    json_out(['ok' => true, 'text' => $cached, 'source' => 'cache']);
}

// Try providers in order.
$result = null; $source = 'google';
if ($translateKey !== '') {
    $result = tl_google_key($text, $translateKey);
    $source = 'googlekey';
}
if ($result === null) { $result = tl_google_gtx($text); $source = 'google'; }
if ($result === null) { $result = tl_mymemory($text); $source = 'mymemory'; }
if ($result === null) { $result = tl_dict($text); $source = 'dict'; }

if ($result !== null) {
    tl_cache_write($root, $cacheKey, $result);
    json_out(['ok' => true, 'text' => $result, 'source' => $source]);
}

// Could not confidently translate → preserve original (per spec #10).
json_out(['ok' => true, 'text' => $text, 'source' => 'passthrough']);
