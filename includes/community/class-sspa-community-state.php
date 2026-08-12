<?php
defined('ABSPATH') || exit;

/**
 * Component state: what another plugin was CONFIGURED as when a run was measured.
 *
 * Two runs of the same site can be identical in every field this plugin measures and still
 * describe different software, because the only thing that changed between them was another
 * plugin's configuration. Without that configuration recorded, a before/after pair is two
 * unexplained sets of numbers.
 *
 * The mechanism is a declaration, never a dump. A plugin publishes its own state through the
 * `sspa_component_state` filter, and registering that filter IS its consent to share what it
 * returns. This plugin never reads another plugin's options directly and never guesses which of
 * them are safe: it cannot know, because it does not know what they mean. Scalability Pro's
 * option table alone holds a licence key and a free-text CSS selector next to the performance
 * settings, and no allowlist maintained over here could keep up with that.
 *
 * What this class DOES enforce is a value grammar, because a declaration is not the same as a
 * guarantee. A filter written by somebody else - or by us on a bad day - must not be able to put
 * a URL, a filesystem path or an email address into a payload, and above all must not be able to
 * stop the site submitting anything ever again: SSPA_Community_Privacy::validate() aborts the
 * WHOLE payload on one forbidden value, so each record is validated alone and DROPPED on failure
 * rather than being allowed to take the submission with it.
 *
 * The division of responsibility, stated so nobody has to infer it: the filter decides WHICH keys
 * travel, this class decides only WHAT A VALUE MAY LOOK LIKE. A semantically sensitive value that
 * happens to look like an enum token will pass. That residual risk sits with the declaring plugin,
 * which is the only code that knows what its own settings mean.
 */
class SSPA_Community_State {

    /** Records per run. A payload describes one site, not a plugin directory. */
    const MAX_RECORDS = 8;

    /** Keys per map. Generous for a settings page, mean enough to stop a rogue filter. */
    const MAX_KEYS = 64;

    /** Members in a list value. */
    const MAX_LIST = 32;

    /**
     * The three maps a record may carry, in the order a receiver should prefer them.
     *
     * They are kept apart deliberately. `summary` is the plugin's own classification of itself -
     * small, stable, and groupable by a receiver that knows nothing about the plugin. `options`
     * is the raw configuration detail, which only means anything alongside the component version.
     * `state` is runtime fact that is not a setting at all (an index exists, a table is built).
     * Merging them would force a receiver to know which keys were which before it could trust any
     * of them.
     */
    const MAPS = array('summary', 'options', 'state');

    /**
     * Ask every opted-in plugin for its state, at the moment a run starts.
     *
     * Called from SSPA_Run_Controller::start() and stored on the run. NOT called at export time:
     * see the note on capture timing in add_component_state().
     *
     * There is deliberately no run id in the context: the run row does not exist yet when this
     * fires, and it must not, or the state would be captured after the first page was profiled.
     *
     * @param array $context {run_type, trigger}
     * @return array Sanitised records, ready to store. Empty when nobody opted in.
     */
    public static function collect($context) {
        $context = array(
            'run_type' => isset($context['run_type']) ? sanitize_key($context['run_type']) : '',
            'trigger' => isset($context['trigger']) ? sanitize_key($context['trigger']) : '',
        );

        /**
         * Publish this plugin's configuration alongside a performance measurement.
         *
         * Return one entry per component. Registering this filter is an opt-in: whatever is
         * returned is submitted to superspeedy.org when the site's owner has enabled sharing,
         * so return only what your plugin is willing to publish about itself. Never return
         * licence keys, credentials, hostnames, paths, or any free-text field a site owner can
         * type into.
         *
         *     array(
         *         'component' => array('type' => 'plugin', 'slug' => 'your-plugin'),
         *         'state_schema_version' => 1,
         *         'disclosure' => array(
         *             'label' => 'Your Plugin',
         *             'publishes' => array(
         *                 'which of its optimisations are switched on',
         *                 'how many of its indexes are installed',
         *             ),
         *         ),
         *         'summary' => array('profile' => 'good'),
         *         'options' => array('some_setting' => 'enabled'),
         *         'state'   => array('index_installed' => true),
         *     )
         *
         * `disclosure` is what the site's owner is shown before they agree to any of this, in
         * their language rather than in key names. It is NOT submitted: it is consent text, and a
         * receiver has the keys themselves. Declaring it is not optional in spirit - a plugin
         * that publishes without saying what it publishes is asking for consent to something
         * nobody can read - so a record with no disclosure is listed as undeclared and the site
         * owner is told exactly that.
         *
         * `component.version` is filled in by this plugin from the inventory captured for the
         * same run, so a state record can never disagree with the rest of the payload about what
         * was measured. Supplying one has no effect.
         *
         * Values must be scalar: bool, int, float, null, a short token string, or a list of
         * those. Anything else is dropped and counted in `omitted_keys`.
         *
         * The site's owner can switch off any individual publisher on the Share tab, and that
         * decision is honoured here, at capture time - a switched-off plugin's configuration is
         * never written to the run in the first place, rather than being collected and filtered
         * out later.
         *
         * @param array $records Records collected so far.
         * @param array $context {run_type:string, trigger:string}
         */
        $records = apply_filters('sspa_component_state', array(), $context);

        if (!is_array($records)) {
            return array();
        }

        $disabled = self::disabled();

        $clean = array();
        foreach ($records as $record) {
            if (count($clean) >= self::MAX_RECORDS) {
                break;
            }
            $safe = self::sanitise_record($record);
            if (!$safe) {
                continue;
            }
            // The owner's decision wins over the plugin author's. Applied before storage, so a
            // switched-off publisher leaves no trace on the run at all.
            if (in_array($safe['component']['slug'], $disabled, true)) {
                continue;
            }
            // Consent text, for the owner. Never submitted.
            unset($safe['disclosure']);
            $clean[$safe['component']['type'] . ':' . $safe['component']['slug']] = $safe;
        }

        $clean = array_values($clean);
        usort($clean, function ($a, $b) {
            return array($a['component']['type'], $a['component']['slug'])
                <=> array($b['component']['type'], $b['component']['slug']);
        });
        return $clean;
    }

    /**
     * One record, or null when it is not a record at all.
     *
     * A record with every value dropped is still returned: "this plugin was active and told us
     * nothing it could share" is a different fact from "this plugin was not asked", and
     * omitted_keys says which happened.
     */
    private static function sanitise_record($record) {
        if (!is_array($record) || empty($record['component']) || !is_array($record['component'])) {
            return null;
        }

        $slug = isset($record['component']['slug']) ? strtolower(trim((string) $record['component']['slug'])) : '';
        $type = isset($record['component']['type']) ? strtolower(trim((string) $record['component']['type'])) : 'plugin';
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,190}$/', $slug)) {
            return null;
        }
        if (!in_array($type, array('plugin', 'theme', 'mu-plugin'), true)) {
            $type = 'plugin';
        }

        $omitted = 0;
        $safe = array(
            'component' => array('slug' => $slug, 'type' => $type),
            'state_schema_version' => isset($record['state_schema_version'])
                ? max(1, min(65535, (int) $record['state_schema_version']))
                : 1,
            'disclosure' => self::sanitise_disclosure(
                isset($record['disclosure']) ? $record['disclosure'] : array(),
                $slug
            ),
        );

        foreach (self::MAPS as $map) {
            $safe[$map] = self::sanitise_map(isset($record[$map]) ? $record[$map] : array(), $omitted);
        }

        // A receiver must be able to tell "the plugin has no such setting" from "the setting was
        // censored on its way out". Those support opposite conclusions.
        $safe['omitted_keys'] = $omitted;
        return $safe;
    }

    /**
     * The plain-English account a plugin gives of what it publishes.
     *
     * Held to a different standard from the payload maps, because it is going on a screen rather
     * than into a JSON document: prose is allowed, tags are not, and it is never submitted.
     *
     * A plugin that declares nothing is not refused - refusing would mean a plugin author's
     * omission silently removes their configuration from a payload the owner already agreed to,
     * which is a worse failure than an unlabelled row. It is marked `declared => false` instead,
     * and the Share tab says in as many words that the plugin has not described what it sends.
     * That is a bug report the site owner can act on, which is what it should be.
     */
    private static function sanitise_disclosure($disclosure, $slug) {
        $disclosure = is_array($disclosure) ? $disclosure : array();

        $label = isset($disclosure['label']) ? wp_strip_all_tags((string) $disclosure['label']) : '';
        $label = trim(preg_replace('/\s+/', ' ', $label));

        $publishes = array();
        foreach ((array) (isset($disclosure['publishes']) ? $disclosure['publishes'] : array()) as $line) {
            if (!is_string($line)) {
                continue;
            }
            $line = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($line)));
            if ('' === $line) {
                continue;
            }
            $publishes[] = self::truncate($line, 200);
            if (count($publishes) >= 12) {
                break;
            }
        }

        return array(
            // Fall back to the slug rather than to nothing: an unnamed row the owner cannot
            // identify is not a consent screen.
            'label' => '' !== $label ? self::truncate($label, 64) : $slug,
            'publishes' => $publishes,
            'declared' => ('' !== $label || (bool) $publishes),
        );
    }

    private static function truncate($text, $limit) {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return (mb_strlen($text) > $limit) ? rtrim(mb_substr($text, 0, $limit - 1)) . '…' : $text;
        }
        return (strlen($text) > $limit) ? rtrim(substr($text, 0, $limit - 1)) . '…' : $text;
    }

    /**
     * Every plugin that has registered the filter, whether or not the owner has switched it off,
     * with what it says it publishes and whether it is currently publishing.
     *
     * This is what the Share tab lists. It deliberately includes switched-off publishers: a
     * consent screen that hides the things you have declined gives you no way to change your
     * mind, and no way to see that the plugin is still installed and still asking.
     *
     * @return array<int, array{slug, type, label, publishes, declared, enabled}>
     */
    public static function publishers() {
        $records = apply_filters('sspa_component_state', array(), array('run_type' => '', 'trigger' => 'disclosure'));
        if (!is_array($records)) {
            return array();
        }

        $disabled = self::disabled();

        $out = array();
        foreach ($records as $record) {
            $safe = self::sanitise_record($record);
            if (!$safe) {
                continue;
            }
            $slug = $safe['component']['slug'];
            $out[$slug] = array(
                'slug' => $slug,
                'type' => $safe['component']['type'],
                'label' => $safe['disclosure']['label'],
                'publishes' => $safe['disclosure']['publishes'],
                'declared' => $safe['disclosure']['declared'],
                'enabled' => !in_array($slug, $disabled, true),
            );
        }

        ksort($out, SORT_STRING);
        return array_values($out);
    }

    /**
     * Slugs the site's owner has switched off. Stored as an explicit deny list rather than an
     * allow list so that installing a new plugin does not silently start publishing under a
     * decision made before it existed... which is exactly why the Share tab shows every
     * publisher rather than only the ones in this list.
     */
    public static function disabled() {
        $disabled = get_option('sspa_component_state_disabled', array());
        if (!is_array($disabled)) {
            return array();
        }
        $clean = array();
        foreach ($disabled as $slug) {
            $slug = strtolower(trim((string) $slug));
            if (preg_match('/^[a-z0-9][a-z0-9._-]{0,190}$/', $slug)) {
                $clean[] = $slug;
            }
        }
        return array_values(array_unique($clean));
    }

    /**
     * Switch one publisher on or off.
     *
     * @param string $slug
     * @param bool   $enabled
     * @return array The deny list now in force.
     */
    public static function set_enabled($slug, $enabled) {
        $slug = strtolower(trim((string) $slug));
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,190}$/', $slug)) {
            return self::disabled();
        }

        $disabled = self::disabled();
        if ($enabled) {
            $disabled = array_values(array_diff($disabled, array($slug)));
        } elseif (!in_array($slug, $disabled, true)) {
            $disabled[] = $slug;
        }

        sort($disabled, SORT_STRING);
        update_option('sspa_component_state_disabled', $disabled, false);
        return $disabled;
    }

    /**
     * A flat map of scalars. Nesting is refused rather than walked: a grammar that admits
     * arbitrary structure is not a grammar, and a caller with structure to send can flatten it
     * into a token string (Archives sends composite indexes as comma-joined column lists).
     */
    private static function sanitise_map($map, &$omitted) {
        if (!is_array($map)) {
            $omitted++;
            return array();
        }

        $safe = array();
        ksort($map, SORT_STRING);
        foreach ($map as $key => $value) {
            if (!is_string($key) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/i', $key)) {
                $omitted++;
                continue;
            }
            if (count($safe) >= self::MAX_KEYS) {
                $omitted++;
                continue;
            }
            $clean = self::sanitise_value($value);
            if (self::REJECTED === $clean) {
                $omitted++;
                continue;
            }

            // The character grammar is necessary but not sufficient: "203.0.113.42" is
            // alphanumerics and dots, so it passes, and a key ending in _ip or _email is
            // forbidden outright regardless of what it holds. Run the real privacy scanner over
            // this ONE key so the answer is "that key was censored" rather than "the whole record
            // was thrown away" - the exporter's per-record check would otherwise drop a plugin's
            // entire configuration over a single stray value.
            //
            // The real scanner rather than a copy of its rules on purpose: a second copy of the
            // forbidden-key list would drift from the one that actually guards the payload.
            if (is_wp_error(SSPA_Community_Privacy::validate(array($key => $clean)))) {
                $omitted++;
                continue;
            }

            $safe[$key] = $clean;
        }
        return $safe;
    }

    /** Sentinel, because null is a legitimate value and false is a legitimate value. */
    const REJECTED = "\0sspa-rejected";

    private static function sanitise_value($value) {
        if (null === $value || is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (is_finite((float) $value) && abs((float) $value) < 1e12) ? $value : self::REJECTED;
        }
        if (is_string($value)) {
            // Leading alphanumeric is doing real work here: it rejects a CSS selector
            // (".col-sm-3.col-sm-pull-9") and a path fragment while still admitting "02:00",
            // "6.29.14" and "post_type,menu_order ASC".
            return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:, -]{0,127}$/', $value) ? $value : self::REJECTED;
        }
        if (is_array($value)) {
            // An empty list is a legitimate value and a common one: "no meta keys configured" is
            // exactly what most sites have to say. Checked before the list test below because
            // range(0, -1) is [0, -1] rather than [], so an empty array fails a naive list check
            // and would be dropped - reported as a censored key, when nothing was censored.
            if (!$value) {
                return array();
            }
            $list = array_values($value);
            if (count($list) > self::MAX_LIST || array_keys($value) !== range(0, count($value) - 1)) {
                return self::REJECTED;
            }
            $safe = array();
            foreach ($list as $item) {
                $clean = self::sanitise_value($item);
                // One bad member invalidates the list: a silently shortened list reads as a
                // shorter configuration, which is a different claim about the site.
                if (self::REJECTED === $clean || is_array($clean)) {
                    return self::REJECTED;
                }
                $safe[] = $clean;
            }
            return $safe;
        }
        return self::REJECTED;
    }

    /**
     * The run-level before/after pairing.
     *
     * Belongs to the RUN, not to any one plugin: "this measurement was taken before applying X"
     * is a property of the measurement, and both Scalability Pro and Archives drive the same
     * two-phase cycle. Carrying it per-plugin would let two records in one payload disagree about
     * which half of the cycle the run was.
     *
     * @return array|null {cycle_uuid, phase, driver} or null when this run is not part of a cycle
     */
    public static function change_cycle($args) {
        if (empty($args) || !is_array($args)) {
            return null;
        }
        $phase = isset($args['phase']) ? sanitize_key($args['phase']) : '';
        $driver = isset($args['driver']) ? strtolower(trim((string) $args['driver'])) : '';
        $uuid = isset($args['cycle_uuid']) ? (string) $args['cycle_uuid'] : '';

        if (!in_array($phase, array('before', 'after'), true)) {
            return null;
        }
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,190}$/', $driver)) {
            return null;
        }
        if (!wp_is_uuid($uuid, 4)) {
            return null;
        }
        return array('cycle_uuid' => $uuid, 'phase' => $phase, 'driver' => $driver);
    }
}
