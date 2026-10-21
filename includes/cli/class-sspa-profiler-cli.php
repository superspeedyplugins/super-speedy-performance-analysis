<?php
defined('ABSPATH') || exit;

/**
 * Turn the profiler on or off from WP-CLI, exactly as Tools > Enable profiler / Disable
 * profiler do: the same SSPA_Run_Controller::set_profiler() call, the same refusal while an
 * analysis is running, and the same record of the choice.
 */
class SSPA_Profiler_CLI {

    /**
     * Enable the profiler: install the profiling loader in wp-content/mu-plugins.
     *
     * Analyses need it. Same effect as the Enable profiler button on the Tools tab. On
     * multisite, run it as a network administrator (--user=<login>).
     *
     * ## EXAMPLES
     *
     *     wp sspa profiler enable
     */
    public function enable($args, $assoc_args) {
        $this->set(true);
    }

    /**
     * Disable the profiler: remove the profiling loader from wp-content/mu-plugins.
     *
     * Same effect as the Disable profiler button on the Tools tab. Analyses cannot start
     * until it is enabled again.
     *
     * ## EXAMPLES
     *
     *     wp sspa profiler disable
     */
    public function disable($args, $assoc_args) {
        $this->set(false);
    }

    private function set($enabled) {
        $result = SSPA_Run_Controller::set_profiler($enabled);
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }
        WP_CLI::success($enabled ? 'Profiler enabled: the loader is installed in wp-content/mu-plugins.' : 'Profiler disabled: the loader has been removed from wp-content/mu-plugins.');
    }
}
