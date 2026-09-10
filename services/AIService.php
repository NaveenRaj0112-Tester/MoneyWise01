<?php
/**
 * MoneyWise AIService — supports Gemini (Google AI) and OpenAI providers.
 *
 * The provider is auto-detected from the key format:
 *   - Keys starting with "AIza" → Gemini (generativelanguage.googleapis.com)
 *   - Everything else → OpenAI (api.openai.com)
 *
 * The API key is read ONLY from the server environment (GEMINI_API_KEY or
 * OPENAI_API_KEY) or from db-config.php (`ai_api_key` / `gemini_api_key`),
 * never from the browser.
 */
declare(strict_types=1);

function ai_provider(): string
{
    $key = ai_key();
    if ($key !== '' && str_starts_with($key, 'AIza')) {
        return 'gemini';
    }
    return 'openai';
}

function ai_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    // Try Gemini key first, then OpenAI
    $key = (string)getenv('GEMINI_API_KEY');
    if ($key === '' && is_file(dirname(__DIR__) . '/db-config.php')) {
        $cfg = (array)require dirname(__DIR__) . '/db-config.php';
        $key = (string)($cfg['gemini_api_key'] ?? '');
    }
    if ($key === '') {
        $key = (string)getenv('OPENAI_API_KEY');
    }
    if ($key === '' && is_file(dirname(__DIR__) . '/db-config.php')) {
        $cfg = (array)require dirname(__DIR__) . '/db-config.php';
        $key = (string)($cfg['ai_api_key'] ?? '');
    }
    return $key;
}

function ai_configured(): bool
{
    return ai_key() !== '';
}

function ai_model(): string
{
    $provider = ai_provider();
    if ($provider === 'gemini') {
        $m = trim((string)getenv('AI_MODEL'));
        return $m !== '' ? $m : 'gemini-2.0-flash';
    }
    $m = trim((string)getenv('AI_MODEL'));
    return $m !== '' ? $m : 'gpt-4o-mini';
}

function ai_http_post_json(string $url, array $payload, array $headers, int $timeout = 30): array
{
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'timeout'       => $timeout,
            'ignore_errors' => true,
            'header'        => "Content-Type: application/json\r\n" . implode("\r\n", $headers) . "\r\n",
            'content'       => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        throw new RuntimeException('AI provider unreachable.');
    }
    $json = json_decode($body, true);
    return is_array($json) ? $json : [];
}

/**
 * Send a single assistant turn and return the model's answer text.
 * Auto-routes to Gemini or OpenAI based on the key format.
 */
function ai_ask(array $history, string $system, int $timeout = 30): array
{
    if (!ai_configured()) {
        throw new RuntimeException('AI is not configured. Set GEMINI_API_KEY or OPENAI_API_KEY.');
    }

    $provider = ai_provider();
    if ($provider === 'gemini') {
        return ai_ask_gemini($history, $system, $timeout);
    }
    return ai_ask_openai($history, $system, $timeout);
}

/**
 * Gemini API — uses the generateContent endpoint with system instruction.
 */
function ai_ask_gemini(array $history, string $system, int $timeout = 30): array
{
    $key  = ai_key();
    $model = ai_model();
    $url  = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";

    // Build contents array (Gemini format: user/model turns, no system role)
    $contents = [];
    foreach ($history as $m) {
        $role = ($m['role'] === 'assistant') ? 'model' : 'user';
        $contents[] = [
            'role'  => $role,
            'parts' => [['text' => (string)$m['content']]],
        ];
    }

    $payload = [
        'contents' => $contents,
        'systemInstruction' => [
            'parts' => [['text' => $system]],
        ],
        'generationConfig' => [
            'temperature'     => 0.2,
            'maxOutputTokens' => 1024,
            'responseMimeType'=> 'application/json',
        ],
    ];

    $data = ai_http_post_json($url, $payload, [], $timeout);

    // Handle errors
    if (isset($data['error'])) {
        $msg = $data['error']['message'] ?? 'Unknown error';
        if (strpos($msg, 'API key not valid') !== false || strpos($msg, 'PERMISSION_DENIED') !== false) {
            throw new RuntimeException('AI is not configured correctly.');
        }
        throw new RuntimeException('AI assistant could not respond. Please try again.');
    }

    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if (!is_string($text) || trim($text) === '') {
        throw new RuntimeException('AI assistant returned an empty response.');
    }

    $parsed = json_decode($text, true);
    if (!is_array($parsed)) {
        if (preg_match('/"answer"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $text, $m)) {
            $parsed = ['answer' => stripcslashes($m[1])];
        } else {
            throw new RuntimeException('AI assistant could not respond. Please try again.');
        }
    }

    $reply = $parsed['answer'] ?? null;
    if (!is_string($reply) || trim($reply) === '') {
        throw new RuntimeException('AI assistant could not respond. Please try again.');
    }
    return ['reply' => trim($reply)];
}

/**
 * OpenAI API — original chat completions endpoint.
 */
function ai_ask_openai(array $history, string $system, int $timeout = 30): array
{
    $messages = [['role' => 'system', 'content' => $system]];
    foreach ($history as $m) {
        $messages[] = [
            'role'    => ($m['role'] === 'assistant') ? 'assistant' : 'user',
            'content' => (string)$m['content'],
        ];
    }

    $payload = [
        'model'               => ai_model(),
        'messages'            => $messages,
        'max_tokens'          => 700,
        'temperature'         => 0.2,
        'response_format'     => ['type' => 'json_object'],
    ];

    $headers = ['Authorization: Bearer ' . ai_key()];
    $data = ai_http_post_json('https://api.openai.com/v1/chat/completions', $payload, $headers, $timeout);

    if (isset($data['error'])) {
        $code = (int)($data['error']['code'] ?? 0);
        $type = (string)($data['error']['type'] ?? '');
        if ($code === 429 || strpos($type, 'rate_limit') !== false) {
            throw new RuntimeException('AI assistant is busy. Please try again in a moment.');
        }
        if ($code === 401) {
            throw new RuntimeException('AI is not configured correctly.');
        }
        throw new RuntimeException('AI assistant could not respond. Please try again.');
    }

    $text = $data['choices'][0]['message']['content'] ?? '';
    if (!is_string($text) || trim($text) === '') {
        throw new RuntimeException('AI assistant returned an empty response.');
    }

    $parsed = json_decode($text, true);
    if (!is_array($parsed)) {
        if (preg_match('/"answer"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $text, $m)) {
            $parsed = ['answer' => stripcslashes($m[1])];
        } else {
            throw new RuntimeException('AI assistant could not respond. Please try again.');
        }
    }

    $reply = $parsed['answer'] ?? null;
    if (!is_string($reply) || trim($reply) === '') {
        throw new RuntimeException('AI assistant could not respond. Please try again.');
    }
    return ['reply' => trim($reply)];
}