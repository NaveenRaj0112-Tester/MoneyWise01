<?php
/**
 * MoneyWise AIService — isolated OpenAI provider integration.
 *
 * Talks to the OpenAI Responses API using the current chat-completions model
 * (gpt-4o-mini). The API key is read ONLY from the server environment
 * (OPENAI_API_KEY) or from db-config.php (`ai_api_key`), never from the browser.
 * The key is never echoed, logged or persisted — chat history stores message
 * text only.
 *
 * A stable, JSON-only answer is requested so the endpoint can strongly validate
 * the reply and never redirect the raw AI output back to the client.
 */
declare(strict_types=1);

function ai_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    $key = (string)getenv('OPENAI_API_KEY');
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
    $m = trim((string)getenv('AI_MODEL'));
    return $m !== '' ? $m : 'gpt-4o-mini';
}

/** A short, capped, common-label model list used for validation is not needed —
 *  the client always picks from a fixed set; we validate client input in the API. */

/**
 * HTTP POST helper using PHP streams (works without the cURL extension).
 * Returns the decoded body or throws a RuntimeException for transport errors.
 */
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
 *
 * `history` must already be a bounded, role-trimmed list: [{role:'user'|'assistant', content}, ...],
 * newest last. `system` is the fixed system instruction. Returns the assistant
 * message text on success. Throws RuntimeException with a safe, non-leaky message
 * when the provider is unavailable, misconfigured or returns garbage.
 *
 * @return array { reply: string }
 */
function ai_ask(array $history, string $system, int $timeout = 30): array
{
    if (!ai_configured()) {
        throw new RuntimeException('AI is not configured. Set OPENAI_API_KEY.');
    }

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
        // The model occasionally wraps JSON in prose; try to extract the reply key.
        if (preg_match('/"answer"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $text, $m)) {
            $parsed = ['answer' => stripcslashes($m[1])];
        } else {
            throw new RuntimeException('AI assistant could not respond. Please try again.');
        }
    }

    $reply = isset($parsed['answer']) && is_string($parsed['answer']) && trim($parsed['answer']) !== ''
        ? trim($parsed['answer'])
        : null;
    if ($reply === null) {
        throw new RuntimeException('AI assistant could not respond. Please try again.');
    }
    return ['reply' => $reply];
}