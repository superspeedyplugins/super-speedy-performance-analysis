<?php
defined('ABSPATH') || exit;

/**
 * How many times a normal analysis measures each page (Dave, 24 November 2026, 0.71.0).
 *
 * "Measurement runs per page" (option measurement_runs) applies to baseline, spot and Analyse
 * this page runs, which used 1 warm-up + 3 measured samples before 0.71.0:
 *   - one (default): one measured request per page, no warm-up. The cold time is reported.
 *   - three: 1 warm-up + 3 measured requests, median reported. The careful mode.
 * Plugin Impact Analysis, Cache Impact Analysis, endpoint profiling, the checkout flow and
 * admin save profiling keep their own fixed sampling: they compare like with like, and their
 * jobs set samples (or rely on SSPA_Crawler's defaults) themselves.
 *
 * The mode a run used is recorded in its notes as measurement {mode, warmups, samples}.
 */
class SSPA_Measurement {

    const ONE = 'one';
    const THREE = 'three';

    /** Run types the setting applies to. adhoc only when the caller did not ask for N samples. */
    const RUN_TYPES = array('baseline', 'spot', 'adhoc');

    /** Seconds per request learned from completed runs, newest last (see record_run_rate()). */
    const RATE_OPTION = 'sspa_seconds_per_request';

    public static function modes() {
        return array(
            self::ONE => __('One run (default)', 'super-speedy-performance-analysis'),
            self::THREE => __('Three runs', 'super-speedy-performance-analysis'),
        );
    }

    public static function sanitise_mode($mode) {
        return in_array($mode, array(self::ONE, self::THREE), true) ? $mode : self::ONE;
    }

    /** The site's setting. */
    public static function mode() {
        return self::sanitise_mode((string) sspa_get_option('measurement_runs'));
    }

    /** @return array{mode: string, warmups: int, samples: int} */
    public static function discipline($mode = null) {
        $mode = self::sanitise_mode(null === $mode ? self::mode() : $mode);
        return self::THREE === $mode
            ? array('mode' => self::THREE, 'warmups' => SSPA_Crawler::WARMUPS, 'samples' => SSPA_Crawler::SAMPLES)
            : array('mode' => self::ONE, 'warmups' => 0, 'samples' => 1);
    }

    /**
     * Stamp a run's jobs with the discipline, through the per-job overrides SSPA_Crawler and
     * SSPA_Browser_Transport already honour (samples, skip_warmup).
     */
    public static function apply_to_jobs($jobs, $discipline) {
        foreach ($jobs as $i => $job) {
            $jobs[$i]['samples'] = (int) $discipline['samples'];
            if (0 === (int) $discipline['warmups']) {
                $jobs[$i]['skip_warmup'] = true;
            } else {
                unset($jobs[$i]['skip_warmup']);
            }
        }
        return $jobs;
    }

    /**
     * The discipline a run used, or null when it is not known or does not apply.
     *
     * A completed baseline, spot or adhoc run without a recorded measurement predates 0.71.0,
     * when 1 warm-up + 3 samples was the only discipline. A failed run's notes are a plain
     * sentence, so its mode is unknown rather than guessed.
     *
     * @param array $run A runs row.
     */
    public static function for_run($run) {
        if (!is_array($run)) {
            return null;
        }
        $notes = json_decode((string) ($run['notes'] ?? ''), true);
        if (is_array($notes) && isset($notes['measurement']) && is_array($notes['measurement'])) {
            $m = $notes['measurement'];
            return array(
                'mode' => isset($m['mode']) ? sanitize_key((string) $m['mode']) : '',
                'warmups' => isset($m['warmups']) ? (int) $m['warmups'] : 0,
                'samples' => isset($m['samples']) ? (int) $m['samples'] : 0,
            );
        }
        if ('done' === ($run['status'] ?? '') && in_array($run['run_type'] ?? '', self::RUN_TYPES, true)) {
            return self::discipline(self::THREE);
        }
        return null;
    }

    /** One line naming what a discipline measures, as the exports and Compare print it. */
    public static function label($discipline) {
        if (!is_array($discipline)) {
            return __('unknown (not recorded for this run)', 'super-speedy-performance-analysis');
        }
        $warmups = (int) $discipline['warmups'];
        $samples = (int) $discipline['samples'];
        if (1 === $samples && 0 === $warmups) {
            return __('1 measured request per page, cold (no warm-up)', 'super-speedy-performance-analysis');
        }
        if (1 === $samples) {
            return __('1 measured request per page after a warm-up', 'super-speedy-performance-analysis');
        }
        return $warmups
            /* translators: %d: number of measured requests per page. */
            ? sprintf(_n('median of %d measured request after a warm-up', 'median of %d measured requests after a warm-up', $samples, 'super-speedy-performance-analysis'), $samples)
            /* translators: %d: number of measured requests per page. */
            : sprintf(_n('median of %d measured request, no warm-up', 'median of %d measured requests, no warm-up', $samples, 'super-speedy-performance-analysis'), $samples);
    }

    /** The sentence the Run Analysis panel shows for the site's current setting. */
    public static function run_panel_sentence() {
        return self::ONE === self::mode()
            ? __('Each page is measured once, cold, with no warm-up: one request per page instead of four. Run it again yourself to check a figure, or choose Three runs for a median.', 'super-speedy-performance-analysis')
            : __('Each page gets a warm-up request and three measured requests, and the median is reported: four requests per page. Choose One run to measure each page once, cold.', 'super-speedy-performance-analysis');
    }

    /** The settings control's location, for links from the screens that start runs. */
    public static function settings_url() {
        return admin_url('admin.php?page=sspa#compare');
    }

    /** Requests a job sends when nothing is retried, for run-time learning. */
    public static function requests_for_job($job) {
        if (!empty($job['checkout'])) {
            return 0; // a purchase flow: a variable number of steps, not a page measurement
        }
        $warmups = (!empty($job['skip_warmup']) || (isset($job['page_key']) && 'baseline' === $job['page_key'])) ? 0 : SSPA_Crawler::WARMUPS;
        $samples = isset($job['samples']) ? max(1, (int) $job['samples']) : SSPA_Crawler::SAMPLES;
        return $warmups + $samples;
    }

    /**
     * Learn seconds per request from a run that has just crawled its whole queue, so the Plugin
     * Impact Analysis estimate stays right whichever mode the recent analyses used.
     */
    public static function record_run_rate($queue) {
        if (!is_array($queue) || empty($queue['jobs']) || empty($queue['started_at'])) {
            return;
        }
        $requests = 0;
        foreach ((array) $queue['jobs'] as $job) {
            $requests += self::requests_for_job($job);
        }
        $seconds = time() - (int) $queue['started_at'];
        if ($requests < 3 || $seconds <= 0) {
            return;
        }
        $rates = get_option(self::RATE_OPTION, array());
        $rates = is_array($rates) ? array_values(array_filter($rates, 'is_numeric')) : array();
        $rates[] = round($seconds / $requests, 3);
        update_option(self::RATE_OPTION, array_slice($rates, -5), false);
    }

    /** Median learned seconds per request, or null before any run has recorded one. */
    public static function seconds_per_request() {
        $rates = get_option(self::RATE_OPTION, array());
        $rates = is_array($rates) ? array_values(array_filter($rates, 'is_numeric')) : array();
        if (!$rates) {
            return null;
        }
        sort($rates);
        return (float) $rates[(int) floor((count($rates) - 1) / 2)];
    }
}
