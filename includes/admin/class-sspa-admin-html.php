<?php
defined('ABSPATH') || exit;

/** Markup emitted by PA's returned admin fragments. Scripts are excluded; styles use the WordPress CSS allowlist. */
class SSPA_Admin_HTML {
    public static function allowed_tags() {
        $tags = wp_kses_allowed_html('post');
        $attributes = array_fill_keys(array('class', 'id', 'title', 'role', 'hidden', 'tabindex', 'aria-*', 'data-*', 'style'), true);
        foreach ($tags as $tag => $allowed) { $tags[$tag] = array_merge($allowed, $attributes); }
        foreach (array('section', 'div', 'span', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'details', 'summary', 'label', 'fieldset', 'legend') as $tag) {
            $tags[$tag] = array_merge($tags[$tag] ?? array(), $attributes);
        }
        foreach (array('button', 'input', 'select', 'option') as $tag) {
            $tags[$tag] = array_merge($attributes, array_fill_keys(array('type', 'name', 'value', 'checked', 'selected', 'disabled', 'placeholder', 'multiple'), true));
        }
        return $tags;
    }
}
