<?php
defined('ABSPATH') || exit;
/**
 * One validated record of a plugin change: which plugin, what happened, and the version it
 * came from and went to. Change capture writes these, the pending change set stores them,
 * the run context embeds them and History reads them back - all through this one shape, so
 * no layer rebuilds the vocabulary or accepts a combination another layer would reject.
 *
 * Version requirements follow from the action. An install or activation has only a target
 * version; a deactivation or removal has only a previous version; an update has both. A
 * record that breaks its action's requirement is rejected, not repaired.
 */
class SSPA_Plugin_Change {
    const ACTIONS = array('installed', 'updated', 'activated', 'deactivated', 'removed');

    private $slug;
    private $action;
    private $from_version;
    private $to_version;

    private function __construct($slug, $action, $from_version, $to_version) {
        $this->slug = $slug;
        $this->action = $action;
        $this->from_version = $from_version;
        $this->to_version = $to_version;
    }

    /**
     * @param mixed $raw {slug, action, from_version?, to_version?}
     * @return SSPA_Plugin_Change|null Null when the record is not a valid plugin change.
     */
    public static function from_array($raw) {
        if (!is_array($raw)) {
            return null;
        }
        $slug = sanitize_key(isset($raw['slug']) ? $raw['slug'] : '');
        $action = sanitize_key(isset($raw['action']) ? $raw['action'] : '');
        if ('' === $slug || !in_array($action, self::ACTIONS, true)) {
            return null;
        }
        $from = SSPA_Version::normalise(isset($raw['from_version']) ? $raw['from_version'] : '');
        $to = SSPA_Version::normalise(isset($raw['to_version']) ? $raw['to_version'] : '');
        $needs_from = in_array($action, array('updated', 'deactivated', 'removed'), true);
        $needs_to = in_array($action, array('installed', 'updated', 'activated'), true);
        if (($needs_from && '' === $from) || (!$needs_from && '' !== $from)) {
            return null;
        }
        if (($needs_to && '' === $to) || (!$needs_to && '' !== $to)) {
            return null;
        }
        return new self($slug, $action, $from, $to);
    }

    /** The one public shape. */
    public function to_array() {
        return array(
            'slug' => $this->slug,
            'action' => $this->action,
            'from_version' => $this->from_version,
            'to_version' => $this->to_version,
        );
    }

    public function slug() { return $this->slug; }
    public function action() { return $this->action; }
    public function from_version() { return $this->from_version; }
    public function to_version() { return $this->to_version; }
}
