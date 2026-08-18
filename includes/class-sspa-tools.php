<?php
defined('ABSPATH') || exit;

/**
 * Detects what this server can do for deeper analysis, and generates the exact commands to
 * enable what it cannot - for THIS operating system, THIS PHP version and THIS SAPI, rather
 * than generic documentation the user has to translate.
 *
 * HARD RULES, and they are not negotiable:
 * - Never edit php.ini. Never run pecl. Never invoke a package manager. Never restart PHP.
 *   Everything here is text for a human to read, copy and run themselves.
 * - Never ship a compiled extension in the plugin zip.
 * - Every command is shown with the reason it is needed.
 *
 * The majority of WordPress sites are on shared hosting and can install nothing at all, so
 * the "ask your host" path is a first-class output, not an afterthought.
 */
class SSPA_Tools {

    const STATUS_ACTIVE = 'active';       // present AND used by this plugin today
    const STATUS_AVAILABLE = 'available'; // present, but this plugin does not use it yet
    const STATUS_MISSING = 'missing';     // not present, and installable
    const STATUS_BLOCKED = 'blocked';     // present but refused (e.g. a missing GRANT)

    /** Facts about this server, used to generate accurate commands. */
    public static function environment() {
        $ini_dir = defined('PHP_CONFIG_FILE_SCAN_DIR') ? PHP_CONFIG_FILE_SCAN_DIR : '';
        $scanned = function_exists('php_ini_scanned_files') ? php_ini_scanned_files() : '';
        if ($ini_dir === '' && $scanned) {
            $first = trim(strtok($scanned, ','));
            if ($first !== '') {
                $ini_dir = dirname($first);
            }
        }

        return array(
            'os' => PHP_OS_FAMILY,
            'uname' => php_uname('s') . ' ' . php_uname('r'),
            'distro' => self::distro(),
            'pkg' => self::package_manager(),
            'php' => PHP_VERSION,
            'php_short' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'zts' => defined('PHP_ZTS') ? (bool) PHP_ZTS : null,
            'sapi' => php_sapi_name(),
            'ini_dir' => $ini_dir,
            'ini_loaded' => function_exists('php_ini_loaded_file') ? php_ini_loaded_file() : '',
            'restart' => self::restart_command(),
            'mysql' => self::mysql_version(),
        );
    }

    private static function distro() {
        if (PHP_OS_FAMILY !== 'Linux' || !@is_readable('/etc/os-release')) {
            return PHP_OS_FAMILY;
        }
        $raw = @file_get_contents('/etc/os-release');
        if (!is_string($raw)) {
            return PHP_OS_FAMILY;
        }
        if (preg_match('/^PRETTY_NAME="?([^"\n]+)"?/m', $raw, $m)) {
            return trim($m[1]);
        }
        return PHP_OS_FAMILY;
    }

    /** @return string apt|dnf|apk|brew|unknown */
    private static function package_manager() {
        if (PHP_OS_FAMILY === 'Darwin') {
            return 'brew';
        }
        $distro = strtolower(self::distro());
        foreach (array('debian' => 'apt', 'ubuntu' => 'apt', 'mint' => 'apt',
                       'rocky' => 'dnf', 'alma' => 'dnf', 'centos' => 'dnf',
                       'red hat' => 'dnf', 'rhel' => 'dnf', 'fedora' => 'dnf',
                       'alpine' => 'apk') as $needle => $pkg) {
            if (strpos($distro, $needle) !== false) {
                return $pkg;
            }
        }
        return 'unknown';
    }

    /**
     * The restart command for THIS init system, not an assumed one. Handing an Alpine user a
     * systemctl line, or a Mac user either, is the generic-documentation failure this class
     * exists to avoid.
     */
    private static function restart_command() {
        $sapi = php_sapi_name();
        $ver = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $nodot = PHP_MAJOR_VERSION . PHP_MINOR_VERSION;

        if (PHP_OS_FAMILY === 'Darwin') {
            return 'brew services restart php';
        }
        if (self::package_manager() === 'apk') {
            // Alpine runs OpenRC, and names the service php-fpm83 rather than php8.3-fpm.
            return 'sudo rc-service php-fpm' . $nodot . ' restart';
        }
        if (strpos($sapi, 'apache') !== false) {
            return 'sudo systemctl restart apache2';
        }
        if (strpos($sapi, 'litespeed') !== false) {
            return 'sudo systemctl restart lsws';
        }
        return 'sudo systemctl restart php' . $ver . '-fpm';
    }

    private static function mysql_version() {
        global $wpdb;
        $suppress = $wpdb->suppress_errors(true);
        $v = $wpdb->get_var('SELECT VERSION()');
        $wpdb->suppress_errors($suppress);
        return $v !== null ? (string) $v : '';
    }

    /**
     * The performance_schema ladder from the plan: is it compiled in, switched on, and can
     * OUR database user actually read the digest table? Each rung gives a different, and
     * differently fixable, answer.
     *
     * @return array {status, detail, on, readable, db_user, digest_rows}
     */
    public static function performance_schema() {
        global $wpdb;
        $suppress = $wpdb->suppress_errors(true);

        $var = $wpdb->get_row("SHOW VARIABLES LIKE 'performance_schema'", ARRAY_A);
        $on = $var && isset($var['Value']) && in_array(strtolower($var['Value']), array('on', '1'), true);

        $readable = false;
        $rows = null;
        if ($on) {
            $rows = $wpdb->get_var('SELECT COUNT(*) FROM performance_schema.events_statements_summary_by_digest');
            $readable = ($rows !== null && $wpdb->last_error === '');
        }
        $db_user = $wpdb->get_var('SELECT CURRENT_USER()');
        $wpdb->suppress_errors($suppress);

        if (!$var) {
            $status = self::STATUS_MISSING;
            $detail = __('This MySQL server does not report a performance_schema setting at all.', 'super-speedy-performance-analysis');
        } elseif (!$on) {
            $status = self::STATUS_MISSING;
            $detail = __('performance_schema is switched off on the database server. MariaDB ships with it off by default.', 'super-speedy-performance-analysis');
        } elseif (!$readable) {
            $status = self::STATUS_BLOCKED;
            $detail = __('performance_schema is on, but this site\'s database user is not allowed to read it. One GRANT fixes this.', 'super-speedy-performance-analysis');
        } else {
            $status = self::STATUS_AVAILABLE;
            $detail = __('performance_schema is on and readable.', 'super-speedy-performance-analysis');
        }

        return array(
            'status' => $status,
            'detail' => $detail,
            'on' => $on,
            'readable' => $readable,
            'db_user' => (string) $db_user,
            'digest_rows' => $rows !== null ? (int) $rows : null,
        );
    }

    /** The GRANT this site actually needs, with its real user and host filled in. */
    public static function grant_sql() {
        $ps = self::performance_schema();
        $user = $ps['db_user'] !== '' ? $ps['db_user'] : 'youruser@localhost';
        // CURRENT_USER() returns user@host; quote both halves for a valid GRANT.
        $parts = explode('@', $user, 2);
        $quoted = "'" . str_replace("'", '', $parts[0]) . "'@'" . (isset($parts[1]) ? str_replace("'", '', $parts[1]) : 'localhost') . "'";
        return "GRANT SELECT ON performance_schema.* TO $quoted;\nFLUSH PRIVILEGES;";
    }

    /**
     * Every capability, with what it adds and whether this server has it.
     *
     * `used` is deliberately explicit: an extension being present does NOT mean this plugin
     * does anything with it yet, and the UI must never imply otherwise.
     */
    public static function capabilities() {
        $ps = self::performance_schema();

        // Ordered by value-per-install: excimer leads because it is the single biggest
        // capability upgrade a site can make, and everything below it is either built
        // in or narrower.
        return array(
            'excimer' => array(
                'label' => __('excimer (function-level profiling)', 'super-speedy-performance-analysis'),
                'adds' => __('The one to install. Which exact FUNCTION is burning the time - including inside theme template files, which no other instrument can see - with each function attributed to its plugin, split by which plugin was driving it, and broken down per request phase. A sampling profiler with negligible overhead, maintained by the Wikimedia Foundation and run on every Wikipedia page view, so it profiles during normal measurement without distorting the numbers.', 'super-speedy-performance-analysis'),
                'status' => extension_loaded('excimer') ? self::STATUS_ACTIVE : self::STATUS_MISSING,
                'used' => extension_loaded('excimer'),
                'detail' => extension_loaded('excimer')
                    ? __('In use: every profiled page carries the "By function" view, and the untimed parts of each request phase expand to the functions sampled inside them.', 'super-speedy-performance-analysis')
                    : __('Needs a one-off install by you or your host - the steps below are generated for this exact server.', 'super-speedy-performance-analysis'),
                'install' => 'excimer',
            ),
            'explain' => array(
                'label' => __('Query plans (EXPLAIN)', 'super-speedy-performance-analysis'),
                'adds' => __('Shows WHY a query is slow: a missing index, a temporary table, a filesort. Also catches queries that are fast today but will not scale.', 'super-speedy-performance-analysis'),
                'status' => self::STATUS_ACTIVE,
                'used' => true,
                'detail' => __('Built in. Needs nothing installed and no extra database permission.', 'super-speedy-performance-analysis'),
                'install' => null,
            ),
            'performance_schema' => array(
                'label' => __('MySQL query fingerprints', 'super-speedy-performance-analysis'),
                'adds' => __('MySQL\'s own normalised query statistics: how many rows were REALLY examined per query, not the optimiser\'s estimate, and which queries used no index.', 'super-speedy-performance-analysis'),
                // Read by the analysis engine as of phase 4, so ACTIVE once it is readable.
                'status' => ($ps['status'] === self::STATUS_AVAILABLE) ? self::STATUS_ACTIVE : $ps['status'],
                'used' => true,
                'detail' => $ps['detail'],
                'install' => 'performance_schema',
            ),
            'tideways_xhprof' => array(
                'label' => __('tideways_xhprof (exact call counts)', 'super-speedy-performance-analysis'),
                'adds' => __('Counts every function call exactly, which is what proves a plugin is running the same query in a loop. Higher overhead than excimer, so it is for one page at a time.', 'super-speedy-performance-analysis'),
                'status' => (extension_loaded('tideways_xhprof') || extension_loaded('xhprof')) ? self::STATUS_AVAILABLE : self::STATUS_MISSING,
                'used' => false,
                'detail' => extension_loaded('xhprof') && !extension_loaded('tideways_xhprof')
                    ? __('The original xhprof is present. tideways_xhprof is the better-maintained successor. Not read by the plugin: exact call counts are a planned deep-dive mode, and sampling answers most questions without the overhead.', 'super-speedy-performance-analysis')
                    : __('Not read by the plugin: exact call counts are a planned deep-dive mode, and sampling answers most questions without the overhead.', 'super-speedy-performance-analysis'),
                'install' => 'tideways_xhprof',
            ),
            'spx' => array(
                'label' => __('SPX (standalone profiler)', 'super-speedy-performance-analysis'),
                'adds' => __('A self-hosted profiler with its own flamegraph and timeline interface. Nothing leaves your server. We detect it and link to it rather than rebuilding what it already does well.', 'super-speedy-performance-analysis'),
                'status' => extension_loaded('spx') ? self::STATUS_AVAILABLE : self::STATUS_MISSING,
                'used' => false,
                'detail' => '',
                'install' => 'spx',
            ),
        );
    }

    /**
     * How often a query's backtrace was cut off at MAX_FRAMES on the most recent run.
     *
     * Caller-mode attribution needs to see the frame where one component calls into another.
     * If the stack was truncated before reaching it, the chain looks single-component and the
     * N+1 goes unreported. This turns "we think 14 frames is enough" into a number, measured
     * on the site in front of you rather than on a two-plugin test box.
     *
     * @return array|null {queries, truncated, pct} or null when no run has been profiled.
     */
    public static function frame_truncation() {
        global $wpdb;

        $run_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM %i WHERE status = 'done'
             AND run_type IN ('baseline','spot') ORDER BY id DESC LIMIT 1",
            SSPA_Schema::table('runs')
        ));
        if (!$run_id) {
            return null;
        }

        $queries = 0;
        $truncated = 0;
        foreach ($wpdb->get_col($wpdb->prepare(
            'SELECT profile_blob FROM %i
             WHERE run_id = %d AND profile_blob IS NOT NULL',
            SSPA_Schema::table('profiles'),
            $run_id
        )) as $blob) {
            $json = @gzuncompress($blob);
            $capture = $json ? json_decode($json, true) : null;
            if (!is_array($capture) || empty($capture['sql'])) {
                continue;
            }
            $queries += isset($capture['sql']['queries']) ? count($capture['sql']['queries']) : 0;
            $truncated += isset($capture['sql']['frames_truncated']) ? (int) $capture['sql']['frames_truncated'] : 0;
        }
        if ($queries === 0) {
            return null;
        }
        return array(
            'queries' => $queries,
            'truncated' => $truncated,
            'pct' => round($truncated / $queries * 100, 1),
        );
    }

    /** Third-party APM agents already on this server. Informational only. */
    public static function detected_apms() {
        $found = array();
        foreach (array(
            'newrelic' => 'New Relic',
            'ddtrace' => 'Datadog APM',
            'blackfire' => 'Blackfire',
            'opentelemetry' => 'OpenTelemetry',
            'elastic_apm' => 'Elastic APM',
        ) as $ext => $label) {
            if (extension_loaded($ext)) {
                $found[$ext] = $label;
            }
        }
        return $found;
    }

    /**
     * Copy-pasteable steps for this server.
     *
     * @return array of {title, why, code} blocks
     */
    public static function install_steps($key) {
        $env = self::environment();

        if ($key === 'performance_schema') {
            $ps = self::performance_schema();
            $steps = array();
            if (!$ps['on']) {
                $steps[] = array(
                    'title' => __('1. Switch performance_schema on', 'super-speedy-performance-analysis'),
                    'why' => __('MariaDB ships with it off. This needs a MySQL restart, so it is the one step that causes a moment of downtime.', 'super-speedy-performance-analysis'),
                    'code' => "# Add to my.cnf (or /etc/mysql/conf.d/99-perfschema.cnf), then restart MySQL\n[mysqld]\nperformance_schema = ON",
                );
            }
            $steps[] = array(
                'title' => $ps['on'] ? __('1. Grant read access', 'super-speedy-performance-analysis') : __('2. Grant read access', 'super-speedy-performance-analysis'),
                'why' => __('Run as a database administrator. This is READ-ONLY access to performance statistics - it grants nothing over your site\'s data and cannot change anything.', 'super-speedy-performance-analysis'),
                'code' => self::grant_sql(),
            );
            return $steps;
        }

        $ext_packages = array(
            'excimer' => 'excimer',
            'tideways_xhprof' => 'tideways_xhprof',
            'spx' => 'spx',
        );
        if (!isset($ext_packages[$key])) {
            return array();
        }
        $pkg = $ext_packages[$key];

        // Excimer has left PECL - upstream states new releases will no longer be published
        // there (1.2.6 is the last) - and is packaged by every mainstream distro instead.
        // Where a package exists it IS the whole install: nothing compiles, the package
        // ships and enables its own ini, and it upgrades alongside PHP, where a hand-built
        // copy silently stops loading on the next PHP upgrade.
        if ($key === 'excimer') {
            $nodot = PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
            // The versioned package name must come first on apt: a box can carry more than
            // one PHP (deb.sury.org setups usually do), and the unversioned php-excimer is
            // a metapackage that installs for the repo's NEWEST PHP - not necessarily the
            // one this site runs. Stock Debian/Ubuntu only carry the unversioned name,
            // hence the fallback.
            $packaged = array(
                'apt' => 'sudo apt-get install -y php' . $env['php_short'] . '-excimer'
                    . "\n\n# If that package name is not found (stock Debian/Ubuntu name it without the version):\n"
                    . 'sudo apt-get install -y php-excimer',
                'dnf' => "# Needs the Remi repository (rpms.remirepo.net) - stock RHEL/Fedora repositories do not carry it\nsudo dnf install -y php-pecl-excimer",
                'apk' => 'sudo apk add php' . $nodot . '-pecl-excimer',
            );
            // Verify with the versioned CLI binary where the platform has one: plain `php`
            // can be a different PHP than the site runs, which reports the wrong answer in
            // both directions.
            $verify = array(
                'apt' => 'php' . $env['php_short'] . ' -m | grep excimer',
                'dnf' => 'php -m | grep excimer',
                'apk' => 'php' . $nodot . ' -m | grep excimer',
            );
            if (isset($packaged[$env['pkg']])) {
                return array(
                    array(
                        'title' => __('1. Install the package', 'super-speedy-performance-analysis'),
                        'why' => sprintf(
                            /* translators: 1: detected operating system, 2: detected PHP version */
                            __('Excimer is packaged for this distribution: nothing to compile, no ini file to write (the package enables itself), and it updates with the rest of the system, where a hand-compiled copy silently stops loading when PHP is upgraded. The command targets PHP %2$s because that is what this site runs - on servers carrying several PHP versions, the unversioned package installs for the newest one instead, which leaves this site without the extension. Detected: %1$s.', 'super-speedy-performance-analysis'),
                            $env['distro'],
                            $env['php_short']
                        ),
                        'code' => $packaged[$env['pkg']],
                    ),
                    array(
                        'title' => __('2. Restart PHP', 'super-speedy-performance-analysis'),
                        'why' => sprintf(
                            /* translators: %s: the detected PHP SAPI */
                            __('A reload is not enough for a new extension. Detected SAPI: %s.', 'super-speedy-performance-analysis'),
                            $env['sapi']
                        ),
                        'code' => $env['restart'],
                    ),
                    array(
                        'title' => __('3. Check it worked', 'super-speedy-performance-analysis'),
                        'why' => __('The versioned binary matters: plain `php` on the command line can be a different PHP than the site runs. Then press Re-check on this page - that is the authoritative test, because it asks the exact PHP process serving this site.', 'super-speedy-performance-analysis'),
                        'code' => $verify[$env['pkg']],
                    ),
                );
            }
        }

        // Everything below compiles on the server. tideways_xhprof and SPX have never been
        // published to PECL, so they are always source builds. Excimer only reaches here on
        // macOS or an unrecognised distro, where pecl - deprecated upstream, but still
        // carrying excimer 1.2.6 - remains the shortest working path.
        $source_builds = array(
            'tideways_xhprof' => array(
                'repo' => 'https://github.com/tideways/php-xhprof-extension.git',
                'dir' => 'php-xhprof-extension',
                'why' => __('tideways_xhprof has never been on PECL, so it is built from source. It is free and open source (Apache 2.0) and sends nothing anywhere.', 'super-speedy-performance-analysis'),
            ),
            'spx' => array(
                'repo' => 'https://github.com/NoiseByNorthwest/php-spx.git',
                'dir' => 'php-spx',
                'why' => __('SPX is not on PECL, so it is built from source. Licence: GPL-3.', 'super-speedy-performance-analysis'),
            ),
        );

        if (isset($source_builds[$key])) {
            $build_deps = array(
                'apt' => 'sudo apt-get install -y git build-essential php' . $env['php_short'] . '-dev',
                'dnf' => 'sudo dnf install -y git make gcc php-devel',
                'apk' => 'sudo apk add git build-base php' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION . '-dev',
                'brew' => '# Homebrew PHP already ships phpize; install git if missing: brew install git',
                'unknown' => '# Install git, a C compiler and the PHP development headers for your distribution',
            );
        } else {
            $build_deps = array(
                'apt' => 'sudo apt-get install -y php-pear php' . $env['php_short'] . '-dev',
                'dnf' => 'sudo dnf install -y php-pear php-devel',
                'apk' => 'sudo apk add php' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION . '-pear php' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION . '-dev build-base',
                'brew' => '# Homebrew PHP already ships pecl',
                'unknown' => '# Install the PECL toolchain for your distribution (php-pear and the PHP dev headers)',
            );
        }

        $steps = array();
        $steps[] = array(
            'title' => __('1. Install the build tools', 'super-speedy-performance-analysis'),
            'why' => sprintf(
                /* translators: %s: detected operating system */
                __('The extension is compiled on this server, so it needs the PHP development headers. Detected: %s.', 'super-speedy-performance-analysis'),
                $env['distro']
            ),
            'code' => isset($build_deps[$env['pkg']]) ? $build_deps[$env['pkg']] : $build_deps['unknown'],
        );

        if (isset($source_builds[$key])) {
            $steps[] = array(
                'title' => __('2. Build and install', 'super-speedy-performance-analysis'),
                'why' => $source_builds[$key]['why'],
                'code' => 'git clone ' . $source_builds[$key]['repo'] . "\ncd " . $source_builds[$key]['dir'] . "\nphpize\n./configure\nmake\nsudo make install",
            );
        } else {
            $steps[] = array(
                'title' => __('2. Install the extension', 'super-speedy-performance-analysis'),
                'why' => __('Excimer is free and open source (Apache 2.0) and sends nothing anywhere - a local extension, not a service. PECL itself is deprecated and excimer 1.2.6 is the last version published there, but on this system it is still the shortest working path (the maintained successor is PIE: pie install wikimedia/excimer). If this step fails with "shtool ... does not exist or is not executable", your server mounts /tmp with noexec (a common hardening default) - temporarily allow execution there for the build, then restore it.', 'super-speedy-performance-analysis'),
                'code' => 'sudo pecl install ' . $pkg . "\n\n"
                    . "# If that fails with a 'shtool ... not executable' error (/tmp is mounted noexec):\n"
                    . "sudo mount -o remount,exec /tmp\n"
                    . 'sudo pecl install ' . $pkg . "\n"
                    . "sudo mount -o remount,noexec /tmp   # restore the hardening",
            );
        }

        $ini_dir = $env['ini_dir'] !== '' ? $env['ini_dir'] : '/etc/php/' . $env['php_short'] . '/' . ($env['sapi'] === 'cli' ? 'cli' : 'fpm') . '/conf.d';
        $steps[] = array(
            'title' => __('3. Enable it', 'super-speedy-performance-analysis'),
            'why' => sprintf(
                /* translators: %s: the ini scan directory detected on this server */
                __('Write a dedicated ini file rather than editing php.ini, so a PHP upgrade does not wipe it. This server scans: %s. Note the web server and the command line often use SEPARATE ini directories - the extension must be enabled for the one your site runs under.', 'super-speedy-performance-analysis'),
                $ini_dir
            ),
            'code' => 'echo "extension=' . $pkg . '.so" | sudo tee ' . $ini_dir . '/20-' . $pkg . '.ini',
        );
        $steps[] = array(
            'title' => __('4. Restart PHP', 'super-speedy-performance-analysis'),
            'why' => sprintf(
                /* translators: %s: the detected PHP SAPI */
                __('A reload is not enough for a new extension. Detected SAPI: %s.', 'super-speedy-performance-analysis'),
                $env['sapi']
            ),
            'code' => $env['restart'],
        );
        $steps[] = array(
            'title' => __('5. Check it worked', 'super-speedy-performance-analysis'),
            'why' => __('Then press Re-check on this page.', 'super-speedy-performance-analysis'),
            'code' => 'php -m | grep ' . $pkg,
        );

        return $steps;
    }

    /**
     * A message the site owner can paste into a support ticket. For the shared-hosting
     * majority this is the only route that ends in success, so it is generated properly
     * rather than left as "ask your host".
     */
    public static function host_message($key) {
        $caps = self::capabilities();
        $env = self::environment();
        if (!isset($caps[$key])) {
            return '';
        }
        $cap = $caps[$key];

        if ($key === 'performance_schema') {
            return sprintf(
                /* translators: 1: the GRANT statement to run, 2: the MySQL version this site reports */
                __("Hello,\n\nI am diagnosing a performance problem on my WordPress site and need read access to MySQL's performance_schema statistics.\n\nCould you please:\n1. Ensure performance_schema is enabled on the database server (MariaDB has it off by default).\n2. Run: %1\$s\n\nThis is read-only access to query statistics. It grants no access to any data and cannot modify anything.\n\nMySQL version reported to my site: %2\$s\n\nThank you.", 'super-speedy-performance-analysis'),
                str_replace("\n", ' ', self::grant_sql()),
                $env['mysql']
            );
        }

        $ini_dir = $env['ini_dir'] !== '' ? $env['ini_dir'] : '(unknown)';
        if ($key === 'excimer') {
            // The distro package is the ask: it self-enables and upgrades with PHP, and
            // excimer's PECL channel is frozen (no new releases are published there).
            $usual = "  Debian/Ubuntu: sudo apt-get install php" . $env['php_short'] . "-excimer   (or php-excimer where the versioned name does not exist - my site runs PHP " . $env['php_short'] . ", so please install for that version, not the newest on the server)\n"
                . "  RHEL/Fedora (Remi repository): sudo dnf install php-pecl-excimer\n"
                . "  Alpine: sudo apk add php" . PHP_MAJOR_VERSION . PHP_MINOR_VERSION . "-pecl-excimer\n"
                . "  then: " . $env['restart'];
        } else {
            $repo = $key === 'spx'
                ? 'https://github.com/NoiseByNorthwest/php-spx.git'
                : 'https://github.com/tideways/php-xhprof-extension.git';
            $usual = "  git clone " . $repo . "\n"
                . "  phpize && ./configure && make && make install   (in the clone)\n"
                . "  echo \"extension=" . $key . ".so\" > " . $ini_dir . "/20-" . $key . ".ini\n"
                . "  " . $env['restart'];
        }

        return sprintf(
            __("Hello,\n\nI am diagnosing a performance problem on my WordPress site and would like the PHP extension \"%1\$s\" installed.\n\nIt is free and open source, it runs entirely on the server, and it sends no data anywhere. It is a diagnostic profiler.\n\nMy environment as far as I can see it:\n- PHP %2\$s (%3\$s)\n- %4\$s\n- PHP ini scan directory: %5\$s\n\nThe usual install is:\n%6\$s\n\nIf this is not something you can install on my plan, could you let me know? Thank you.", 'super-speedy-performance-analysis'),
            $key,
            $env['php'],
            $env['sapi'],
            $env['distro'],
            $ini_dir,
            $usual
        );
    }
}
