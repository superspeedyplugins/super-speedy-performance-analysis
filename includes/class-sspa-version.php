<?php
defined('ABSPATH') || exit;
/**
 * The one definition of a valid plugin, theme or platform version string.
 *
 * Change capture, the pending change set, History's run context and component comparison,
 * the community exporter, the report's measured-version comparison and the site snapshot all
 * used to carry their own copy of this grammar, with two different length caps and two
 * different "invalid" results. A divergence between any two of them silently broke version
 * comparisons on formatting alone. There is now one grammar and two deliberate entry points:
 * local capture and comparison, and the privacy boundary.
 *
 * Grammar: after sanitize_text_field() and trim(), the first character is alphanumeric and the
 * rest is drawn from [0-9A-Za-z.+_-]. That admits semantic versions, pre-release and build
 * suffixes ('1.2.3-beta.1+build.7'), a leading 'v' and calendar versions, and refuses
 * whitespace inside, quotes, SQL and markup.
 */
class SSPA_Version {
    /** Longest version kept for local History and change capture. */
    const LOCAL_MAX = 64;
    /** Longest version that may cross the sharing boundary; unchanged from the exporter's cap. */
    const SHARED_MAX = 32;

    /**
     * Local capture and comparison.
     *
     * @param mixed $version
     * @return string The normalised version, or '' when it is empty or invalid.
     */
    public static function normalise($version) {
        return self::accept($version, self::LOCAL_MAX);
    }

    /**
     * The privacy boundary: what may leave the site in a shared payload or be compared
     * against a shared measurement.
     *
     * @param mixed $version
     * @return string|null The normalised version, or null when it is empty or invalid.
     */
    public static function shared($version) {
        $accepted = self::accept($version, self::SHARED_MAX);
        return '' === $accepted ? null : $accepted;
    }

    private static function accept($version, $max_length) {
        if (!is_scalar($version)) {
            return '';
        }
        $version = trim(sanitize_text_field((string) $version));
        if ('' === $version || strlen($version) > $max_length) {
            return '';
        }
        return preg_match('/^[0-9A-Za-z][0-9A-Za-z.+_-]*$/', $version) ? $version : '';
    }
}
