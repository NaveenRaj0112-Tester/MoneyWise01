<?php
$php = 'C:\\Users\\Magdyn Pc\\AppData\\Local\\Microsoft\\WinGet\\Packages\\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\\php.exe';

// Build each target phrase from \u{} escapes (pure ASCII source, PHP resolves at runtime)
$phrases = [
    'yestIncome'     => "\u{0CA8}\u{0CBF}\u{0CA8}\u{0CCD}\u{0CA8}\u{0CC6} \u{0CA8}\u{0CBF}\u{0CAE}\u{0C97}\u{0CC6} %s \u{0CB8}\u{0C95}\u{0CCD}\u{0C95}\u{0CBF}\u{0CA4}\u{0CC1}.",
    'lastMonthIncome'=> "\u{0C95}\u{0CB3}\u{0CC6}\u{0CA6} \u{0CA4}\u{0CBF}\u{0C82}\u{0C97}\u{0CB3}\u{0CC1} \u{0CA8}\u{0CBF}\u{0CAE}\u{0C97}\u{0CC6} %s \u{0CB8}\u{0C95}\u{0CCD}\u{0C95}\u{0CBF}\u{0CA4}\u{0CC1}.",
    'lastMonthNone'  => "\u{0C95}\u{0CB3}\u{0CC6}\u{0CA6} \u{0CA4}\u{0CBF}\u{0C82}\u{0C97}\u{0CB3}\u{0CBF}\u{0C97}\u{0CC6} \u{0CAF}\u{0CBE}\u{0CB5}\u{0CC1}\u{0CA6}\u{0CC7} \u{0CB5}\u{0CB9}\u{0CBF}\u{0CB5}\u{0CBE}\u{0C9F}\u{0CC1} \u{0C95}\u{0C82}\u{0CA1}\u{0CCD}\u{0CAC}\u{0C82}\u{0CA6}\u{0CBF}\u{0CB2}\u{0CCD}\u{0CB2}.",
    'weekIncome'     => "\u{0C88} \u{0CB5}\u{0CBE}\u{0CB0} \u{0CA8}\u{0CBF}\u{0CAE}\u{0C97}\u{0CC6} %s \u{0CB8}\u{0C95}\u{0CCD}\u{0C95}\u{0CBF}\u{0CA4}\u{0CC1}.",
    'weekNone'       => "\u{0C88} \u{0CB5}\u{0CBE}\u{0CB0}\u{0CCD}\u{0C95}\u{0CC6} \u{0CAF}\u{0CBE}\u{0CB5}\u{0CC1}\u{0CA6}\u{0CC7} \u{0CB5}\u{0CB9}\u{0CBF}\u{0CB5}\u{0CBE}\u{0C9F}\u{0CC1} \u{0C95}\u{0C82}\u{0CA1}\u{0CCD}\u{0CAC}\u{0C82}\u{0CA6}\u{0CBF}\u{0CB2}\u{0CCD}\u{0CB2}.",
    'yearspeak'      => "%d \u{0CB0}\u{0CB2}\u{0CCD}\u{0CB2}\u{0CBF} \u{0CA8}\u{0CC0}\u{0CB5}\u{0CC1} %s \u{0C96}\u{0CB0}\u{0CCD}\u{0C9A}\u{0CC1} \u{0CAE}\u{0CBE} \u{0CA1}\u{0CBF}\u{0CA6}\u{0CCD}\u{0CA6}\u{0CC0}\u{0CB0}\u{0CC1}, %s \u{0CB8}\u{0CBF}\u{0C95}\u{0CCD}\u{0C95}\u{0CBF}\u{0CA4}\u{0CC1}, %d \u{0CB5}\u{0CB9}\u{0CBF}\u{0CB5}\u{0CBE}\u{0C9F}\u{0CC1}\u{0C97}\u{0CB3}\u{0CB2}\u{0C82}\u{0CB2}\u{0CBF}.",
    'lastYearSpent'  => "\u{0C95}\u{0CB3}\u{0CC6}\u{0CA6} \u{0CB5}\u{0CB0}\u{0CCD}\u{0CB7} \u{0CA8}\u{0CC0}\u{0CB5}\u{0CC1} %s \u{0C96}\u{0CB0}\u{0CCD}\u{0C9A}\u{0CC1} \u{0CAE}\u{0CBE} \u{0CA1}\u{0CBF}\u{0CA6}\u{0CCD}\u{0CA6}\u{0CC0}\u{0CB0}\u{0CC1}.",
    'monthTop'       => "\u{0CA8}\u{0CBF}\u{0CAE}\u{0CCD}\u{0CAE} \u{0CAA}\u{0CCD}\u{0CB0}\u{0CAE}\u{0CC1}\u{0C96} \u{0C96}\u{0CB0}\u{0CCD}\u{0C9A}\u{0CC1} \u{0CB5}\u{0CB0}\u{0CCD}\u{0C97}\u{0C97}\u{0CB3}\u{0CC1}:",
];

echo "=== PHRASE VERIFICATION ===\n\n";
foreach ($phrases as $key => $str) {
    $hex = unpack('H*', $str)[1];
    echo "KEY: $key\n";
    echo "  Text: $str\n";
    echo "  Hex:  $hex\n";
    echo "  hex2bin line: '$key' => hex2bin('$hex'),\n";

    // Verify expected substrings
    $checks = [];
    if (strpos($str, 'ನಿಮಗೆ') !== false) $checks[] = 'ನಿಮಗೆ';
    if (strpos($str, 'ಸಿಕ್ಕಿತು') !== false) $checks[] = 'ಸಿಕ್ಕಿತು';
    if (strpos($str, 'ವಹಿವಾಟು') !== false) $checks[] = 'ವಹಿವಾಟು';
    if (strpos($str, 'ಕಂಡುಬಂದಿಲ್ಲ') !== false) $checks[] = 'ಕಂಡುಬಂದಿಲ್ಲ';
    if (strpos($str, 'ಕಳೆದ') !== false) $checks[] = 'ಕಳೆದ';
    if (strpos($str, 'ತಿಂಗಳು') !== false) $checks[] = 'ತಿಂಗಳು';
    if (strpos($str, 'ಖರ್ಚು') !== false) $checks[] = 'ಖರ್ಚು';
    if (strpos($str, 'ವರ್ಗಗಳು') !== false) $checks[] = 'ವರ್ಗಗಳು';
    if (strpos($str, '%s') !== false) $checks[] = '%s';
    if (strpos($str, '%d') !== false) $checks[] = '%d';
    if (strpos($str, 'ನೀವು') !== false) $checks[] = 'ನೀವು';
    if (strpos($str, 'ವಾರ') !== false) $checks[] = 'ವಾರ';
    if (strpos($str, 'ವರ್ಷ') !== false) $checks[] = 'ವರ್ಷ';
    echo "  Found: " . implode(', ', $checks) . "\n\n";
}

echo "\n=== HEX2BIN LINES TO PASTE INTO ai.php ===\n\n";
foreach ($phrases as $key => $str) {
    $hex = unpack('H*', $str)[1];
    echo "        '$key' => hex2bin('$hex'),\n";
}
