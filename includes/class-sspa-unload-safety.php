<?php
defined('ABSPATH') || exit;

/**
 * Per-plugin unload-safety classification: may this plugin ever be prevented from
 * loading on a front-end page? Consumed by SSPA_Report::page_plugin_usage(), and
 * through that by Scalability Pro's Unload Plugins tab.
 *
 * The ladder (same shape as the HTTP API scan contract's block_safety):
 *
 *   'never'     category- or dependency-protected. Never offered for unloading, whatever
 *               the measurements say. Includes the plugins whose entire JOB is to look
 *               idle on most pages (membership walls, consent banners): "no measurable
 *               impact" is their normal operating state, not evidence of safety.
 *   'review'    nothing known to forbid it, but no category clears it either. Only a
 *               human decision (backed by measured evidence) may unload it.
 *   'candidate' nothing category-protected AND the plugin belongs to a category whose
 *               members are page-scoped by nature (forms, sliders, tables ...). Still
 *               only unloaded on measured evidence - candidate means eligible, not safe.
 *
 * A classifier failure must land on 'never' or 'review', never on 'candidate'. Unknown
 * plugins are 'review'.
 *
 * Category data lives in rules/rules-snapshot.json (overlayable from the signed
 * community feed), so the lists grow without a plugin release and every consumer -
 * this plugin, Scalability Pro, future tooling - classifies from ONE source.
 */
class SSPA_Unload_Safety {

    const SCHEMA = 1;

    /** Categories whose members must never be unloaded from a front-end request. */
    private static $never_categories = array(
        // Money and order fulfilment.
        'payments', 'commerce-core',
        // Their job is to block/act on requests, so idleness is not evidence.
        'security', 'membership', 'consent', 'anti-spam',
        // They act on every request's routing, headers or language.
        'multilingual', 'redirects',
        // They must observe the request to serve or populate caches.
        'page-cache',
        // The theme depends on them; unloading breaks rendering.
        'page-builder',
    );

    /** Categories that make a plugin an unload candidate rather than review-only. */
    private static $candidate_categories = array('contact-form', 'seo', 'backup');

    /**
     * @param string $slug Plugin directory slug.
     * @return array {classification: 'never'|'review'|'candidate', reasons: string[]}
     */
    public static function classify($slug) {
        $slug = (string) $slug;
        $reasons = array();

        if ('super-speedy-performance-analysis' === $slug || 'scalability-pro' === $slug) {
            return array('classification' => 'never', 'reasons' => array('self'));
        }

        $by_category = self::category_index();
        $category = isset($by_category[$slug]) ? $by_category[$slug] : null;

        if (in_array($slug, SSPA_Rules::fragile(), true)) {
            $reasons[] = 'fragile';
        }
        if ($category && in_array($category, self::$never_categories, true)) {
            $reasons[] = 'category:' . $category;
        }

        // Dependency contagion: unloading this plugin takes its group with it, so a group
        // containing a protected plugin protects the root. Same rule the deep sweep uses
        // for isolation candidates.
        if (!$reasons) {
            $groups = SSPA_Dependency_Map::must_exclude_together();
            if (isset($groups[$slug])) {
                foreach ($groups[$slug] as $member) {
                    $member_cat = isset($by_category[$member]) ? $by_category[$member] : null;
                    if (in_array($member, SSPA_Rules::fragile(), true)
                        || ($member_cat && in_array($member_cat, self::$never_categories, true))) {
                        $reasons[] = 'group-member:' . $member;
                        break;
                    }
                }
            }
        }

        if ($reasons) {
            return array('classification' => 'never', 'reasons' => $reasons);
        }
        if ($category && in_array($category, self::$candidate_categories, true)) {
            return array('classification' => 'candidate', 'reasons' => array('category:' . $category));
        }
        return array('classification' => 'review', 'reasons' => array($category ? 'category:' . $category : 'unknown-category'));
    }

    /** slug => category name, inverted from the rules snapshot. */
    private static function category_index() {
        static $index = null;
        if (null === $index) {
            $index = array();
            foreach (SSPA_Rules::categories() as $category => $slugs) {
                foreach ((array) $slugs as $slug) {
                    $index[$slug] = $category;
                }
            }
        }
        return $index;
    }

    /**
     * Plugin FILES (dir/file.php) that must never be unloaded, for defence in depth in a
     * consumer's own enforcement layer.
     */
    public static function never_files() {
        $files = array();
        foreach (SSPA_Dependency_Map::slug_to_file() as $slug => $file) {
            $verdict = self::classify($slug);
            if ('never' === $verdict['classification']) {
                $files[] = $file;
            }
        }
        return $files;
    }
}
