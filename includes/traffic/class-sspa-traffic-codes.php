<?php
defined('ABSPATH') || exit;

/** Stable numeric storage codes for the experimental traffic collector. */
class SSPA_Traffic_Codes {

    const OBSERVER_VERSION = 4;

    const COLLECTION_PLANNED = 1;
    const COLLECTION_RUNNING = 2;
    const COLLECTION_OUTCOME = 3;
    const COLLECTION_STOPPED = 4;
    const COLLECTION_INCOMPLETE = 5;
    const COLLECTION_COMPLETE = 6;
    const COLLECTION_FINALISING = 7;

    const REPORT_QUEUED = 1;
    const REPORT_AGGREGATING = 2;
    const REPORT_COMPLETE = 3;
    const REPORT_FAILED = 4;

    const QUALITY_UNAVAILABLE = 0;
    const QUALITY_EXACT = 1;
    const QUALITY_SAMPLED = 2;
    const QUALITY_INFERRED = 3;
    const QUALITY_LOWER_BOUND = 4;
    const QUALITY_PARTIAL = 5;

    const STOP_MANUAL = 1;
    const STOP_EXPIRED = 2;
    const STOP_EVENT_LIMIT = 3;
    const STOP_DATABASE_ERROR = 4;
    const STOP_DEACTIVATED = 5;
    const STOP_PLUGIN_UPDATE = 6;
    const STOP_EMERGENCY = 7;
    const STOP_DISK_LIMIT = 8;
    const STOP_OBSERVER_MISSING = 9;

    const EVENT_REQUEST = 1;
    const EVENT_BASKET_STARTED = 10;
    const EVENT_BASKET_EMPTIED = 11;
    const EVENT_CART_VIEWED = 12;
    const EVENT_CHECKOUT_STARTED = 13;
    const EVENT_PRE_EXISTING_BASKET = 14;
    const EVENT_ACTOR_ALIAS = 20;
    const EVENT_ORDER_CREATED = 30;
    const EVENT_PAYMENT_COMPLETED = 31;
    const EVENT_PAID_STATUS_REACHED = 32;
    const EVENT_ORDER_CANCELLED = 33;
    const EVENT_ORDER_REFUNDED = 34;

    const ACTOR_UNKNOWN = 0;
    const ACTOR_ANONYMOUS_NO_SESSION = 1;
    const ACTOR_ANONYMOUS_EMPTY_SESSION = 2;
    const ACTOR_GUEST_NON_EMPTY_BASKET = 3;
    const ACTOR_LOGGED_IN_NO_BASKET = 4;
    const ACTOR_LOGGED_IN_NON_EMPTY_BASKET = 5;
    const ACTOR_STAFF = 6;
    const ACTOR_AUTOMATED_CLAIMED = 7;

    const AUTOMATION_NOT_IDENTIFIED = 0;
    const AUTOMATION_CLAIMED_SEARCH = 1;
    const AUTOMATION_CLAIMED_SHOPPING = 2;
    const AUTOMATION_CLAIMED_GENERIC = 3;
    const AUTOMATION_TRUSTED_CLOUDFLARE = 4;

    const SURFACE_UNKNOWN = 0;
    const SURFACE_PUBLIC_CACHE_CANDIDATE = 1;
    const SURFACE_PUBLIC_PRIVATE = 2;
    const SURFACE_CART = 3;
    const SURFACE_CHECKOUT = 4;
    const SURFACE_ACCOUNT = 5;
    const SURFACE_WP_ADMIN = 6;
    const SURFACE_ADMIN_AJAX = 7;
    const SURFACE_REST_READ = 8;
    const SURFACE_REST_WRITE = 9;
    const SURFACE_WEBHOOK = 10;
    const SURFACE_WP_CRON = 11;
    const SURFACE_SITEMAP_OR_FEED = 12;
    const SURFACE_LOGIN_OR_AUTH = 13;

    const PAGE_UNKNOWN = 0;
    const PAGE_HOME = 1;
    const PAGE_ARCHIVE = 2;
    const PAGE_SINGLE = 3;
    const PAGE_SEARCH = 4;
    const PAGE_SHOP = 5;
    const PAGE_PRODUCT_ARCHIVE = 6;
    const PAGE_PRODUCT_SINGLE = 7;
    const PAGE_TAXONOMY = 8;
    const PAGE_OTHER_PUBLIC = 9;

    const SSF_PROTECTION_UNAVAILABLE = 0;
    const SSF_PRODUCT_ARCHIVE_ALLOWED = 1;
    const SSF_REDIRECT_NEAREST_ARCHIVE = 2;
    const SSF_REDIRECT_SHOP = 3;
    const SSF_PRODUCT_SINGLE_NOT_PROTECTABLE = 4;
    const SSF_UNRELATED_REQUEST = 5;

    const FLAG_EXACT = 1;
    const FLAG_SAMPLED = 2;
    const FLAG_CACHE_CANDIDATE = 4;
    const FLAG_LOGGED_IN = 8;
    const FLAG_NON_EMPTY_BASKET = 16;
    const FLAG_STAFF = 32;
    const FLAG_EXCLUDED_ADMIN = 64;
    const FLAG_EXCLUDED_API = 128;
    const FLAG_EXCLUDED_RENEWAL = 256;
    const FLAG_PRE_EXISTING_BASKET = 512;

    public static function collection_status($code) {
        return self::label($code, array(
            self::COLLECTION_PLANNED => 'planned',
            self::COLLECTION_RUNNING => 'running',
            self::COLLECTION_OUTCOME => 'outcome',
            self::COLLECTION_STOPPED => 'stopped',
            self::COLLECTION_INCOMPLETE => 'incomplete',
            self::COLLECTION_COMPLETE => 'complete',
            self::COLLECTION_FINALISING => 'finalising',
        ), 'unknown');
    }

    public static function stop_reason($code) {
        return self::label($code, array(
            self::STOP_MANUAL => 'manual',
            self::STOP_EXPIRED => 'expired',
            self::STOP_EVENT_LIMIT => 'event_limit',
            self::STOP_DATABASE_ERROR => 'database_error',
            self::STOP_DEACTIVATED => 'plugin_deactivated',
            self::STOP_PLUGIN_UPDATE => 'plugin_updated',
            self::STOP_EMERGENCY => 'emergency_stop',
            self::STOP_DISK_LIMIT => 'disk_limit',
            self::STOP_OBSERVER_MISSING => 'observer_missing',
        ), null);
    }

    public static function report_status($code) {
        return self::label($code, array(
            self::REPORT_QUEUED => 'queued',
            self::REPORT_AGGREGATING => 'aggregating',
            self::REPORT_COMPLETE => 'complete',
            self::REPORT_FAILED => 'failed',
        ), 'unknown');
    }

    public static function quality($code) {
        return self::label($code, array(
            self::QUALITY_UNAVAILABLE => 'unavailable',
            self::QUALITY_EXACT => 'exact',
            self::QUALITY_SAMPLED => 'sampled',
            self::QUALITY_INFERRED => 'inferred',
            self::QUALITY_LOWER_BOUND => 'lower_bound',
            self::QUALITY_PARTIAL => 'partial',
        ), 'unknown');
    }

    public static function event($code) {
        return self::label($code, array(
            self::EVENT_REQUEST => 'request',
            self::EVENT_BASKET_STARTED => 'basket_started',
            self::EVENT_BASKET_EMPTIED => 'basket_emptied',
            self::EVENT_CART_VIEWED => 'cart_viewed',
            self::EVENT_CHECKOUT_STARTED => 'checkout_started',
            self::EVENT_PRE_EXISTING_BASKET => 'pre_existing_basket_observed',
            self::EVENT_ACTOR_ALIAS => 'actor_alias',
            self::EVENT_ORDER_CREATED => 'order_created',
            self::EVENT_PAYMENT_COMPLETED => 'payment_completed',
            self::EVENT_PAID_STATUS_REACHED => 'paid_status_reached',
            self::EVENT_ORDER_CANCELLED => 'order_cancelled',
            self::EVENT_ORDER_REFUNDED => 'order_refunded',
        ), 'unknown');
    }

    public static function actor($code) {
        return self::label($code, array(
            self::ACTOR_UNKNOWN => 'unknown',
            self::ACTOR_ANONYMOUS_NO_SESSION => 'anonymous_no_session',
            self::ACTOR_ANONYMOUS_EMPTY_SESSION => 'anonymous_empty_session',
            self::ACTOR_GUEST_NON_EMPTY_BASKET => 'guest_non_empty_basket',
            self::ACTOR_LOGGED_IN_NO_BASKET => 'logged_in_no_basket',
            self::ACTOR_LOGGED_IN_NON_EMPTY_BASKET => 'logged_in_non_empty_basket',
            self::ACTOR_STAFF => 'staff',
            self::ACTOR_AUTOMATED_CLAIMED => 'automated_claimed',
        ), 'unknown');
    }

    public static function surface($code) {
        return self::label($code, array(
            self::SURFACE_UNKNOWN => 'unknown',
            self::SURFACE_PUBLIC_CACHE_CANDIDATE => 'public_html_get_head',
            self::SURFACE_PUBLIC_PRIVATE => 'public_html_private',
            self::SURFACE_CART => 'cart',
            self::SURFACE_CHECKOUT => 'checkout',
            self::SURFACE_ACCOUNT => 'account',
            self::SURFACE_WP_ADMIN => 'wp_admin',
            self::SURFACE_ADMIN_AJAX => 'admin_ajax',
            self::SURFACE_REST_READ => 'rest_read',
            self::SURFACE_REST_WRITE => 'rest_write',
            self::SURFACE_WEBHOOK => 'webhook',
            self::SURFACE_WP_CRON => 'wp_cron',
            self::SURFACE_SITEMAP_OR_FEED => 'sitemap_or_feed',
            self::SURFACE_LOGIN_OR_AUTH => 'login_or_auth',
        ), 'unknown');
    }

    public static function page_class($code) {
        return self::label($code, array(
            self::PAGE_UNKNOWN => 'unknown',
            self::PAGE_HOME => 'home',
            self::PAGE_ARCHIVE => 'content_archive',
            self::PAGE_SINGLE => 'content_single',
            self::PAGE_SEARCH => 'search',
            self::PAGE_SHOP => 'shop',
            self::PAGE_PRODUCT_ARCHIVE => 'product_archive',
            self::PAGE_PRODUCT_SINGLE => 'product_single',
            self::PAGE_TAXONOMY => 'public_taxonomy',
            self::PAGE_OTHER_PUBLIC => 'other_public',
        ), 'unknown');
    }

    public static function automation($code) {
        return self::label($code, array(
            self::AUTOMATION_NOT_IDENTIFIED => 'not_identified_as_automation',
            self::AUTOMATION_CLAIMED_SEARCH => 'claimed_search_crawler',
            self::AUTOMATION_CLAIMED_SHOPPING => 'claimed_shopping_crawler',
            self::AUTOMATION_CLAIMED_GENERIC => 'claimed_generic_crawler',
            self::AUTOMATION_TRUSTED_CLOUDFLARE => 'trusted_cloudflare_bot',
        ), 'unknown');
    }

    public static function ssf_protection($code) {
        return self::label($code, array(
            self::SSF_PROTECTION_UNAVAILABLE => 'unavailable',
            self::SSF_PRODUCT_ARCHIVE_ALLOWED => 'product_archive_allowed',
            self::SSF_REDIRECT_NEAREST_ARCHIVE => 'redirect_to_nearest_archive',
            self::SSF_REDIRECT_SHOP => 'redirect_to_shop',
            self::SSF_PRODUCT_SINGLE_NOT_PROTECTABLE => 'product_single_not_protectable',
            self::SSF_UNRELATED_REQUEST => 'unrelated_request',
        ), 'unknown');
    }

    public static function ssf_protectable($code) {
        return in_array((int) $code, array(
            self::SSF_REDIRECT_NEAREST_ARCHIVE,
            self::SSF_REDIRECT_SHOP,
        ), true);
    }

    private static function label($code, $map, $fallback) {
        $code = (int) $code;
        return array_key_exists($code, $map) ? $map[$code] : $fallback;
    }
}
