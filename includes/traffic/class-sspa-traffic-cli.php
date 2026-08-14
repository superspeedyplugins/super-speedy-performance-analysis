<?php
defined('ABSPATH') || exit;

/** WP-CLI controls for the experimental traffic collector. */
class SSPA_Traffic_CLI {

    /**
     * Start a collection.
     *
     * [--duration=<duration>]
     * : 24h (default), 72h or 7d.
     *
     * [--format=<format>]
     * : table (default) or json.
     */
    public function start($args, $assoc_args) {
        $result = SSPA_Traffic_Collection::start(isset($assoc_args['duration']) ? $assoc_args['duration'] : '24h', 'cli');
        $this->output($result, $assoc_args);
    }

    /**
     * Show collection health and progress.
     *
     * [--collection=<id>]
     * : Specific collection; defaults to the active or latest collection.
     *
     * [--format=<format>]
     * : table (default) or json.
     */
    public function status($args, $assoc_args) {
        $result = SSPA_Traffic_Collection::status(!empty($assoc_args['collection']) ? (int) $assoc_args['collection'] : 0);
        $this->output($result, $assoc_args);
    }

    /**
     * Stop request collection and retain the bounded order-outcome observer.
     *
     * [--collection=<id>]
     * : Specific collection; defaults to the active collection.
     *
     * [--emergency]
     * : Remove the observer immediately, including order-outcome observation.
     *
     * [--format=<format>]
     * : table (default) or json.
     */
    public function stop($args, $assoc_args) {
        $result = SSPA_Traffic_Collection::stop(
            !empty($assoc_args['collection']) ? (int) $assoc_args['collection'] : 0,
            !empty($assoc_args['emergency'])
        );
        $this->output($result, $assoc_args);
    }

    /**
     * Export privacy-safe Phase 3 observations, not a finished traffic report.
     *
     * [--collection=<id>]
     * : Specific collection; defaults to the active or latest collection.
     *
     * [--output=<path>]
     * : Write JSON to this path instead of stdout.
     */
    public function observations($args, $assoc_args) {
        $result = SSPA_Traffic_Collection::observations(!empty($assoc_args['collection']) ? (int) $assoc_args['collection'] : 0);
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }
        $json = wp_json_encode($result, JSON_PRETTY_PRINT);
        if (!empty($assoc_args['output'])) {
            if (false === file_put_contents($assoc_args['output'], $json . PHP_EOL)) {
                WP_CLI::error('Could not write observations to ' . $assoc_args['output']);
            }
            WP_CLI::success('Wrote ' . $assoc_args['output']);
            return;
        }
        WP_CLI::line($json);
    }

    /**
     * Permanently delete one stopped collection, its raw rows and temporary join key.
     *
     * <collection-id>
     * : Collection to delete.
     *
     * [--yes]
     * : Skip the confirmation prompt.
     */
    public function delete($args, $assoc_args) {
        $collection_id = isset($args[0]) ? (int) $args[0] : 0;
        if (!$collection_id) {
            WP_CLI::error('Provide the collection id to delete.');
        }
        WP_CLI::confirm('Permanently delete collection ' . $collection_id . ' and all of its raw traffic data?', $assoc_args);
        $result = SSPA_Traffic_Collection::delete($collection_id);
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }
        WP_CLI::success('Deleted traffic collection ' . $collection_id . '.');
    }

    private function output($result, $assoc_args) {
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }
        if (isset($assoc_args['format']) && 'json' === $assoc_args['format']) {
            WP_CLI::line(wp_json_encode($result));
            return;
        }
        if (empty($result['collection'])) {
            WP_CLI::log('No traffic collection found.');
            return;
        }
        WP_CLI\Utils\format_items('table', array($result['collection']), array(
            'id', 'status', 'started_at', 'collect_until', 'outcomes_until', 'event_count',
            'event_ceiling', 'table_bytes', 'observer_state', 'stop_reason',
        ));
    }
}
