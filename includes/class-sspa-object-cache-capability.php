<?php
defined('ABSPATH') || exit;

/** Classifies whether SSPA can safely disable the site's persistent object cache. */
class SSPA_Object_Cache_Capability {

    /**
     * @return array {persistent:bool,switchable:bool,category:string,reason:string}
     */
    public static function inspect() {
        $persistent = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
        $dropin = WP_CONTENT_DIR . '/object-cache.php';
        $has_dropin = file_exists($dropin);
        $source = '';

        if (function_exists('wp_cache_init')) {
            try {
                $source = (string) (new ReflectionFunction('wp_cache_init'))->getFileName();
            } catch (ReflectionException $e) {
                $source = '';
            }
        }

        $content = self::normalise(realpath(WP_CONTENT_DIR) ?: WP_CONTENT_DIR);
        $core = self::normalise(realpath(ABSPATH . WPINC) ?: ABSPATH . WPINC);
        $source = self::normalise($source);

        if (!$persistent && (!$source || self::inside($source, $core))) {
            return array(
                'persistent' => false,
                'switchable' => false,
                'category' => 'none',
                'reason' => __('No persistent object cache is active.', 'super-speedy-performance-analysis'),
            );
        }

        if ($source && self::inside($source, $content) && $has_dropin) {
            return array(
                'persistent' => true,
                'switchable' => true,
                'category' => 'wordpress-dropin',
                'reason' => '',
            );
        }

        if ($source && !self::inside($source, $content) && !self::inside($source, $core)) {
            return array(
                'persistent' => true,
                'switchable' => false,
                'category' => 'platform-managed',
                'reason' => __('The persistent object cache is supplied by the hosting platform outside wp-content, so Performance Analysis will not try to disable it. Cache-off comparisons are skipped; normal profiling still works.', 'super-speedy-performance-analysis'),
            );
        }

        return array(
            'persistent' => (bool) ($persistent || $has_dropin),
            'switchable' => false,
            'category' => 'unknown',
            'reason' => __('A persistent object cache is active, but Performance Analysis cannot prove that its WordPress drop-in is safe to disable. Cache-off comparisons are skipped; normal profiling still works.', 'super-speedy-performance-analysis'),
        );
    }

    private static function normalise($path) {
        return rtrim(str_replace('\\', '/', (string) $path), '/');
    }

    private static function inside($file, $dir) {
        return $file && $dir && 0 === strpos($file . '/', $dir . '/');
    }
}
