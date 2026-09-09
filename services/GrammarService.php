<?php
/**
 * MoneyWise GrammarService — deterministic, offline writing assistance.
 *
 * A "Grammarly-style" assistant that runs entirely in PHP, so it needs no API
 * key and behaves identically for every user. It covers the fixes people
 * actually need when writing a leave request, a short email or a message:
 *
 *   - capitalization (sentence starts, "i" -> "I", honorifics like "Sir")
 *   - informal spellings and contractions ("u", "ur", "pls", "dont", "im")
 *   - common subject-verb agreement slips ("i is", "he go", "she have")
 *   - punctuation and spacing, duplicate words
 *   - tone rewriting (professional / formal / friendly / casual / simple / concise)
 *   - shorten and expand
 *
 * IMPORTANT: every replacement is anchored on WORD BOUNDARIES. The previous
 * implementation used str_ireplace on bare substrings, which corrupted ordinary
 * text — "you are" became "yoyou are" (from 'u ' -> 'you ') and "him" became
 * "hI am" (from 'im ' -> 'I am '). Anything that cannot be fixed confidently is
 * left exactly as the user wrote it.
 */
declare(strict_types=1);

/** Trim, collapse runs of whitespace, and normalise punctuation spacing. */
function grm_squash(string $s): string
{
    $s = preg_replace('/[ \t]+/u', ' ', trim($s)) ?? trim($s);
    $s = preg_replace('/\s+([,.;:!?])/u', '$1', $s) ?? $s;      // " ," -> ","
    $s = preg_replace('/([,;:])(?=\S)/u', '$1 ', $s) ?? $s;      // "a,b" -> "a, b"
    $s = preg_replace('/([.!?])(?=[A-Za-z])/u', '$1 ', $s) ?? $s; // "end.Next" -> "end. Next"
    $s = preg_replace('/([.!?]){2,}/u', '$1', $s) ?? $s;          // "!!!" -> "!"
    $s = preg_replace('/ +\n/u', "\n", $s) ?? $s;
    return trim($s);
}

/** Drop accidental duplicate words: "the the", "and and", "is is". */
function grm_dedup(string $s): string
{
    return preg_replace('/\b(\w+)(\s+)\1\b/iu', '$1', $s) ?? $s;
}

/**
 * Word-for-word replacement map applied with word boundaries only.
 * Keys are matched case-insensitively; the replacement keeps its own casing
 * because these are all fixed forms ("I", "please", "don't").
 */
function grm_word_map(): array
{
    return [
        'u'        => 'you',
        'ur'       => 'your',
        'urs'      => 'yours',
        'r'        => 'are',
        'pls'      => 'please',
        'plz'      => 'please',
        'plss'     => 'please',
        'thx'      => 'thanks',
        'ty'       => 'thank you',
        'i'        => 'I',
        'im'       => "I am",
        'ive'      => "I have",
        'ill'      => "I will",
        'id'       => "I would",
        'dont'     => "don't",
        'doesnt'   => "doesn't",
        'didnt'    => "didn't",
        'cant'     => "can't",
        'wont'     => "won't",
        'isnt'     => "isn't",
        'arent'    => "aren't",
        'wasnt'    => "wasn't",
        'werent'   => "weren't",
        'hasnt'    => "hasn't",
        'havent'   => "haven't",
        'couldnt'  => "couldn't",
        'shouldnt' => "shouldn't",
        'wouldnt'  => "wouldn't",
        'thats'    => "that's",
        'whats'    => "what's",
        'lets'     => "let's",
        'theres'   => "there's",
        'youre'    => "you're",
        'youve'    => "you've",
        'theyre'   => "they're",
        'hes'      => "he's",
        'shes'     => "she's",
        'gonna'    => 'going to',
        'wanna'    => 'want to',
        'gotta'    => 'have to',
        'gimme'    => 'give me',
        'lemme'    => 'let me',
        'cuz'      => 'because',
        'coz'      => 'because',
        'bcoz'     => 'because',
        'bcz'      => 'because',
        'becoz'    => 'because',
        'b4'       => 'before',
        '2day'     => 'today',
        'tmrw'     => 'tomorrow',
        'tmr'      => 'tomorrow',
        'asap'     => 'as soon as possible',
        'info'     => 'information',
        'msg'      => 'message',
        'msgs'     => 'messages',
        'ther'     => 'there',
        'teh'      => 'the',
        'adn'      => 'and',
        'recieve'  => 'receive',
        'recieved' => 'received',
        'seperate' => 'separate',
        'definately' => 'definitely',
        'occured'  => 'occurred',
        'untill'   => 'until',
        'alot'     => 'a lot',
        'tommorow' => 'tomorrow',
        'tomorow'  => 'tomorrow',
        'greatful' => 'grateful',
        'wich'     => 'which',
        'becuase'  => 'because',
        'beacuse'  => 'because',
        'freind'   => 'friend',
        'goverment' => 'government',
        'personel' => 'personal',
    ];
}

/** Titles that should be capitalised when addressing someone. */
function grm_titles(): array
{
    return ['sir', 'madam', 'maam', 'mam', 'mr', 'mrs', 'ms', 'dr', 'prof'];
}

/** Apply the word map using strict word boundaries. */
function grm_informal(string $s): string
{
    foreach (grm_word_map() as $from => $to) {
        $s = preg_replace(
            '/(?<![\p{L}\p{N}\'])' . preg_quote($from, '/') . '(?![\p{L}\p{N}\'])/iu',
            str_replace('$', '\$', $to),
            $s
        ) ?? $s;
    }
    return $s;
}

/** Capitalise honorifics used as address terms ("hi sir" -> "Hi Sir"). */
function grm_titles_caps(string $s): string
{
    foreach (grm_titles() as $t) {
        $s = preg_replace_callback(
            '/(?<![\p{L}\p{N}])' . preg_quote($t, '/') . '(?![\p{L}\p{N}])/iu',
            fn($m) => mb_strtoupper(mb_substr($m[0], 0, 1)) . mb_substr($m[0], 1),
            $s
        ) ?? $s;
    }
    return $s;
}

/** Common subject-verb agreement fixes on simple present-tense sentences. */
function grm_subject_verb(string $s): string
{
    $rules = [
        '/\bi\s+is\b/iu'                          => 'I am',
        '/\bi\s+are\b/iu'                         => 'I am',
        '/\bi\s+has\b/iu'                         => 'I have',
        '/\bi\s+were\b/iu'                        => 'I was',
        '/\b(he|she|it)\s+are\b/iu'               => '$1 is',
        '/\b(he|she|it)\s+have\b/iu'              => '$1 has',
        '/\b(he|she|it)\s+go\b/iu'                => '$1 goes',
        '/\b(he|she|it)\s+do\s+not\b/iu'          => '$1 does not',
        '/\b(he|she|it)\s+don\'t\b/iu'            => "$1 doesn't",
        '/\b(we|they|you)\s+is\b/iu'              => '$1 are',
        '/\b(we|they|you)\s+was\b/iu'             => '$1 were',
        '/\b(we|they)\s+has\b/iu'                 => '$1 have',
        '/\bthere\s+is\s+(many|several|some\s+of\s+the)\b/iu' => 'there are $1',
        '/\bdoes\s+not\s+has\b/iu'                => 'does not have',
    ];
    foreach ($rules as $pattern => $replacement) {
        $s = preg_replace($pattern, $replacement, $s) ?? $s;
    }
    return $s;
}

/** Capitalise the first letter of each sentence (and of the whole text). */
function grm_caps(string $s): string
{
    $s = preg_replace_callback(
        '/(^|[.!?]\s+|\n\s*)(\p{Ll})/u',
        fn($m) => $m[1] . mb_strtoupper($m[2]),
        $s
    ) ?? $s;
    return $s;
}

/** Add a closing full stop when the text clearly ends mid-sentence. */
function grm_terminate(string $s): string
{
    $s = rtrim($s);
    if ($s === '') {
        return $s;
    }
    return preg_match('/[.!?:,;)"\']$/u', $s) === 1 ? $s : $s . '.';
}

/**
 * Full proofread pass. Returns [ ok, improved, changed, fixes[] ].
 * Each applied stage reports itself so the UI can list what was corrected.
 */
function grm_improve(string $text): array
{
    $original = $text;
    $fixes    = [];

    $s = grm_squash($text);
    if ($s !== trim($original)) {
        $fixes[] = 'Tidied spacing and punctuation.';
    }

    $before = $s;
    $s = grm_informal($s);
    if ($s !== $before) {
        $fixes[] = 'Expanded informal spellings and contractions.';
    }

    $before = $s;
    $s = grm_subject_verb($s);
    if ($s !== $before) {
        $fixes[] = 'Corrected subject-verb agreement.';
    }

    $before = $s;
    $s = grm_dedup($s);
    if ($s !== $before) {
        $fixes[] = 'Removed repeated words.';
    }

    $before = $s;
    $s = grm_titles_caps($s);
    $s = grm_caps($s);
    if ($s !== $before) {
        $fixes[] = 'Fixed capitalization.';
    }

    $before = $s;
    $s = grm_terminate(grm_squash($s));
    if ($s !== $before) {
        $fixes[] = 'Added the missing end punctuation.';
    }

    return [
        'ok'       => true,
        'improved' => $s,
        'changed'  => $s !== $original,
        'fixes'    => $fixes,
    ];
}

/** Tone presets used by the rewrite action. */
function grm_tones(): array
{
    return [
        'professional' => 'clear, professional business writing',
        'friendly'     => 'warm and friendly',
        'formal'       => 'formal and respectful',
        'concise'      => 'short and to the point',
        'simple'       => 'simple and easy to understand',
        'casual'       => 'casual and relaxed',
    ];
}

/**
 * Split a message into an optional greeting ("hi sir", "hello team") and the
 * body. Rewrites should restyle the request, not mangle the salutation.
 *
 * @return array{0:string,1:string} [greeting, body]
 */
function grm_split_greeting(string $s): array
{
    // The greeting is the opening word plus AT MOST one address term (a title,
    // "team"/"all", or a capitalised name). Matching more than that would
    // swallow the request itself — "hi sir i need leave" is a greeting of two
    // words followed by a request, not a five-word greeting.
    $pattern = '/^\s*((?:hi|hello|hey|dear|good\s+(?:morning|afternoon|evening))'
        . '(?:\s+(?:sir|madam|maam|mam|team|all|everyone|mr|mrs|ms|dr|prof)\.?|\s+\p{Lu}[\p{L}\']+)?)'
        . '\s*[,.!]?\s*(.*)$/isu';
    if (preg_match($pattern, $s, $m)) {
        $body = trim($m[2]);
        if ($body !== '') {
            return [trim($m[1]), $body];
        }
    }
    return ['', $s];
}

/**
 * Join an optional greeting to a body with the right capitalisation:
 * "Hi Sir, I would like…" — the greeting is capitalised, the body continues in
 * lower case unless it starts with "I" or a proper noun.
 */
function grm_join_greeting(string $greeting, string $body): string
{
    $body = trim($body);
    if ($greeting === '') {
        return grm_proper_nouns(grm_caps($body));
    }
    if ($body !== '' && !preg_match('/^(?:I\b|\p{Lu}\p{Lu})/u', $body)) {
        $body = mb_strtolower(mb_substr($body, 0, 1)) . mb_substr($body, 1);
    }
    $greeting = grm_caps(grm_titles_caps(mb_strtolower($greeting)));
    return grm_proper_nouns(grm_squash($greeting . ', ' . $body));
}

/** Capitalise weekday and month names wherever they appear. */
function grm_proper_nouns(string $s): string
{
    $names = [
        'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
        'january', 'february', 'march', 'april', 'may', 'june',
        'july', 'august', 'september', 'october', 'november', 'december',
    ];
    foreach ($names as $n) {
        $s = preg_replace_callback(
            '/(?<![\p{L}])' . preg_quote($n, '/') . '(?![\p{L}])/iu',
            fn($m) => mb_strtoupper(mb_substr($m[0], 0, 1)) . mb_substr($m[0], 1),
            $s
        ) ?? $s;
    }
    return $s;
}

/** Remove leading filler so tone templates attach to the real request. */
function grm_strip_filler(string $s): string
{
    $s = preg_replace('/^\s*(?:i\s+(?:just\s+)?(?:want(?:ed)?\s+to\s+say\s+that|mean|think\s+that|feel\s+that))\s+/iu', '', $s) ?? $s;
    $s = preg_replace('/^\s*(?:basically|actually|so|well|um)\s*,?\s*/iu', '', $s) ?? $s;
    return trim($s);
}

/**
 * Rewrite the text in the requested tone. Deterministic and offline: the body
 * is normalised, its request pattern is recognised where possible, and a
 * tone-appropriate frame is applied. When no pattern is recognised the text is
 * still corrected and restyled word-by-word rather than being invented.
 */
function grm_rewrite(string $text, string $tone): array
{
    $tone = array_key_exists($tone, grm_tones()) ? $tone : 'professional';

    // 1. Correct the text first — a rewrite of broken input is still broken.
    $clean = grm_improve($text)['improved'];
    [$greeting, $body] = grm_split_greeting($clean);
    $body = grm_strip_filler($body);
    $body = rtrim($body, " \t\n\r.,;");

    // 2. Recognise the most common request shape: "I need X because/for Y".
    //    This is what turns "i need leave tomorrow because personal work" into
    //    a properly framed sentence instead of a word-swapped one.
    $request = null;
    if (preg_match('/^i\s+(?:need|want|require|would\s+like)\s+(?:to\s+take\s+|a\s+|an\s+)?(.+?)(?:\s+(?:because|due\s+to|for|as)\s+(?:of\s+)?(.+))?$/iu', $body, $m)) {
        $request = ['what' => trim($m[1]), 'why' => isset($m[2]) ? trim($m[2]) : ''];
    }

    $frames = [
        'professional' => ['I would like to request %s', ' due to %s'],
        'formal'       => ['I am writing to formally request %s', ', owing to %s'],
        'friendly'     => ['I was hoping to request %s', ' because of %s'],
        'casual'       => ['I would like to take %s', ' because of %s'],
        'simple'       => ['I would like to ask for %s', ' because of %s'],
        'concise'      => ['Requesting %s', ' - %s'],
    ];

    if ($request !== null) {
        [$mainFrame, $reasonFrame] = $frames[$tone];
        $what = $request['what'];
        $why  = grm_reason_phrase($request['why'], $tone);
        $out  = sprintf($mainFrame, $what);
        if ($why !== '') {
            $out .= sprintf($reasonFrame, $why);
        }
        $rewritten = $out;
    } else {
        // 3. No recognised pattern: apply tone word swaps to the corrected text.
        $rewritten = grm_tone_words($body, $tone);
    }

    $rewritten = grm_terminate(grm_caps(grm_squash($rewritten)));
    if ($greeting !== '') {
        $rewritten = grm_caps(grm_titles_caps($greeting)) . ', ' . $rewritten;
    }
    $rewritten = grm_squash($rewritten);

    return [
        'ok'        => true,
        'rewritten' => $rewritten,
        'tone'      => $tone,
        'differs'   => mb_strtolower($rewritten) !== mb_strtolower(grm_terminate($clean)),
    ];
}

/** Turn a bare reason ("personal work") into a natural noun phrase per tone. */
function grm_reason_phrase(string $why, string $tone): string
{
    $why = trim(rtrim($why, " \t\n\r.,;"));
    if ($why === '') {
        return '';
    }
    $lower = mb_strtolower($why);
    $known = [
        'personal work'    => ['professional' => 'personal reasons', 'formal' => 'personal reasons', 'default' => 'some personal work'],
        'personal reason'  => ['default' => 'personal reasons'],
        'personal reasons' => ['default' => 'personal reasons'],
        'personal'         => ['default' => 'personal reasons'],
        'health issue'     => ['default' => 'health reasons'],
        'health problem'   => ['default' => 'health reasons'],
        'not well'         => ['default' => 'health reasons'],
        'sick'             => ['default' => 'health reasons'],
        'family function'  => ['default' => 'a family function'],
        'family work'      => ['default' => 'family commitments'],
        'family'           => ['default' => 'family commitments'],
        'marriage'         => ['default' => 'a family wedding'],
        'some work'        => ['default' => 'prior commitments'],
    ];
    if (isset($known[$lower])) {
        return $known[$lower][$tone] ?? $known[$lower]['default'];
    }
    return $why;
}

/** Tone-appropriate word swaps for text with no recognised request pattern. */
function grm_tone_words(string $s, string $tone): string
{
    $map = [
        'professional' => ['need' => 'require', 'want' => 'would like', 'get' => 'obtain', 'give' => 'provide', 'help' => 'assist', 'send' => 'forward', 'ask' => 'request', 'a lot of' => 'considerable'],
        'formal'       => ['need' => 'require', 'want' => 'wish', 'get' => 'receive', 'ask' => 'request', 'sorry' => 'I apologise', 'buy' => 'purchase', 'about' => 'regarding'],
        'friendly'     => ['regarding' => 'about', 'however' => 'that said', 'therefore' => 'so', 'request' => 'ask'],
        'casual'       => ['however' => 'but', 'therefore' => 'so', 'require' => 'need', 'request' => 'ask for', 'purchase' => 'buy'],
        'simple'       => ['obtain' => 'get', 'require' => 'need', 'assist' => 'help', 'request' => 'ask for', 'provide' => 'give', 'nevertheless' => 'still', 'utilise' => 'use', 'commence' => 'start'],
        'concise'      => ['in order to' => 'to', 'due to the fact that' => 'because', 'at this point in time' => 'now', 'a large number of' => 'many'],
    ];
    $swaps = $map[$tone] ?? [];
    uksort($swaps, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    foreach ($swaps as $from => $to) {
        $s = preg_replace('/(?<![\p{L}\p{N}])' . preg_quote($from, '/') . '(?![\p{L}\p{N}])/iu', $to, $s) ?? $s;
    }
    if ($tone === 'concise') {
        $s = preg_replace('/(?<![\p{L}])(just|really|basically|actually|very|quite|simply)(?![\p{L}])\s*/iu', '', $s) ?? $s;
    }
    return grm_squash($s);
}

/** Shorten to the core message: drop filler, hedges and redundant clauses. */
function grm_shorten(string $text): array
{
    $clean = grm_improve($text)['improved'];
    [$greeting, $body] = grm_split_greeting($clean);
    $body = grm_strip_filler($body);

    // Remove intensifiers, hedges and wordy connectives.
    $body = preg_replace('/(?<![\p{L}])(just|really|basically|actually|very|quite|simply|kind of|sort of|i think|i believe|in my opinion)(?![\p{L}])\s*/iu', '', $body) ?? $body;
    $wordy = [
        'in order to' => 'to',
        'due to the fact that' => 'because',
        'at this point in time' => 'now',
        'a large number of' => 'many',
        'in the event that' => 'if',
        'for the purpose of' => 'for',
        'with regard to' => 'about',
        'as soon as possible' => 'soon',
    ];
    foreach ($wordy as $from => $to) {
        $body = preg_replace('/(?<![\p{L}])' . preg_quote($from, '/') . '(?![\p{L}])/iu', $to, $body) ?? $body;
    }

    // Keep only the first sentence when there are several — that carries the ask.
    $sentences = preg_split('/(?<=[.!?])\s+/u', trim($body)) ?: [];
    if (count($sentences) > 1) {
        $body = $sentences[0];
    }

    $short = grm_terminate(grm_caps(grm_squash($body)));
    if ($greeting !== '') {
        $short = grm_caps(grm_titles_caps($greeting)) . ', ' . $short;
    }
    return ['ok' => true, 'shortened' => grm_squash($short)];
}

/** Expand a terse note into a fuller, more complete message. */
function grm_expand(string $text): array
{
    $clean = grm_improve($text)['improved'];
    [$greeting, $body] = grm_split_greeting($clean);
    $body = rtrim(trim($body), " \t\n\r.,;");

    if ($body !== '') {
        if (preg_match('/^please\s+/iu', $body) === 1) {
            $body = preg_replace('/^please\s+/iu', 'I would be grateful if you could ', $body) ?? $body;
        } elseif (preg_match('/^i\s+(?:need|want|require)\b/iu', $body) === 1) {
            $body = preg_replace('/^i\s+(?:need|want|require)\b/iu', 'I would like to request', $body) ?? $body;
        } elseif (!preg_match('/(?<![\p{L}])(i|we|you|he|she|it|they)(?![\p{L}])/iu', $body)) {
            $body = 'I am writing to let you know that ' . lcfirst($body);
        }
        $body .= '. Please let me know if you need any further details.';
    }

    $expanded = grm_terminate(grm_caps(grm_squash($body)));
    if ($greeting !== '') {
        $expanded = grm_caps(grm_titles_caps($greeting)) . ', ' . $expanded;
    }
    return ['ok' => true, 'expanded' => grm_squash($expanded)];
}

/**
 * Per-issue suggestions for the editor's side panel: what would change and why.
 * Returns a bounded list of { type, from, to, note }.
 */
function grm_suggestions(string $text): array
{
    $out  = [];
    $seen = [];

    foreach (grm_word_map() as $from => $to) {
        if (count($out) >= 25) {
            break;
        }
        if (preg_match('/(?<![\p{L}\p{N}\'])(' . preg_quote($from, '/') . ')(?![\p{L}\p{N}\'])/iu', $text, $m)) {
            $key = mb_strtolower($m[1]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'type' => 'spelling',
                'from' => $m[1],
                'to'   => $to,
                'note' => 'Use the full written form.',
            ];
        }
    }

    if (preg_match('/\b(i\s+(?:is|are|has|were)|(?:he|she|it)\s+(?:are|have|go)|(?:we|they|you)\s+(?:is|was))\b/iu', $text, $m)) {
        $fixed = trim(grm_subject_verb($m[1]));
        $out[] = ['type' => 'grammar', 'from' => $m[1], 'to' => $fixed, 'note' => 'Subject and verb should agree.'];
    }
    if (preg_match('/\b(\w+)\s+\1\b/iu', $text, $m)) {
        $out[] = ['type' => 'grammar', 'from' => $m[0], 'to' => $m[1], 'note' => 'This word is repeated.'];
    }
    if (preg_match('/^\s*(\p{Ll})/u', $text, $m)) {
        $out[] = ['type' => 'style', 'from' => $m[1], 'to' => mb_strtoupper($m[1]), 'note' => 'Start the sentence with a capital letter.'];
    }
    if (trim($text) !== '' && preg_match('/[.!?]\s*$/u', trim($text)) !== 1) {
        $out[] = ['type' => 'style', 'from' => '', 'to' => '.', 'note' => 'Add end punctuation.'];
    }
    foreach (grm_titles() as $t) {
        if (preg_match('/(?<![\p{L}])(' . preg_quote($t, '/') . ')(?![\p{L}])/u', $text, $m) && $m[1] === mb_strtolower($m[1])) {
            $out[] = ['type' => 'style', 'from' => $m[1], 'to' => ucfirst($m[1]), 'note' => 'Capitalise a title used to address someone.'];
            break;
        }
    }

    return array_slice($out, 0, 25);
}

/** Word / character / sentence statistics for the editor header. */
function grm_stats(string $text): array
{
    $trimmed   = trim($text);
    $words     = $trimmed === '' ? 0 : count(array_filter(preg_split('/\s+/u', $trimmed) ?: []));
    $chars     = mb_strlen($text);
    $sentences = $trimmed === '' ? 0 : max(1, count(array_filter(array_map('trim', preg_split('/[.!?]+/u', $trimmed) ?: []), fn($s) => $s !== '')));
    return [
        'words'     => $words,
        'chars'     => $chars,
        'sentences' => $sentences,
        'read'      => (int)ceil($words / 200),   // minutes, at ~200 wpm
        'issues'    => count(grm_suggestions($text)),
    ];
}
