<?php
defined('ABSPATH') || exit;

/**
 * Infers a SQL column type for every meta key and lookup-table column an archive query orders
 * by or filters on.
 *
 * WHY THIS EXISTS: an index cannot serve an ORDER BY on a CAST. Ordering
 * CAST(meta_value AS DECIMAL) filesorts however good the index is, which is the entire reason
 * Super Speedy Archives materialises a meta key as a real typed column. Choosing the type is
 * therefore the decision that makes or breaks the optimisation, and choosing it WRONG is worse
 * than not configuring the key at all: `_price` declared varchar sorts "100" before "99" and
 * every price-ordered archive is quietly incorrect.
 *
 * The stored type is not recoverable from the SQL. A CAST in the query says what the query
 * ASKED for, not what the rows actually hold - so this samples the rows.
 *
 * WHERE IT RUNS: analysis time only, after the crawl. Never inside a profiled request, or the
 * sampling cost lands in the very measurement it is attached to.
 *
 * PRIVACY: meta KEYS, types and counts leave this class. Meta VALUES never do, not even a
 * sample - they hold licence keys, customer addresses and order data.
 */
class SSPA_Archive_Types {

    /** Values sampled per key. Enough to be confident, small enough to stay cheap. */
    const SAMPLE_SIZE = 500;

    /** Sampling chunks, spread across the id range so a key added late is not missed. */
    const CHUNKS = 5;

    /** Below this, the sample is too thin to call anything but varchar. */
    const MIN_CONFIDENT = 20;

    /** Whole-pass ceiling in seconds. A pathological postmeta table must not stall analysis. */
    const TIME_BUDGET = 5.0;

    /**
     * @param int   $run_id
     * @param array $needed {postmeta: [key => roles[]], other_table: [table => columns[]]}
     * @return array keyed "postmeta:<key>" and "<table>.<column>"
     */
    public static function for_run($run_id, $needed) {
        $key = 'sspa_archive_types_' . (int) $run_id;
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }
        $types = self::infer($needed);
        set_transient($key, $types, 12 * HOUR_IN_SECONDS);
        return $types;
    }

    public static function infer($needed) {
        global $wpdb;

        $out = array();
        $started = microtime(true);

        $meta_keys = (isset($needed['postmeta']) && is_array($needed['postmeta'])) ? $needed['postmeta'] : array();
        foreach ($meta_keys as $meta_key => $roles) {
            if ((microtime(true) - $started) > self::TIME_BUDGET) {
                $out['postmeta:' . $meta_key] = array(
                    'sql_type' => null,
                    'confidence' => 'skipped',
                    'reason' => 'time_budget',
                    'used_for' => array_values((array) $roles),
                );
                continue;
            }
            $verdict = self::sample_meta_key((string) $meta_key);
            $verdict['used_for'] = array_values((array) $roles);
            $out['postmeta:' . $meta_key] = $verdict;
        }

        $tables = (isset($needed['other_table']) && is_array($needed['other_table'])) ? $needed['other_table'] : array();
        foreach ($tables as $table => $columns) {
            $schema = self::columns_from_schema((string) $table);
            foreach ((array) $columns as $column => $roles) {
                // A real table has a declared type. There is nothing to infer and nothing to
                // be uncertain about, so this is exact where the meta sampling is a sample.
                $out[$table . '.' . $column] = array(
                    'sql_type' => isset($schema[$column]) ? $schema[$column] : null,
                    'confidence' => isset($schema[$column]) ? 'schema' : 'unknown',
                    'used_for' => array_values((array) $roles),
                );
            }
        }

        return $out;
    }

    /**
     * Sample the stored values for one meta key and name a type from Super Speedy Archives'
     * own vocabulary: varchar, bigint, decimal, float, int, datetime, date, bit.
     */
    private static function sample_meta_key($meta_key) {
        global $wpdb;

        $bounds = $wpdb->get_row($wpdb->prepare(
            "SELECT MIN(meta_id) lo, MAX(meta_id) hi FROM {$wpdb->postmeta} WHERE meta_key = %s",
            $meta_key
        ), ARRAY_A);

        if (!$bounds || null === $bounds['lo']) {
            return array('sql_type' => null, 'confidence' => 'absent', 'sampled' => 0, 'nulls' => 0);
        }

        $lo = (int) $bounds['lo'];
        $hi = (int) $bounds['hi'];
        $per_chunk = (int) ceil(self::SAMPLE_SIZE / self::CHUNKS);
        $span = max(1, (int) floor(($hi - $lo) / self::CHUNKS));

        // Spread the sample across the id range rather than taking the first N rows: the first
        // N are the oldest, and a key whose format changed - a price column that used to hold
        // "£9.99" and now holds "9.99" - would be typed off the half that no longer matters.
        $values = array();
        $empties = 0;
        for ($i = 0; $i < self::CHUNKS; $i++) {
            $from = $lo + ($i * $span);
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_id >= %d LIMIT %d",
                $meta_key,
                $from,
                $per_chunk
            ));
            foreach ((array) $rows as $value) {
                if (null === $value || '' === $value) {
                    $empties++;
                    continue;
                }
                $values[] = $value;
            }
            if (count($values) >= self::SAMPLE_SIZE) {
                break;
            }
        }

        $verdict = self::classify($values);
        $verdict['sampled'] = count($values);
        $verdict['nulls'] = $empties;
        return $verdict;
    }

    /**
     * Deliberately conservative: a type is only claimed when EVERY sampled value fits it.
     * One value that does not fit demotes the whole key, because a single unparseable row is
     * enough to make a typed column wrong.
     */
    private static function classify($values) {
        if (count($values) < self::MIN_CONFIDENT) {
            return array(
                'sql_type' => 'varchar',
                'confidence' => count($values) ? 'low' : 'absent',
            );
        }

        $all_bit = true;
        $all_int = true;
        $all_decimal = true;
        $any_numeric = false;
        $all_datetime = true;
        $all_date = true;
        $max_abs = 0;
        $max_scale = 0;

        foreach ($values as $value) {
            $value = trim((string) $value);

            if ($value !== '0' && $value !== '1') {
                $all_bit = false;
            }

            if (preg_match('/^-?\d+$/', $value)) {
                $any_numeric = true;
                $max_abs = max($max_abs, abs((float) $value));
            } else {
                $all_int = false;
            }

            if (preg_match('/^-?\d*\.?\d+$/', $value)) {
                $any_numeric = true;
                $dot = strpos($value, '.');
                if (false !== $dot) {
                    $max_scale = max($max_scale, strlen($value) - $dot - 1);
                }
            } else {
                $all_decimal = false;
            }

            if (!self::parses_as($value, 'Y-m-d H:i:s')) {
                $all_datetime = false;
            }
            if (!self::parses_as($value, 'Y-m-d')) {
                $all_date = false;
            }
        }

        // bit before int: 0/1 also satisfies int, and a flag stored as a real bit column is
        // both smaller and unambiguous.
        if ($all_bit) {
            return array('sql_type' => 'bit', 'confidence' => 'sampled');
        }
        if ($all_int) {
            return array(
                'sql_type' => ($max_abs > 2147483647) ? 'bigint' : 'int',
                'confidence' => 'sampled',
            );
        }
        if ($all_decimal) {
            return array(
                'sql_type' => ($max_scale <= 2) ? 'decimal' : 'float',
                'confidence' => 'sampled',
            );
        }
        if ($all_datetime) {
            return array('sql_type' => 'datetime', 'confidence' => 'sampled');
        }
        if ($all_date) {
            return array('sql_type' => 'date', 'confidence' => 'sampled');
        }

        // Numbers in some rows and not others. Reported as mixed and NEVER rounded to the
        // majority: a consumer must refuse to auto-apply this, because either choice is wrong
        // for part of the data. It is a data-quality finding in its own right.
        return array(
            'sql_type' => 'varchar',
            'confidence' => $any_numeric ? 'mixed' : 'sampled',
        );
    }

    private static function parses_as($value, $format) {
        $parsed = DateTime::createFromFormat('!' . $format, $value);
        return ($parsed instanceof DateTime) && $parsed->format($format) === $value;
    }

    /** Declared column types for a real table, straight from INFORMATION_SCHEMA. */
    private static function columns_from_schema($table) {
        global $wpdb;

        $full = $wpdb->prefix . $table;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $full
        ), ARRAY_A);

        $out = array();
        foreach ((array) $rows as $row) {
            $out[$row['COLUMN_NAME']] = strtolower($row['DATA_TYPE']);
        }
        return $out;
    }
}
