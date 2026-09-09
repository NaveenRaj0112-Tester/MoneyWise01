<?php
/**
 * MoneyWise AI — multi-language support (English / Tamil / Malayalam / Hindi /
 * Kannada) for the deterministic assistant engine.
 *
 * The engine ALWAYS computes the real, data-driven value first (in the shared
 * numeric/computation layer). This file only supplies:
 *   1. Detection — which language the user is writing in (keywords + script).
 *   2. Templates — how a precomputed result is phrased in each locale.
 *
 * Amounts (₹1,234.56), dates and category names are language-neutral.
 */
declare(strict_types=1);

if (!defined('AI_LOCALES')) {
    define('AI_LOCALES', ['en', 'ta', 'ml', 'hi', 'kn']);
}

if (!function_exists('ai_detect_lang')) {

    /**
     * Detect the language of a question. `pref` is the client's chosen language
     * selector value (if the user explicitly picked one, honour it unless the
     * text is clearly typed in a different script). Uses script detection first
     * (most reliable), then keyword sniffing.
     */
    function ai_detect_lang(string $text, string $pref = ''): string
    {
        $pref = strtolower(trim($pref));
        if ($pref !== '' && in_array($pref, AI_LOCALES, true)) {
            // Explicit selector wins unless the input is plainly in another script.
            if (ai_script_is($text)) {
                return ai_script_is($text);
            }
            return $pref;
        }
        return ai_script_is($text) ?: 'en';
    }

    /** Return the locale a script-biased bit of text is written in, or ''. */
    function ai_script_is(string $text): string
    {
        if (preg_match('/[\x{0B80}-\x{0BFF}]/u', $text)) {
            return 'ta';        // Tamil
        }
        if (preg_match('/[\x{0D00}-\x{0D7F}]/u', $text)) {
            return 'ml';        // Malayalam
        }
        if (preg_match('/[\x{0910}-\x{0949}\x{0900}-\x{097F}]/u', $text)) {
            return 'hi';        // Devanagari (Hindi)
        }
        if (preg_match('/[\x{0C80}-\x{0CFF}]/u', $text)) {
            return 'kn';        // Kannada
        }
        return '';
    }

    /** Returns the stored title/display label for a locale. */
    function ai_lang_label(string $lang): string
    {
        return [
            'en' => 'English',
            'ta' => 'Tamil',
            'ml' => 'Malayalam',
            'hi' => 'Hindi',
            'kn' => 'Kannada',
        ][$lang] ?? 'English';
    }
}