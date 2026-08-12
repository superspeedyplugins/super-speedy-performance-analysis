<?php
defined('ABSPATH') || exit;

/**
 * Site characteristics: what KIND of site this is, and how big, expressed only in terms a
 * cohort can be built from.
 *
 * Two vocabularies, deliberately kept apart:
 *
 *  - "site characteristics" is the user-facing idea - what the owner sees described on the
 *    Overview tab and in the Share disclosure;
 *  - "site cohort dimensions" are the fields superspeedy.org groups by.
 *
 * Neither is "demographics". These are characteristics of a website, not of people, and the
 * old word invites exactly the wrong assumption about what is being collected.
 * `SSPA_Demographics` keeps its name because it is the local metrics collector and its rows
 * are private to the site; nothing in the public contract uses the term.
 *
 * The classifier is DETERMINISTIC: same counts and same plugin list give the same primary
 * label, every time, with no randomness and no "closest guess" tie-breaking. The receiver can
 * reclassify later from the coarse signals, which is why the signals travel with the label.
 */
class SSPA_Site_Characteristics {

    /**
     * The size ladder is a contract, not a formatting choice: a bucket string means the same
     * thing in every payload ever sent, so the receiver can compare a submission from today
     * with one from a year ago. Bump this if the ladder itself ever changes, and never
     * silently re-scale an existing band.
     *
     * The ladder is decimal (10, 100, 1k ... 1b) and shared by counts AND byte sizes, so
     * `database_bytes` of '<10b' means under ten billion bytes.
     */
    const BANDING_VERSION = 1;

    /** A content type needs this many items before it says anything about the site's purpose. */
    const CONTENT_SIGNAL_MIN = 10;

    /** ...and this many before it is a strong signal. */
    const CONTENT_SIGNAL_STRONG = 100;

    /**
     * Purpose from content shape and installed stack.
     *
     * Scoring, in full, because "deterministic" is worth nothing if the rule is not written
     * down: a purpose scores 3 for a content type at or above the strong threshold, 2 at or
     * above the signal threshold, 1 for a handful of items, and 2 for each plugin in the
     * taxonomy that maps to it. Highest total wins; ties break on the taxonomy's own label
     * order, which is fixed in the rules file. Everything else that scored becomes a
     * secondary purpose, so a WooCommerce shop with a real blog is not forced to choose.
     *
     * @param array $post_counts    public post type => published count
     * @param array $active_plugins active plugin slugs
     * @return array {primary_purpose, secondary_purposes[], confidence, taxonomy_version, signals[]}
     */
    public static function classify($post_counts, $active_plugins) {
        $taxonomy = SSPA_Rules::purpose_taxonomy();
        $order = array();
        foreach ((array) $taxonomy['labels'] as $index => $label) {
            $order[$label] = $index;
        }

        $scores = array();
        $signals = array();
        $content_strength = array(); // purpose => best content count seen
        $has_stack = array();

        foreach ((array) $post_counts as $type => $count) {
            $count = (int) $count;
            if ($count < 1 || !isset($taxonomy['content_types'][$type])) {
                continue;
            }
            $purpose = $taxonomy['content_types'][$type];
            if ($count >= self::CONTENT_SIGNAL_STRONG) {
                $weight = 3;
            } elseif ($count >= self::CONTENT_SIGNAL_MIN) {
                $weight = 2;
            } else {
                $weight = 1;
            }
            $scores[$purpose] = (isset($scores[$purpose]) ? $scores[$purpose] : 0) + $weight;
            $content_strength[$purpose] = max(isset($content_strength[$purpose]) ? $content_strength[$purpose] : 0, $count);
            // The signal names the canonical content CLASS, never the site's own post type
            // slug - a bespoke CPT slug can carry a client's name.
            $class = self::content_class($type);
            if ('other' !== $class) {
                $signals[] = $class . '-content';
            }
        }

        foreach ((array) $active_plugins as $slug) {
            if (!isset($taxonomy['plugin_slugs'][$slug])) {
                continue;
            }
            $purpose = $taxonomy['plugin_slugs'][$slug];
            $scores[$purpose] = (isset($scores[$purpose]) ? $scores[$purpose] : 0) + 2;
            $has_stack[$purpose] = true;
            // Safe by construction: only slugs that appear in the taxonomy, which is a closed
            // published list, and the active plugin list is already in every payload.
            $signals[] = $slug . '-stack';
        }

        if (!$scores) {
            return array(
                'primary_purpose' => 'general',
                'secondary_purposes' => array(),
                'confidence' => 'low',
                'taxonomy_version' => (int) $taxonomy['version'],
                'signals' => array(),
            );
        }

        $ranked = array_keys($scores);
        usort($ranked, function ($a, $b) use ($scores, $order) {
            if ($scores[$a] !== $scores[$b]) {
                return $scores[$b] <=> $scores[$a];
            }
            $order_a = isset($order[$a]) ? $order[$a] : PHP_INT_MAX;
            $order_b = isset($order[$b]) ? $order[$b] : PHP_INT_MAX;
            return ($order_a !== $order_b) ? ($order_a <=> $order_b) : strcmp($a, $b);
        });

        $primary = array_shift($ranked);
        $content = isset($content_strength[$primary]) ? $content_strength[$primary] : 0;
        $stack = !empty($has_stack[$primary]);
        if ($stack && $content >= self::CONTENT_SIGNAL_MIN) {
            $confidence = 'high';
        } elseif ($stack || $content >= self::CONTENT_SIGNAL_MIN) {
            $confidence = 'medium';
        } else {
            $confidence = 'low';
        }

        sort($signals, SORT_STRING);
        return array(
            'primary_purpose' => $primary,
            // Capped: a site with a plugin from every family is a cohort of one, and the
            // long tail says nothing a receiver can group by.
            'secondary_purposes' => array_slice(array_values($ranked), 0, 3),
            'confidence' => $confidence,
            'taxonomy_version' => (int) $taxonomy['version'],
            'signals' => array_values(array_unique($signals)),
        );
    }

    /**
     * The canonical class of a content type. An unmapped type - which on a real site means a
     * bespoke CPT, often named after the client or their product - is always 'other'. The raw
     * slug never leaves the site.
     */
    public static function content_class($post_type) {
        $taxonomy = SSPA_Rules::purpose_taxonomy();
        $type = (string) $post_type;
        return isset($taxonomy['content_classes'][$type]) ? $taxonomy['content_classes'][$type] : 'other';
    }

    /**
     * What this site is mostly made of: the largest public content type that is not the
     * WordPress furniture (pages, media), falling back to posts.
     *
     * @return array {class, count} - count is the raw number, for the caller to band
     */
    public static function primary_content($post_counts) {
        $counts = (array) $post_counts;
        unset($counts['page'], $counts['attachment']);
        arsort($counts, SORT_NUMERIC);
        foreach ($counts as $type => $count) {
            if ((int) $count >= self::CONTENT_SIGNAL_MIN) {
                return array('class' => self::content_class($type), 'count' => (int) $count);
            }
        }
        // Nothing has meaningful volume. Say so honestly rather than promoting a stray CPT.
        return array(
            'class' => !empty($post_counts['post']) ? self::content_class('post') : null,
            'count' => !empty($post_counts['post']) ? (int) $post_counts['post'] : null,
        );
    }

    /**
     * Band a count for sharing. Null in, null out - an unknown size must never band to '<10',
     * which reads as "a tiny site" and is a different claim entirely.
     */
    public static function band($value) {
        return (null === $value) ? null : SSPA_Anonymiser::bucket($value);
    }

    /**
     * The full cohort projection of one stored metrics row: sizes, classification, primary
     * content and environment, all banded or categorical.
     *
     * @param array $metrics the raw local metrics blob (exact numbers, private to the site)
     * @return array
     */
    public static function cohort_dimensions($metrics) {
        $metrics = (array) $metrics;
        $post_counts = isset($metrics['post_counts']) ? (array) $metrics['post_counts'] : array();
        $active_plugins = isset($metrics['active_plugins']) ? (array) $metrics['active_plugins'] : array();

        // Classified at EXPORT time from the stored counts, not at measurement time: the
        // taxonomy improves, the site's counts do not change, and a payload should carry the
        // best mapping available when it is built. `taxonomy_version` says which one that was.
        $classification = self::classify($post_counts, $active_plugins);
        $primary_content = self::primary_content($post_counts);

        $sizes = array(
            'posts' => self::band(isset($post_counts['post']) ? (int) $post_counts['post'] : 0),
            'pages' => self::band(isset($post_counts['page']) ? (int) $post_counts['page'] : 0),
            'primary_content_items' => self::band($primary_content['count']),
            'products' => self::band(isset($post_counts['product']) ? (int) $post_counts['product'] : 0),
            'orders_total' => self::band(isset($metrics['orders_total']) ? $metrics['orders_total'] : null),
            'orders_30d' => self::band(isset($metrics['orders_30d']) ? $metrics['orders_30d'] : null),
            'users' => self::band(isset($metrics['users']) ? (int) $metrics['users'] : 0),
            'comments' => self::band(isset($metrics['comments']) ? (int) $metrics['comments'] : 0),
            'postmeta' => self::band(isset($metrics['postmeta_rows']) ? $metrics['postmeta_rows'] : null),
            'database_bytes' => self::band(isset($metrics['db_bytes']) ? (int) $metrics['db_bytes'] : 0),
            'active_plugins' => self::band(count($active_plugins)),
            'banding_version' => self::BANDING_VERSION,
        );

        return array(
            'classification' => $classification,
            'sizes' => $sizes,
            'primary_content' => array(
                'class' => $primary_content['class'],
                'count' => self::band($primary_content['count']),
            ),
            'environment' => self::environment($metrics),
        );
    }

    /**
     * Categorical environment facts. Every value is either from a closed list or a version
     * string: no hostnames, no paths, no hosting company, nothing that narrows the site down
     * to one installation.
     */
    private static function environment($metrics) {
        $locale = isset($metrics['locale']) ? strtolower((string) $metrics['locale']) : '';
        $locale_family = preg_match('/^([a-z]{2})(?:[_-]|$)/', $locale, $m) ? $m[1] : null;

        return array(
            'wordpress_version' => self::safe_version(isset($metrics['wp']) ? $metrics['wp'] : null),
            'php_version' => self::safe_version(isset($metrics['php']) ? $metrics['php'] : null),
            'database_family' => self::allowed(isset($metrics['db_family']) ? $metrics['db_family'] : null, array('mysql', 'mariadb')),
            'database_version' => self::safe_version(isset($metrics['mysql']) ? $metrics['mysql'] : null),
            'object_cache' => isset($metrics['object_cache']) ? (bool) $metrics['object_cache'] : null,
            'object_cache_category' => self::allowed(
                isset($metrics['object_cache_category']) ? $metrics['object_cache_category'] : null,
                array('redis', 'memcached', 'apcu', 'sqlite', 'other')
            ),
            'page_cache' => self::allowed(
                isset($metrics['page_cache']) ? $metrics['page_cache'] : null,
                array('redis', 'memcached', 'other')
            ),
            'multisite' => isset($metrics['multisite']) ? (bool) $metrics['multisite'] : null,
            'locale_family' => $locale_family,
            'woocommerce_hpos' => isset($metrics['hpos']) ? (null === $metrics['hpos'] ? null : (bool) $metrics['hpos']) : null,
            'checkout_type' => self::allowed(
                isset($metrics['checkout_type']) ? $metrics['checkout_type'] : null,
                array('block', 'classic', 'unknown')
            ),
            'orders_total_basis' => self::allowed(
                isset($metrics['orders_total_basis']) ? $metrics['orders_total_basis'] : null,
                array('table_estimate', 'maintained_count', 'skipped_large_table')
            ),
            'orders_30d_basis' => self::allowed(
                isset($metrics['orders_30d_basis']) ? $metrics['orders_30d_basis'] : null,
                array('query_count')
            ),
        );
    }

    private static function allowed($value, $list) {
        $value = is_string($value) ? strtolower(trim($value)) : $value;
        return in_array($value, $list, true) ? $value : null;
    }

    private static function safe_version($version) {
        $version = trim((string) $version);
        if ('' === $version || !preg_match('/^[0-9A-Za-z][0-9A-Za-z.+_-]{0,31}$/', $version)) {
            return null;
        }
        return $version;
    }
}
