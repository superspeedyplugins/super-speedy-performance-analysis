<?php
// Standalone - used by the profiler at capture time, by the analysis engine for dupe
// detection, and by the anonymiser for submissions. No WordPress dependencies.

if (!function_exists('sspa_sql_fingerprint')) {
    /**
     * Normalise SQL to a fingerprint: literals become ?, IN-lists collapse to IN(?...),
     * whitespace collapses. Preserves the design of the query (joins, ORDER BY rand(),
     * SQL_CALC_FOUND_ROWS, unindexed LIKE shapes) with zero data values in it.
     */
    function sspa_sql_fingerprint($sql) {
        $fp = trim($sql);
        // String literals (handles escaped quotes inside).
        $fp = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/s", '?', $fp);
        $fp = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/s', '?', $fp);
        // Numeric literals not part of identifiers.
        $fp = preg_replace('/\b\d+(\.\d+)?\b/', '?', $fp);
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
