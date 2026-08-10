<?php
defined('ABSPATH') || exit;

/**
 * Which plugins may safely be virtually excluded, and which of them have to be excluded
 * TOGETHER.
 *
 * Three sources:
 *
 *  - core's `Requires Plugins` headers, the declared dependency;
 *  - the rules snapshot's fragile list (security plugins etc that must never be excluded
 *    behind the site owner's back);
 *  - the plugins' own CODE, because the declared header is usually absent. On the site where
 *    this was written not one of nine active plugins declared anything, while Rank Math Pro
 *    carried the dependency as a literal in its main file, checked it against
 *    `active_plugins`, and called `deactivate_plugins()` on itself when it was missing.
 *
 * The last one is the interesting signal, and the question it answers is narrower than
 * "does A depend on B": it is "will A do something drastic if B is not there". A plugin that
 * degrades quietly can be orphaned for a measurement. One that switches itself off, or
 * switches its dependency back on, must never be - so the sweep excludes it in the same cell
 * as its dependency and reports the verdict as the pair.
 */
class SSPA_Dependency_Map {

    /** Cached code scan, invalidated by any change to an active plugin's main file. */
    const SIGNALS_OPTION = 'sspa_dep_signals';

    /**
     * Pairs learned by EXPERIMENT: a sweep excluded X and plugin Y reacted (tried to
     * (de)activate something, or ran a destructive statement the shim refused). The scan
     * only sees dependencies written as literals in a main file; this option is how a
     * dependency the scan cannot see still gets grouped - once, after its first (fully
     * neutralised) reaction, instead of on every sweep forever.
     */
    const LEARNED_OPTION = 'sspa_learned_groups';

    /** Main files larger than this are scanned only up to here - a loader is never this big. */
    const SCAN_BYTES = 1048576;

    /**
     * @return array slug => [slugs that require it]
     */
    public static function required_by() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $map = array();
        $active = (array) get_option('active_plugins', array());
        foreach ($active as $file) {
            $headers = get_file_data(WP_PLUGIN_DIR . '/' . $file, array('RequiresPlugins' => 'Requires Plugins'));
            $requires = array_filter(array_map('trim', explode(',', (string) $headers['RequiresPlugins'])));
            $slug = dirname($file) !== '.' ? dirname($file) : basename($file, '.php');
            foreach ($requires as $required_slug) {
                $map[$required_slug][] = $slug;
            }
        }
        return $map;
    }

    /**
     * What each active plugin's own main file says about the other plugins on this site.
     *
     * Main file only, deliberately. The requirement check that switches a plugin off lives in
     * its bootstrap - it has to run before anything else - so this finds it while staying
     * cheap enough to call on a page load. WooCommerce alone is several thousand files; nine
     * main files is nine file reads.
     *
     * @return array slug => {file, self_deactivates, activates_others, names: [slug, ...]}
     */
    public static function code_signals() {
        $active = (array) get_option('active_plugins', array());
        $by_path = array();
        foreach ($active as $file) {
            $by_path[$file] = (dirname($file) !== '.') ? dirname($file) : basename($file, '.php');
        }

        // A plugin only changes what it says about its dependencies when its file changes.
        $stamp = array();
        foreach (array_keys($by_path) as $file) {
            $path = WP_PLUGIN_DIR . '/' . $file;
            $stamp[] = $file . ':' . (int) @filemtime($path) . ':' . (int) @filesize($path);
        }
        $fingerprint = md5(implode('|', $stamp));
        $cached = get_option(self::SIGNALS_OPTION);
        if (is_array($cached) && isset($cached['fingerprint']) && $cached['fingerprint'] === $fingerprint) {
            return $cached['signals'];
        }

        $signals = array();
        foreach ($by_path as $file => $slug) {
            $path = WP_PLUGIN_DIR . '/' . $file;
            $src = is_readable($path) ? (string) @file_get_contents($path, false, null, 0, self::SCAN_BYTES) : '';
            $names = array();
            if ('' !== $src && preg_match_all('/[\'"]([A-Za-z0-9._-]+\/[A-Za-z0-9._-]+\.php)[\'"]/', $src, $matches)) {
                foreach (array_unique($matches[1]) as $referenced) {
                    if (isset($by_path[$referenced]) && $referenced !== $file) {
                        $names[] = $by_path[$referenced];
                    }
                }
            }
            $signals[$slug] = array(
                'file' => $file,
                // Can switch ITSELF off. This is the one that turned a measurement into a
                // permanent change on a real site.
                'self_deactivates' => (bool) preg_match('/\bdeactivate_plugins\s*\(/', $src),
                // Can switch something else ON - worse, because activation hooks run whole
                // installers (options, capabilities, cron).
                'activates_others' => (bool) preg_match('/\bactivate_plugin\s*\(/', $src),
                'names' => array_values(array_unique($names)),
            );
        }

        update_option(self::SIGNALS_OPTION, array('fingerprint' => $fingerprint, 'signals' => $signals), false);
        return $signals;
    }

    /**
     * slug => [slugs that must be excluded WITH it].
     *
     * An edge exists when a plugin both names another active plugin in its bootstrap and is
     * capable of activating or deactivating a plugin. Naming alone is not enough - a compat
     * shim mentions plugins it will never act on - and the capability alone says nothing
     * about which plugin it would react to. Declared `Requires Plugins` headers count on
     * their own, being a statement of exactly this.
     *
     * Transitive: excluding a dependency pulls in the dependants of its dependants.
     */
    public static function must_exclude_together() {
        $dependants = self::required_by(); // dependency slug => [dependants], from headers

        // Pairs proven by a previous sweep's caught reaction outrank any heuristic.
        foreach ((array) get_option(self::LEARNED_OPTION, array()) as $dependency => $reactors) {
            foreach ((array) $reactors as $reactor) {
                if (!isset($dependants[$dependency]) || !in_array($reactor, $dependants[$dependency], true)) {
                    $dependants[$dependency][] = (string) $reactor;
                }
            }
        }

        foreach (self::code_signals() as $slug => $signal) {
            if (empty($signal['self_deactivates']) && empty($signal['activates_others'])) {
                continue;
            }
            foreach ($signal['names'] as $dependency) {
                if (!isset($dependants[$dependency]) || !in_array($slug, $dependants[$dependency], true)) {
                    $dependants[$dependency][] = $slug;
                }
            }
        }

        // Closure: excluding A takes B, and whatever cannot run without B.
        $groups = array();
        foreach (array_keys($dependants) as $root) {
            $group = array();
            $queue = array($root);
            while ($queue) {
                $current = array_shift($queue);
                foreach ((isset($dependants[$current]) ? $dependants[$current] : array()) as $dependant) {
                    if ($dependant === $root || isset($group[$dependant])) {
                        continue;
                    }
                    $group[$dependant] = true;
                    $queue[] = $dependant;
                }
            }
            if ($group) {
                $groups[$root] = array_keys($group);
            }
        }
        return $groups;
    }

    public static function fragile() {
        $rules = SSPA_Rules::categories();
        $fragile = isset($rules['security']) ? $rules['security'] : array();
        $extra = SSPA_Rules::fragile();
        return array_values(array_unique(array_merge($fragile, $extra)));
    }

    /**
     * Active plugin slugs that are safe to virtually exclude in the deep sweep: not us, and
     * not fragile.
     *
     * A dependency root used to be dropped here, which kept it safe and made it permanently
     * unmeasurable - on a Rank Math site the SEO plugin, usually one of the most expensive
     * things on the page, could never get a measured verdict. It is a candidate again now
     * that excluding it takes its dependants with it in the same cell.
     *
     * With one limit that grouping creates: a plugin whose group would contain a FRAGILE
     * plugin is not a candidate either. Excluding it would leave a security plugin either
     * orphaned or switched off for the test request, and the fragile list exists to promise
     * neither ever happens. Better to leave one plugin unmeasured than to measure a page
     * with the site's security plugin missing.
     */
    public static function isolation_candidates() {
        $active = array();
        foreach ((array) get_option('active_plugins', array()) as $file) {
            $active[] = dirname($file) !== '.' ? dirname($file) : basename($file, '.php');
        }
        $fragile = self::fragile();
        $exclude = array_merge(array('super-speedy-performance-analysis'), $fragile);

        foreach (self::must_exclude_together() as $root => $group) {
            if (array_intersect($group, $fragile)) {
                $exclude[] = $root;
            }
        }
        return array_values(array_diff($active, $exclude));
    }

    /**
     * slug => plugin file (dir/file.php) for the active set.
     */
    public static function slug_to_file() {
        $map = array();
        foreach ((array) get_option('active_plugins', array()) as $file) {
            $slug = dirname($file) !== '.' ? dirname($file) : basename($file, '.php');
            $map[$slug] = $file;
        }
        return $map;
    }
}
