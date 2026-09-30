<?php
defined('ABSPATH') || exit;
// Standalone - used by the profiler at capture time, by the analysis engine for dupe
// detection, and by the anonymiser for submissions. No WordPress dependencies.

if (!function_exists('sspa_sql_fingerprint')) {
    /**
     * Normalise SQL to a fingerprint: literals become ?, IN-lists collapse to IN(?...),
     * whitespace collapses. Preserves the design of the query (joins, ORDER BY rand(),
     * SQL_CALC_FOUND_ROWS, unindexed LIKE shapes) with zero data values in it.
     */
    function sspa_sql_fingerprint($sql) {
        // Match tokens together so comment markers inside quotes cannot eat SQL, and
        // quotes inside comments cannot hide their contents. Failed SQL may end inside
        // a string/comment, so consume that token through EOF too. Backticks are identifiers.
        $tokens = <<<'REGEX'
~'(?:''|\\.|[^'\\])*(?:'|\\?\z)|"(?:""|\\.|[^"\\])*(?:"|\\?\z)|`(?:``|[^`])*`|/\*.*?(?:\*/|\z)|--(?=[\x00-\x20])[^\r\n]*|\#[^\r\n]*|\b(?:0x[0-9a-f]+|0b[01]+|\d+(?:\.\d+)?(?:e[+-]?\d+)?)\b~is
REGEX;
        $fp = preg_replace_callback($tokens, static function ($match) {
            $token = $match[0];
            if ('`' === $token[0]) {
                return $token;
            }
            if ('/*' === substr($token, 0, 2) || '--' === substr($token, 0, 2) || '#' === $token[0]) {
                return ' ';
            }
            return '?';
        }, trim($sql));
        // Collapse IN (?, ?, ?, ...) lists of any length.
        $fp = preg_replace('/\bIN\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/i', 'IN(?...)', $fp);
        // Collapse VALUES (?,?),(?,?) multi-row inserts.
        $fp = preg_replace('/\bVALUES\s*(\(\s*\?(?:\s*,\s*\?)*\s*\)\s*,?\s*)+/i', 'VALUES (?...) ', $fp);
        $fp = preg_replace('/\s+/', ' ', $fp);
        return trim($fp);
    }
}

if (!function_exists('sspa_fingerprint_hash')) {
    function sspa_fingerprint_hash($sql) {
        return md5(sspa_sql_fingerprint($sql));
    }
}
