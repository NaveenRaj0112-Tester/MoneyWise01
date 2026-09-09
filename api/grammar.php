<?php
/**
 * MoneyWise Grammar endpoint — "Grammarly-style" writing assistant.
 *
 * Deterministic, fully offline text polishing. No API key required: every action
 * runs through the GrammarService in PHP. When the optional AI provider is
 * configured we still prefer the fast local path for reproducible results, so the
 * assistant works identically for every user.
 *
 * POST api/grammar.php
 *   { "action": "improve" | "rewrite" | "shorten" | "expand" | "stats", "text": "...", "tone": "professional" }
 *   -> { ok, [improved|rewritten|shortened|expanded], ... }
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';
$u = require_login();
require_once dirname(__DIR__) . '/services/GrammarService.php';

$action = scalar_string(param('action', ''));
$text   = is_string(body()['text'] ?? null) ? trim((string)body()['text']) : '';
$tone   = scalar_string(param('tone', 'professional'));

if ($action === '') {
    json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
}
if ($text === '') {
    json_out(['ok' => false, 'error' => 'No text to polish.'], 422);
}
if (mb_strlen($text) > 8000) {
    json_out(['ok' => false, 'error' => 'Text is too long. Please keep it under 8000 characters.'], 422);
}

switch ($action) {
    case 'stats':
        json_out(['ok' => true, 'stats' => grm_stats($text)]);
        break;
    case 'improve':
        $r = grm_improve($text);
        json_out($r);
        break;
    case 'rewrite':
        $r = grm_rewrite($text, $tone);
        json_out($r);
        break;
    case 'shorten':
        $r = grm_shorten($text);
        json_out($r);
        break;
    case 'expand':
        $r = grm_expand($text);
        json_out($r);
        break;
    default:
        json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
}

json_out(['ok' => false, 'error' => 'Something went wrong.'], 500);