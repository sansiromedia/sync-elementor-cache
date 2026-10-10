<?php
/**
 * Plugin Name:       Sync Elementor Cache
 * Plugin URI:        https://github.com/sansiromedia/sync-elementor-cache
 * Description:       Keeps Elementor in sync with WP Rocket and/or SiteGround Optimizer so logged-out visitors don't see stale CSS after editor saves, library template changes, or plugin updates. Auto-detects which caching layers are present and adapts.
 * Version:           4.7.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Pip Baddock
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sync-elementor-cache
 * Update URI:        https://github.com/sansiromedia/sync-elementor-cache
 *
 * Canonical source: https://github.com/sansiromedia/sync-elementor-cache
 * Distilled from the v4 WPCode snippet pattern documented at
 * ~/pip-ai/skills/elementor-rocket-cache-sync.md.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SEC_PLUGIN_FILE',    __FILE__ );
define( 'SEC_PLUGIN_DIR',     plugin_dir_path( __FILE__ ) );
define( 'SEC_PLUGIN_VERSION', '4.7.0' );
define( 'SEC_PLUGIN_SLUG',    'sync-elementor-cache' );

// ---------------------------------------------------------------------------
// Self-hosted updates via Plugin Update Checker (GitHub releases).
// ---------------------------------------------------------------------------
require_once SEC_PLUGIN_DIR . 'includes/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$sec_update_checker = PucFactory::buildUpdateChecker(
    'https://github.com/sansiromedia/sync-elementor-cache/',
    __FILE__,
    SEC_PLUGIN_SLUG
);
$sec_update_checker->getVcsApi()->enableReleaseAssets();
$sec_update_checker->setBranch( 'main' );

// ---------------------------------------------------------------------------
// Detector — figure out which cache layers are present at runtime.
// ---------------------------------------------------------------------------
final class SEC_Detector {

    public static function has_rocket() {
        return function_exists( 'rocket_clean_domain' );
    }

    public static function has_sg_soft() {
        return function_exists( 'sg_cachepress_purge_cache' );
    }

    public static function has_sg_nuclear() {
        return function_exists( 'sg_cachepress_purge_everything' );
    }

    public static function has_elementor() {
        return class_exists( '\Elementor\Plugin' );
    }

    public static function summary() {
        return array(
            'elementor'   => self::has_elementor(),
            'wp_rocket'   => self::has_rocket(),
            'sg_soft'     => self::has_sg_soft(),
            'sg_nuclear'  => self::has_sg_nuclear(),
        );
    }

    /**
     * Scan for known footgun combinations and return zero-or-more
     * "you might want to fix this" recommendations. Each is informational —
     * the plugin never auto-changes a host setting.
     *
     * @return array<int, array{level:string, title:string, body:string, fix:string}>
     */
    public static function recommendations() {
        $recs = array();

        // Footgun 1: Elementor 4.x + internal CSS print method.
        // Real-world: on SG sites this leaves per-post CSS sitting in
        // _elementor_css postmeta with status:inline but never printed
        // for most posts → broken layout after any cache flush. Less acute
        // on cPanel but still worth eliminating to make sites resilient
        // to future Elementor flushes.
        if ( self::has_elementor() ) {
            $print_method = get_option( 'elementor_css_print_method' );

            // Elementor defines ELEMENTOR_VERSION in the GLOBAL namespace, not
            // under \Elementor\. v4.2.0–v4.3.0 looked it up as
            // \Elementor\ELEMENTOR_VERSION, which never resolves — $el_version
            // was always '' so this whole check silently never fired on any
            // site. Found 2026-08-03 on shirleyyeung.com.au, which was running
            // Elementor 4.2.1 + 'internal' and still reported "healthy".
            $el_version    = defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '';
            $is_elementor4 = $el_version && version_compare( $el_version, '4.0.0', '>=' );

            if ( $print_method === 'internal' && $is_elementor4 ) {
                $recs[] = array(
                    'level' => self::has_sg_soft() ? 'critical' : 'warning',
                    'title' => sprintf(
                        /* translators: %s = Elementor version */
                        __( 'Elementor %s + "internal" CSS print method is a known issue', 'sync-elementor-cache' ),
                        esc_html( $el_version )
                    ),
                    'body'  => __(
                        'Elementor 4.x silently fails to inline per-post CSS for some posts when print method is "internal" — the CSS is generated and saved to <code>_elementor_css</code> postmeta with status <code>inline</code>, but Elementor never writes it into the page. Symptom: anonymous visitors see broken layouts on header / archive / template-using pages, especially right after a cache flush. The condition is much more aggressive on SiteGround. Recommended setting on all Elementor 4.x sites: <strong>external</strong> (Elementor writes per-post CSS files to <code>wp-content/uploads/elementor/css/</code> and includes them via <code>&lt;link&gt;</code> tags — reliable).',
                        'sync-elementor-cache'
                    ),
                    'fix'   => __(
                        'In WordPress admin: Elementor → Settings → Advanced → CSS Print Method → External File. After saving, Elementor → Tools → Regenerate Files & Data, then come back here and click "Purge everything".',
                        'sync-elementor-cache'
                    ),
                );
            }
        }

        // Footgun 2: WP Rocket "Remove Unused CSS" on Elementor sites.
        // Strips dynamic-state CSS (mega-menu open, popup visible, etc.)
        // because those selectors aren't on the page at RUCSS scan time.
        if ( self::has_rocket() && self::has_elementor() ) {
            $rocket_settings = get_option( 'wp_rocket_settings', array() );
            $rucss           = ! empty( $rocket_settings['remove_unused_css'] ) ? 1 : 0;
            $rucss_mobile    = ! empty( $rocket_settings['remove_unused_css_mobile'] ) ? 1 : 0;

            if ( $rucss || $rucss_mobile ) {
                $recs[] = array(
                    'level' => 'critical',
                    'title' => __( 'WP Rocket "Remove Unused CSS" is enabled — known to break Elementor dynamic widgets', 'sync-elementor-cache' ),
                    'body'  => __(
                        'RUCSS scans the rendered HTML to figure out which CSS selectors are "used" and strips the rest. Selectors that only become active on user interaction — mega-menu open states (<code>.e-n-menu-toggle[aria-expanded="true"]</code>), popup-visible classes, slider active slides, accordion expanded states — all look "unused" to RUCSS at scan time and get removed. Result: hamburger menus that look dead, popups that don\'t open, sliders stuck on the first slide. Logged-in admins bypass Rocket entirely so the symptom is incognito-only and easy to miss.',
                        'sync-elementor-cache'
                    ),
                    'fix'   => __(
                        'In WordPress admin: WP Rocket → File Optimization → CSS Files → uncheck "Remove Unused CSS" (both desktop and mobile if separately enabled). The performance loss is roughly 20-50 KB of unused CSS per page; the reliability gain is dynamic widgets that actually work.',
                        'sync-elementor-cache'
                    ),
                );
            }
        }

        return $recs;
    }
}

// ---------------------------------------------------------------------------
// Purger — the actual cache-clearing logic. Pure functions, no side effects
// beyond hitting the detected cache layers.
// ---------------------------------------------------------------------------
final class SEC_Purger {

    /** Upper bound on documents regenerated synchronously inside a purge. */
    const MAX_GLOBAL_REGEN = 60;

    /**
     * Wall-clock budget for synchronous regeneration, in seconds.
     *
     * A large mega-panel can take ~350ms to render its CSS, so a count-based cap alone is not a
     * safety net - 250 documents would be ~90s and blow max_execution_time, turning a save into a
     * 500. Whatever is not reached inside the budget falls back to Elementor's own lazy
     * regeneration, which is exactly the pre-4.4.0 behaviour: correct, just not pre-warmed.
     */
    const REGEN_TIME_BUDGET = 10;


    private static $purging = false;

    /** Purge scopes. */
    const SCOPE_POST = 'post';
    const SCOPE_SITE = 'site';

    /** Cron hook for the coalesced trailing purge. */
    const HOOK_DEFERRED = 'sec_run_deferred_purge';

    /**
     * Pending-purge rows live in wp_options as one row per item, named
     * sec_pending_{post id} or sec_pending_site, value = time() it was booked.
     *
     * One row per item (not one shared array) so concurrent requests can never overwrite each
     * other's entries, and run_deferred() only deletes the rows it actually read. They are read
     * and written with direct SQL, never get_option(): a persistent object cache (SG memcached)
     * can serve a stale option long after the DB changed (Provenir, 2026-10-10).
     */
    const PENDING_PREFIX = 'sec_pending_';

    /** Past this many distinct deferred posts, one site-wide purge is cheaper. */
    const DEFER_COLLAPSE_AT = 20;

    /** Whether a shutdown flush is already registered for this request. */
    private static $shutdown_registered = false;

    /**
     * Purges collected during this (browser) request, run once at shutdown.
     *
     * One Elementor template save fires three hooks, and the first (save_post_elementor_library,
     * inside wp_update_post) fires BEFORE Elementor deletes the old CSS and element cache. Purging
     * there leaves a gap in which a visitor render can re-cache the old markup. Running at
     * shutdown - after the last write of the request - closes it, collapses the three hooks into
     * one purge, and covers a request that saves several templates.
     */
    private static $request_items = array();

    /** Whether the request-end purge is registered / has already run this request. */
    private static $request_flush_registered = false;
    private static $request_flush_ran        = false;

    /** Hook that originally asked for a purge collected for shutdown (for "Triggered by"). */
    private static $origin_hook = '';

    /** Lock row (deliberately NOT under PENDING_PREFIX). */
    const LOCK_NAME = 'sec_deferred_lock';

    /** A lock older than this is treated as abandoned (fatal mid-purge). */
    const LOCK_TTL = 300;

    /**
     * Public entry point. Signature-compatible with <= 4.6.x: an id means "one page
     * changed", null means "something global changed".
     *
     * v4.7.0 no longer purges unconditionally - a request may run now or be folded
     * into a single trailing purge. See request_purge().
     */
    public static function purge_all( $post_id = null ) {
        $post_id = $post_id ? (int) $post_id : null;
        self::request_purge( $post_id ? self::SCOPE_POST : self::SCOPE_SITE, $post_id );
    }

    /**
     * Site-wide purge caused by saving ONE document (template, popup, font, Additional CSS,
     * WPCode snippet). The purge is domain-wide, but the throttle is keyed to that document,
     * so saving the footer is never held back because the header was saved a moment ago.
     */
    public static function purge_site_for( $source_post_id ) {
        self::request_purge( self::SCOPE_SITE, null, (int) $source_post_id );
    }

    /**
     * Decide whether to purge now, later, or not at all:
     *
     *   1. sec_should_purge - the host site can veto. No-op by default.
     *   2. inside purge_site() - nothing to do, its own site-wide execute() covers it.
     *   3. non-interactive context (WP-CLI, cron) - collect, then purge once when the
     *      process ends. Nobody is watching a screen, and bulk writers live here.
     *   4. rate window - the first save of each page (or of site-wide CSS) purges at the end
     *      of the same request (run_request_purges, after the last write), because an editor
     *      expects to see their change. Repeat saves of the SAME item inside the window fold
     *      into one trailing purge.
     */
    public static function request_purge( $scope, $post_id = null, $throttle_id = null ) {

        if ( ! apply_filters( 'sec_should_purge', true, $scope, $post_id ) ) {
            return;
        }

        if ( self::$purging ) {
            return;
        }

        if ( ! self::is_interactive() ) {
            // Flushed at process end; no WP-Cron event, so a visitor-spawned cron run cannot
            // start purging half-way through a long bulk job.
            self::defer( $scope, $post_id, false );
            self::flush_on_shutdown();
            return;
        }

        // Already collected in this request (one save, several hooks): nothing more to do.
        $item = self::SCOPE_POST === $scope && $post_id ? (int) $post_id : 'site';
        if ( isset( self::$request_items[ $item ] ) ) {
            return;
        }

        if ( self::is_throttled( $scope, $post_id, $throttle_id ) ) {
            self::defer( $scope, $post_id );
            return;
        }

        // Asked for after our shutdown flush already ran (another plugin writing at
        // shutdown): there is no later point in this request, so purge now.
        if ( self::$request_flush_ran ) {
            self::execute( $post_id, $scope, false );
            return;
        }

        self::$request_items[ $item ] = current_filter();
        if ( ! self::$request_flush_registered ) {
            self::$request_flush_registered = true;
            add_action( 'shutdown', array( __CLASS__, 'run_request_purges' ), 0 );
        }
    }

    /** Shutdown: run what this request collected, once, after all of its writes. */
    public static function run_request_purges() {
        self::$request_flush_ran = true;
        $items = self::$request_items;
        self::$request_items = array();
        if ( ! $items ) {
            return;
        }
        self::$origin_hook = (string) reset( $items );
        try {
            if ( isset( $items['site'] ) || count( $items ) > self::DEFER_COLLAPSE_AT ) {
                self::execute( null, self::SCOPE_SITE, false );
            } else {
                self::execute_posts( array_keys( $items ) );
            }
        } finally {
            self::$origin_hook = '';
        }
    }

    /** WP-CLI and cron are bulk contexts by nature - never purge per write there. */
    private static function is_interactive() {
        $interactive = true;
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            $interactive = false;
        } elseif ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
            $interactive = false;
        }
        return (bool) apply_filters( 'sec_is_interactive', $interactive );
    }

    /**
     * Debounce window, seconds. Short on purpose: it exists to absorb bursts (a save
     * clicked twice, a plugin re-saving in a loop), not to delay an editor's second
     * deliberate save. Bulk writers (WP-CLI, cron) are coalesced separately. Filter to 0
     * to restore <= 4.6.x immediate behaviour.
     */
    private static function window() {
        return max( 0, (int) apply_filters( 'sec_purge_window', 15 ) );
    }

    /**
     * Leading-edge throttle, keyed per post (or 'site'), so saving page Y is never held
     * back because page X was saved a moment ago. A transient: self-expiring, and on sites
     * with a persistent object cache it never touches the database.
     */
    private static function is_throttled( $scope, $post_id = null, $throttle_id = null ) {
        $window = self::window();
        if ( $window <= 0 ) {
            return false;
        }
        if ( $throttle_id ) {
            $key = 'sec_thr_' . (int) $throttle_id;
        } else {
            $key = 'sec_thr_' . ( self::SCOPE_POST === $scope && $post_id ? (int) $post_id : 'site' );
        }
        if ( get_transient( $key ) ) {
            return true;
        }
        set_transient( $key, 1, $window );
        return false;
    }

    /**
     * Fold this request into the pending set and book a trailing purge.
     *
     * Row value is "{first booked time}:{token}". A new item inserts; re-booking an existing
     * item keeps the first time (the overdue check needs the oldest) but changes the token, so
     * a run that read the old value will not delete it (see delete_pending()). Single atomic
     * statements on a unique key, so concurrent requests cannot lose each other's bookings.
     */
    public static function defer( $scope, $post_id = null, $schedule = true ) {
        global $wpdb;

        $name  = self::PENDING_PREFIX . ( self::SCOPE_POST === $scope && $post_id ? (int) $post_id : 'site' );
        $token = str_replace( '.', '', uniqid( '', true ) );

        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')
             ON DUPLICATE KEY UPDATE option_value = CONCAT( SUBSTRING_INDEX( option_value, ':', 1 ), ':', %s )",
            $name,
            time() . ':' . $token,
            $token
        ) );

        if ( $schedule && ! wp_next_scheduled( self::HOOK_DEFERRED ) ) {
            wp_schedule_single_event( time() + max( 30, self::window() ), self::HOOK_DEFERRED );
        }
    }

    /** Pending rows straight from the DB: name => raw value ("{time}:{token}"). */
    private static function pending_rows() {
        global $wpdb;
        $like = $wpdb->esc_like( self::PENDING_PREFIX ) . '%';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
            $like
        ) );
        $out = array();
        foreach ( (array) $rows as $row ) {
            $out[ $row->option_name ] = (string) $row->option_value;
        }
        return $out;
    }

    /**
     * Run the coalesced purge booked by defer(). Claims exactly the rows it read, so a
     * defer() landing mid-run survives for the next run instead of being deleted unseen.
     */
    public static function run_deferred() {
        global $wpdb;

        if ( ! self::pending_rows() ) {
            return;
        }

        // One runner at a time: the overdue fallback and cron can otherwise start several full
        // domain purges in parallel. INSERT IGNORE is atomic; an abandoned lock expires.
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d",
            self::LOCK_NAME,
            time() - self::LOCK_TTL
        ) );
        $got = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            self::LOCK_NAME,
            (string) time()
        ) );
        if ( 1 !== (int) $got ) {
            // Another runner holds the lock. If WP-Cron already consumed our event, make
            // sure something will come back for rows that runner did not read.
            if ( ! wp_next_scheduled( self::HOOK_DEFERRED ) ) {
                wp_schedule_single_event( time() + max( 30, self::window() ), self::HOOK_DEFERRED );
            }
            return;
        }

        try {
            self::run_deferred_locked();
        } finally {
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_NAME ) );
        }
    }

    private static function run_deferred_locked() {
        $rows = self::pending_rows();
        if ( ! $rows ) {
            return;
        }

        $names    = array_keys( $rows );
        $site_key = self::PENDING_PREFIX . 'site';
        $post_ids = array();
        foreach ( $names as $name ) {
            if ( $name !== $site_key ) {
                $post_ids[] = (int) substr( $name, strlen( self::PENDING_PREFIX ) );
            }
        }
        $post_ids = array_filter( $post_ids );

        if ( isset( $rows[ $site_key ] ) || count( $post_ids ) > self::DEFER_COLLAPSE_AT ) {
            self::execute( null, self::SCOPE_SITE, false );
        } else {
            self::execute_posts( $post_ids );
        }

        // Delete only after the purge ran, so a fatal mid-purge leaves the work pending
        // (at-least-once: a repeated purge is harmless, a lost one is not).
        self::delete_pending( $rows );
    }

    /**
     * Compare-and-delete: remove each row only if it still holds the value that was read.
     * A row re-booked (new token) or created after the read survives for the next run.
     *
     * @param array $rows name => value, as returned by pending_rows().
     */
    private static function delete_pending( array $rows ) {
        global $wpdb;
        foreach ( $rows as $name => $value ) {
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
                $name,
                $value
            ) );
        }
    }

    /**
     * WP-CLI / cron: purge once when this process ends, not once per write, and without
     * depending on WP-Cron ever running (DISABLE_WP_CRON with no system cron is common).
     */
    private static function flush_on_shutdown() {
        if ( self::$shutdown_registered ) {
            return;
        }
        self::$shutdown_registered = true;
        add_action( 'shutdown', array( __CLASS__, 'run_deferred' ), 0 );
    }

    /**
     * Fallback for the trailing purge of throttled interactive saves when WP-Cron does not
     * run: any admin request runs pending work that is overdue by a full window.
     */
    public static function maybe_run_overdue() {
        // admin_init also fires for every admin-ajax.php hit, front-end ones included.
        if ( wp_doing_ajax() ) {
            return;
        }
        $rows = self::pending_rows();
        if ( ! $rows ) {
            return;
        }
        $oldest = min( array_map( 'intval', $rows ) ); // (int) "1791...:token" = the time
        if ( time() - $oldest >= max( 30, self::window() ) + 30 ) {
            self::run_deferred();
        }
    }

    /**
     * Do the actual cache clearing.
     *
     * SCOPE_POST: per-page Rocket purge and post-cache invalidation only. v4.6.x also called
     * the three site-wide Rocket CSS cleaners (minify / cache-busting / used-CSS) plus
     * wp_cache_flush() on EVERY save, so one editor saving one page wiped the entire object
     * cache and every minified bundle - on memcached/Redis sites a thundering herd back to
     * MySQL. SG's dynamic-cache soft purge stays domain-wide as in 4.6.x: it is page cache
     * only, and on SG-only sites it is the only thing that refreshes the home page, archives
     * and listing grids that show the saved post.
     *
     * SCOPE_SITE: shared bundles too. A full object-cache flush is reserved for purge_site().
     *
     * @param int|null $post_id            Post, for SCOPE_POST.
     * @param string   $scope              SCOPE_POST | SCOPE_SITE.
     * @param bool     $flush_object_cache Nuclear path only.
     */
    public static function execute( $post_id, $scope, $flush_object_cache = false ) {

        if ( self::SCOPE_POST === $scope && $post_id ) {
            self::execute_posts( array( (int) $post_id ) );
            return;
        }

        if ( function_exists( 'rocket_clean_domain' ) ) {
            rocket_clean_domain();
        }
        if ( function_exists( 'rocket_clean_minify' ) ) {
            rocket_clean_minify( 'css' );
        }
        if ( function_exists( 'rocket_clean_cache_busting' ) ) {
            rocket_clean_cache_busting();
        }
        if ( function_exists( 'rocket_clean_used_css' ) ) {
            rocket_clean_used_css();
        }
        if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
            sg_cachepress_purge_cache();
        }

        if ( $flush_object_cache ) {
            wp_cache_flush();
        }

        self::stamp_last_purge( $scope, null );
    }

    /**
     * Rocket's per-post purge, without rocket_clean_post()'s once-per-request guard.
     *
     * Rocket hooks rocket_clean_post() to clean_post_cache, so during an Elementor save it has
     * already run (inside wp_update_post, BEFORE Elementor deletes the old CSS) and its static
     * $done makes any later call in the same request a no-op. Our shutdown purge must really
     * purge, so call the same building blocks directly. Falls back to rocket_clean_post().
     */
    private static function rocket_purge_post( $pid ) {
        if ( ! function_exists( 'rocket_get_purge_urls' ) || ! function_exists( 'rocket_clean_files' ) ) {
            if ( function_exists( 'rocket_clean_post' ) ) {
                rocket_clean_post( $pid );
            }
            return;
        }
        // Mirrors WP Rocket 3.23 rocket_clean_post() step for step - same skips, language, filters
        // and before/after actions (term/date archives, preload, Sucuri, Cloudflare, host caches
        // listen to those) - minus only its once-per-request static guard.
        if ( function_exists( 'rocket_is_importing' ) && rocket_is_importing() ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) ) {
            return;
        }
        $post = get_post( $pid );
        if ( ! is_object( $post ) ) {
            return;
        }
        if ( 'auto-draft' === $post->post_status || 'draft' === $post->post_status || empty( $post->post_type ) || 'nav_menu_item' === $post->post_type || 'attachment' === $post->post_type ) {
            return;
        }
        $type = get_post_type_object( $post->post_type );
        if ( ! is_object( $type ) || true !== $type->public ) {
            return;
        }

        $lang = '';
        $i18n = function_exists( 'rocket_has_i18n' ) ? rocket_has_i18n() : false;
        if ( 'wpml' === $i18n && isset( $GLOBALS['sitepress'] ) && function_exists( 'rocket_is_plugin_active' ) && ! rocket_is_plugin_active( 'woocommerce-multilingual/wpml-woocommerce.php' ) ) {
            $lang = $GLOBALS['sitepress']->get_language_for_element( $pid, 'post_' . get_post_type( $pid ) );
        } elseif ( 'polylang' === $i18n && function_exists( 'pll_get_post_language' ) ) {
            $lang = pll_get_post_language( $pid );
        }

        $urls = rocket_get_purge_urls( $pid, $post );
        do_action( 'before_rocket_clean_post', $post, $urls, $lang ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
        $urls = apply_filters( 'rocket_post_purge_urls', $urls, $post ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
        rocket_clean_files( $urls );

        $home_after = apply_filters( 'rocket_clean_home_after_clean_post', true, $pid ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
        if ( $home_after && function_exists( 'rocket_clean_home' ) ) {
            rocket_clean_home( $lang );
        }
        if ( function_exists( 'rocket_clean_home_feeds' ) && false !== has_filter( 'rocket_cache_reject_uri', 'wp_rocket_cache_feed' ) ) {
            rocket_clean_home_feeds();
        }

        $urls = (array) apply_filters( 'after_rocket_clean_post_urls', $urls, $post ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
        do_action( 'after_rocket_clean_post', $post, $urls, $lang ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
    }

    /** SCOPE_POST for one or more posts: per-post work in a loop, one SG soft purge total. */
    public static function execute_posts( array $post_ids ) {
        $post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
        if ( ! $post_ids ) {
            return;
        }

        foreach ( $post_ids as $pid ) {
            self::rocket_purge_post( $pid );
            // Targeted object-cache invalidation. Also makes Rocket's own
            // clean_post_cache subscribers drop this post's used-CSS.
            clean_post_cache( $pid );
        }

        if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
            sg_cachepress_purge_cache();
        }

        self::stamp_last_purge( self::SCOPE_POST, count( $post_ids ) === 1 ? $post_ids[0] : null );
    }

    /**
     * Site-wide (high-impact) purge. Used for library template saves, kit
     * changes, plugin/theme updates, manual ?sec_purge_all=1.
     * Forces Elementor to regen ALL per-post CSS, then NUKES every cache
     * including SG's full-page cache (where cached error responses live).
     */
    public static function purge_site() {
        if ( self::$purging ) {
            return; // re-entrancy guard — Elementor's clear_cache fires our own hook
        }
        self::$purging = true;

        // Snapshot what is pending now: this purge covers it. Anything booked while it runs
        // is NOT covered and must survive (compare-and-delete below).
        $covered_rows        = self::pending_rows();
        self::$request_items = array();

        // 1. Wipe Elementor's per-post CSS files. They'll regen on next page hit.
        if ( class_exists( '\Elementor\Plugin' ) && ! empty( \Elementor\Plugin::$instance->files_manager ) ) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
        }

        // 1b. Close the regeneration window (v4.4.0).
        //     clear_cache() above deletes EVERY Elementor CSS file on a live site and
        //     leaves regeneration to the next render of each page. For ordinary posts
        //     that is fine — the first visitor pays and the file is written correctly.
        //
        //     It is NOT fine for the global set: the active kit, and the theme-builder
        //     templates that are actually in use. Those appear on every page, so between
        //     the delete and the first render their CSS URLs 404 and pages render
        //     unstyled. Logged-out visitors are shielded by the page cache (rebuilt only
        //     after regeneration completes), but logged-in users get a live render with
        //     no shield — so whoever runs a plugin update and then clicks around is
        //     precisely who sees the broken layout.
        //
        //     Measured on businessassist.net 2026-08-30: a ~14s window (22:52:53 ->
        //     22:53:07 UTC) after a four-plugin update batch.
        //
        //     Regenerating the global set synchronously here removes that window. The
        //     set is small — kit + in-use headers/footers, 3 templates on that site
        //     against 836 Elementor-built posts — so this stays cheap.
        self::regenerate_global_css();

        // 2. Standard site-wide cache layer wipe, plus the full object-cache flush.
        //    Explicit execute() - this path must never be throttled or deferred.
        self::execute( null, self::SCOPE_SITE, true );

        // 3. SG NUCLEAR purge — clears cached error responses (403, 5xx) too.
        //    Documented as v4 in the central skill: a WAF rate-limit can leave
        //    a 403 cached and the soft purge above can't touch it.
        if ( function_exists( 'sg_cachepress_purge_everything' ) ) {
            sg_cachepress_purge_everything();
        }

        self::delete_pending( $covered_rows );
        if ( ! self::pending_rows() ) {
            wp_clear_scheduled_hook( self::HOOK_DEFERRED );
        }

        self::stamp_last_purge( 'site', null );
        self::$purging = false;
    }

    /**
     * Rebuild the Elementor CSS that every page depends on, synchronously.
     *
     * Deliberately NOT every Elementor post — that would be hundreds of files and
     * would make purge_site() unusable inside a request. Only the set whose absence
     * breaks layout site-wide:
     *
     *   - the active kit (global colours + typography)
     *   - published elementor_library templates that have display conditions set
     *
     * Templates with no _elementor_conditions are skipped on purpose: they never
     * render, so Elementor never generates CSS for them and their "missing" file is
     * correct behaviour, not a fault. Do not treat those as something to fix.
     *
     * @return int Number of CSS files written.
     */
    /**
     * Collect Elementor document ids nested inside a set of documents.
     *
     * A theme-builder header pulls its mega-menu panels in as separate Elementor documents,
     * and archive templates pull in JetEngine listing templates the same way. Those nested
     * documents have NO _elementor_conditions of their own, so the conditioned-template query
     * in regenerate_global_css() never sees them - yet each one has its own post-<id>.css that
     * the rendered page links to.
     *
     * After a purge those files are gone until something renders them. If WP Rocket minifies a
     * page during that window, the combined bundle is built WITHOUT them and then cached, so the
     * nested component (typically the mega menu) stays broken until the next full cache clear.
     *
     * Measured on spiritoftasmania staging 2026-09-01: 51 conditioned templates nested a further
     * 35 documents, 25 of which had no CSS after a purge - including every mega-menu panel.
     *
     * @param int[] $ids   Documents to scan.
     * @param int   $depth Remaining recursion depth (panels can nest panels).
     * @return int[] Nested document ids.
     */
    protected static function collect_nested_ids( array $ids, $depth = 2 ) {

        if ( $depth < 1 || empty( $ids ) ) {
            return array();
        }

        $found = array();

        // Elementor stores some settings url-encoded, so each blob is scanned in both forms.
        $patterns = array(
            '/"template_id"\s*:\s*"?(\d+)"?/',      // Template widget - mega-menu panels
            '/"templateID"\s*:\s*"?(\d+)"?/',       // global widgets
            '/"lis[it]{2}ng_id"\s*:\s*"?(\d+)"?/',  // JetEngine listing grid (their spelling)
            '/elementor-template\s+id="?(\d+)"?/',   // [elementor-template] shortcode
        );

        foreach ( $ids as $id ) {

            $data = get_post_meta( (int) $id, '_elementor_data', true );
            if ( ! is_string( $data ) || '' === $data ) {
                continue;
            }

            $haystack = $data;
            if ( false !== strpos( $data, '%' ) ) {
                $haystack .= "\n" . urldecode( $data );
            }

            foreach ( $patterns as $pattern ) {
                if ( preg_match_all( $pattern, $haystack, $m ) ) {
                    foreach ( $m[1] as $hit ) {
                        $found[] = (int) $hit;
                    }
                }
            }
        }

        $found = array_values( array_diff( array_unique( array_filter( $found ) ), $ids ) );

        if ( empty( $found ) ) {
            return array();
        }

        // Recurse - a mega panel can itself embed a listing template.
        return array_values( array_unique( array_merge(
            $found,
            self::collect_nested_ids( array_merge( $ids, $found ), $depth - 1 )
        ) ) );
    }

    public static function regenerate_global_css() {

        if ( ! class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
            return 0;
        }

        $ids = array();

        // Active kit — global colours and typography, referenced by every page.
        $kit = (int) get_option( 'elementor_active_kit' );
        if ( $kit ) {
            $ids[] = $kit;
        }

        // In-use theme-builder templates (headers, footers, archives, singles).
        $templates = get_posts( array(
            'post_type'              => 'elementor_library',
            'post_status'            => 'publish',
            'posts_per_page'         => 100,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'meta_query'             => array(
                array(
                    'key'     => '_elementor_conditions',
                    'compare' => 'EXISTS',
                ),
            ),
        ) );

        // Only the templates that render on EVERY page belong in the synchronous set.
        //
        // A missing archive or single-post CSS file affects one page type, and the first
        // render there regenerates it before that page's cache is written - lazy is fine.
        // A missing header/footer file affects the whole site, and on a minifying host it
        // gets baked into the combined bundle for every page at once.
        //
        // Measured on spiritoftasmania staging 2026-09-01: all 51 conditioned templates plus
        // their nested set is 94 documents / 14.5s on every save. Narrowing to header+footer
        // and their nested set is 21 documents / 7.3s and still covers every mega-menu panel.
        $global_types = apply_filters( 'sec_global_template_types', array( 'header', 'footer' ) );

        foreach ( $templates as $tid ) {
            // EXISTS still matches an empty array serialised into the meta, which is
            // what Elementor leaves behind when every condition is removed. Those
            // templates do not render, so skip them.
            $conditions = get_post_meta( $tid, '_elementor_conditions', true );
            if ( empty( $conditions ) ) {
                continue;
            }

            $type = (string) get_post_meta( $tid, '_elementor_template_type', true );
            if ( ! in_array( $type, $global_types, true ) ) {
                continue;
            }

            $ids[] = (int) $tid;
        }

        // Templates nested inside the set above - mega-menu panels, listing templates, global
        // widgets. Each has its own post-<id>.css but no display conditions of its own, so
        // without this they are missing until something renders them. See collect_nested_ids().
        $ids = array_merge( $ids, self::collect_nested_ids( $ids ) );

        // Only real Elementor documents - Post::create() on anything else writes an empty file.
        $ids = array_filter( array_unique( array_filter( $ids ) ), function ( $id ) {
            return '' !== (string) get_post_meta( (int) $id, '_elementor_data', true );
        } );

        // Hard cap. Pathological sites must not turn every save into a minutes-long request.
        if ( count( $ids ) > self::MAX_GLOBAL_REGEN ) {
            $ids = array_slice( $ids, 0, self::MAX_GLOBAL_REGEN );
        }

        $written = 0;
        $skipped = 0;
        $started = microtime( true );

        foreach ( $ids as $id ) {

            // Budget exceeded - leave the remainder to lazy regeneration rather than risk a
            // timeout. Ordering matters: $ids is kit-first, then header/footer, then nested,
            // so the most site-wide files are always the ones that get done.
            if ( ( microtime( true ) - $started ) > self::REGEN_TIME_BUDGET ) {
                $skipped = count( $ids ) - $written;
                break;
            }

            try {
                $css = \Elementor\Core\Files\CSS\Post::create( $id );
                $css->update();
                $written++;
            } catch ( \Throwable $e ) {
                // A single bad template must not abort the purge — the caches below
                // still need clearing. Regeneration for it falls back to lazy.
                continue;
            }
        }

        update_option( 'sec_last_global_regen', array(
            'skipped' => $skipped,
            'seconds' => round( microtime( true ) - $started, 1 ),
            'count' => $written,
            'ids'   => array_values( array_unique( array_filter( $ids ) ) ),
            'time'  => time(),
        ), false );

        return $written;
    }

    /**
     * Library templates (header/footer/kit) and JetEngine listings are
     * embedded across many pages — saving them requires a site-wide purge,
     * not just the saved post.
     */
    public static function is_site_wide_post( $post_id ) {
        $type = get_post_type( $post_id );
        return in_array( $type, array( 'elementor_library', 'jet-engine', 'jet-popup' ), true );
    }

    /**
     * Regenerate the CSS file for ONE document. Nothing else is touched.
     *
     * Elementor normally rewrites a document's CSS as part of saving it, but not always - a
     * kit whose custom fonts changed was seen with CSS ten hours older than the kit itself
     * (spiritoftasmania staging, 2026-09-01). Refreshing the saved document explicitly costs
     * one file (~350ms for a large mega panel) and makes the save path deterministic.
     */
    public static function refresh_document_css( $post_id ) {
        $post_id = (int) $post_id;
        if ( ! $post_id || ! class_exists( '\\Elementor\\Core\\Files\\CSS\\Post' ) ) {
            return false;
        }
        if ( '' === (string) get_post_meta( $post_id, '_elementor_data', true ) ) {
            return false;
        }
        try {
            \Elementor\Core\Files\CSS\Post::create( $post_id )->update();
            return true;
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    /**
     * Save-time purge, routed by what was saved.
     *
     * v4.6.0: site-wide documents (templates, listings, popups, the kit) no longer take the
     * nuclear purge_site() path. That path deletes EVERY Elementor CSS file and then races
     * visitors to regenerate them - the window it opens is what let a mega menu render
     * unstyled and get baked into a cached, minified bundle. A save only invalidates the saved
     * document's own CSS, so: refresh that one file, then purge page caches site-wide.
     * Zero window, and saves are faster than under 4.4.0-4.5.x.
     *
     * purge_site() remains for the events that genuinely change CSS everywhere: plugin/theme
     * updates, kit switch, Elementor's own "Regenerate", and the manual purge.
     */
    public static function purge_for_elementor_post( $post_id ) {
        $post_id = (int) $post_id;
        if ( ! $post_id ) {
            return;
        }
        // Elementor autosaves of a DRAFT are written into the draft itself, so is_autosave() and
        // wp_is_post_autosave() are false; only the request says it is an autosave. Visitors
        // cannot see an autosave either way, so do not purge (every 60s while editing).
        if ( self::is_elementor_autosave_request() ) {
            return;
        }
        if ( self::is_site_wide_post( $post_id ) ) {
            self::refresh_document_css( $post_id );
            self::purge_site_for( $post_id ); // domain-wide: a template appears on arbitrary pages
        } else {
            self::purge_all( $post_id );
        }
    }

    /**
     * True when this is an Elementor editor ajax request whose every save_builder action is an
     * autosave (status "autosave"). A request that also does a real save returns false.
     */
    public static function is_elementor_autosave_request() {
        if ( ! wp_doing_ajax() || ! isset( $_REQUEST['action'], $_REQUEST['actions'] ) || 'elementor_ajax' !== $_REQUEST['action'] ) {
            return false;
        }
        $actions = json_decode( wp_unslash( (string) $_REQUEST['actions'] ), true );
        if ( ! is_array( $actions ) ) {
            return false;
        }
        $saves = 0;
        foreach ( $actions as $a ) {
            if ( ! is_array( $a ) || 'save_builder' !== ( $a['action'] ?? '' ) ) {
                continue;
            }
            $saves++;
            if ( 'autosave' !== ( $a['data']['status'] ?? '' ) ) {
                return false;
            }
        }
        return $saves > 0;
    }

    private static function stamp_last_purge( $scope, $post_id ) {
        // Capture the WordPress hook that ended up triggering the purge, so
        // post-hoc diagnosis doesn't need filesystem forensics. Falls back to
        // 'manual' for admin-page / WP-CLI / ?sec_purge_all=1 invocations that
        // aren't inside a hook fire.
        $hook = self::$origin_hook ? self::$origin_hook : current_filter();
        if ( empty( $hook ) ) {
            $hook = 'manual';
        }
        update_option( 'sec_last_purge', array(
            'scope'   => $scope,
            'post_id' => $post_id,
            'time'    => time(),
            'hook'    => $hook,
        ), false );
    }
}

// ---------------------------------------------------------------------------
// Hooks — bind purgers to every event that should cause a cache invalidation.
// Same hook set as v4 of the central skill.
// ---------------------------------------------------------------------------

// Coalesced trailing purge booked by SEC_Purger::defer().
add_action( SEC_Purger::HOOK_DEFERRED, array( 'SEC_Purger', 'run_deferred' ) );

// Trailing purge fallback when WP-Cron is not running.
add_action( 'admin_init', array( 'SEC_Purger', 'maybe_run_overdue' ) );

// Deactivation: drop the scheduled event and any pending rows (they are useless without us).
register_deactivation_hook( __FILE__, function () {
    global $wpdb;
    wp_clear_scheduled_hook( SEC_Purger::HOOK_DEFERRED );
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like( SEC_Purger::PENDING_PREFIX ) . '%'
    ) );
} );

// Elementor's explicit "clear CSS" action.
add_action( 'elementor/core/files/clear_cache', array( 'SEC_Purger', 'purge_site' ), 5 );

// Plugin / theme / core updates.
add_action( 'upgrader_process_complete',  array( 'SEC_Purger', 'purge_site' ), 5 );
add_action( 'automatic_updates_complete', array( 'SEC_Purger', 'purge_site' ), 5 );
add_action( 'activated_plugin',           array( 'SEC_Purger', 'purge_site' ), 5 );
add_action( 'deactivated_plugin',         array( 'SEC_Purger', 'purge_site' ), 5 );
add_action( 'switch_theme',               array( 'SEC_Purger', 'purge_site' ), 5 );

// Elementor document / editor saves — routed by post type.
add_action( 'elementor/document/after_save', function ( $document ) {
    if ( ! $document || ! method_exists( $document, 'get_main_id' ) ) {
        return;
    }
    // Autosaves change nothing visitors see, and must not use up the purge window
    // that the real Update click needs.
    if ( method_exists( $document, 'is_autosave' ) && $document->is_autosave() ) {
        return;
    }
    SEC_Purger::purge_for_elementor_post( $document->get_main_id() );
}, 5 );

add_action( 'elementor/editor/after_save', function ( $post_id ) {
    if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
        return;
    }
    SEC_Purger::purge_for_elementor_post( (int) $post_id );
}, 5 );

// Direct save_post on library templates (belt & braces — some flows skip the
// elementor/document/after_save hook).
add_action( 'save_post_elementor_library', function ( $post_id ) {
    if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
        return;
    }
    SEC_Purger::purge_for_elementor_post( (int) $post_id );
}, 5 );

// Custom font added/changed. Fonts have no CSS of their own - their @font-face lives in the
// KIT's CSS, which Elementor was seen NOT regenerating on its own. Refresh the kit explicitly.
add_action( 'save_post_elementor_font', function ( $post_id ) {
    if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
        return;
    }
    $kit = (int) get_option( 'elementor_active_kit' );
    if ( $kit ) {
        SEC_Purger::refresh_document_css( $kit );
    }
    SEC_Purger::purge_site_for( $post_id );
}, 5 );

// Elementor Kit (global colors/typography) and bulk regen.
add_action( 'update_option_elementor_active_kit',    array( 'SEC_Purger', 'purge_site' ), 5 );
add_action( 'elementor/core/files/after_regenerate', array( 'SEC_Purger', 'purge_site' ), 5 );

// WordPress "Additional CSS" edits.
add_action( 'save_post_custom_css', function ( $post_id ) { SEC_Purger::purge_site_for( $post_id ); }, 5 );

// WPCode snippet saves (changes runtime PHP/JS output cached in HTML).
add_action( 'save_post_wpcode', function ( $post_id ) {
    if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
        return;
    }
    SEC_Purger::purge_site_for( $post_id );
}, 5 );

// Manual admin purge: ?sec_purge_all=1 on any front-end URL while logged in
// as an administrator. Convenient when SG caches something unexpected.
add_action( 'init', function () {
    if ( ! is_admin() && isset( $_GET['sec_purge_all'] ) && current_user_can( 'manage_options' ) ) {
        SEC_Purger::purge_site();
        wp_die(
            'All caches purged. <a href="' . esc_url( remove_query_arg( 'sec_purge_all' ) ) . '">Continue</a>'
        );
    }
} );

// ---------------------------------------------------------------------------
// Admin page — Settings → Sync Elementor Cache. Shows detected layers,
// last purge, and a one-click "Purge everything" button.
// ---------------------------------------------------------------------------
add_action( 'admin_menu', function () {
    add_options_page(
        __( 'Sync Elementor Cache', 'sync-elementor-cache' ),
        __( 'Sync Elementor Cache', 'sync-elementor-cache' ),
        'manage_options',
        'sync-elementor-cache',
        'sec_render_admin_page'
    );
} );

function sec_render_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Handle the purge button submission.
    if ( isset( $_POST['sec_action'] ) && $_POST['sec_action'] === 'purge_site' && check_admin_referer( 'sec_purge_site' ) ) {
        SEC_Purger::purge_site();
        echo '<div class="notice notice-success"><p>All caches purged.</p></div>';
    }

    $detected = SEC_Detector::summary();
    $last     = get_option( 'sec_last_purge', array() );
    $recs     = SEC_Detector::recommendations();

    echo '<div class="wrap"><h1>Sync Elementor Cache</h1>';
    echo '<p>Version ' . esc_html( SEC_PLUGIN_VERSION ) . ' &mdash; auto-updates from <code>github.com/sansiromedia/sync-elementor-cache</code>.</p>';

    // ---------- Recommendations (informational, never auto-applied) ----------
    if ( ! empty( $recs ) ) {
        echo '<h2>Recommendations</h2>';
        foreach ( $recs as $r ) {
            $color = $r['level'] === 'critical' ? '#d63638' : '#dba617';
            printf(
                '<div style="border-left:4px solid %s;background:#fff;padding:12px 16px;margin:0 0 12px 0;max-width:760px;">' .
                '<p style="margin:0 0 8px 0;"><strong>%s</strong></p>' .
                '<p style="margin:0 0 8px 0;">%s</p>' .
                '<p style="margin:0;"><em>Fix:</em> %s</p>' .
                '</div>',
                esc_attr( $color ),
                esc_html( $r['title'] ),
                wp_kses_post( $r['body'] ),
                wp_kses_post( $r['fix'] )
            );
        }
    }

    echo '<h2>Detected cache layers</h2><table class="widefat striped" style="max-width:520px;"><tbody>';
    $rows = array(
        'Elementor'                     => $detected['elementor'],
        'WP Rocket'                     => $detected['wp_rocket'],
        'SiteGround Optimizer (soft)'   => $detected['sg_soft'],
        'SiteGround Optimizer (nuclear)'=> $detected['sg_nuclear'],
    );
    foreach ( $rows as $label => $present ) {
        printf(
            '<tr><th style="width:60%%;">%s</th><td>%s</td></tr>',
            esc_html( $label ),
            $present ? '<span style="color:#138a36;">✓ active</span>' : '<span style="color:#999;">not present</span>'
        );
    }
    echo '</tbody></table>';

    echo '<h2>Last purge</h2>';
    if ( empty( $last ) ) {
        echo '<p>No purge recorded since plugin activation.</p>';
    } else {
        printf(
            '<p>Scope: <code>%s</code>%s<br>When: <code>%s</code> (%s ago)<br>Triggered by: <code>%s</code></p>',
            esc_html( $last['scope'] ),
            empty( $last['post_id'] ) ? '' : ' (post ID ' . (int) $last['post_id'] . ')',
            esc_html( gmdate( 'Y-m-d H:i:s', $last['time'] ) . ' UTC' ),
            esc_html( human_time_diff( $last['time'], time() ) ),
            esc_html( isset( $last['hook'] ) ? $last['hook'] : 'unknown (pre-4.3 purge)' )
        );
    }

    echo '<h2>Manual purge</h2>';
    echo '<p>Click below to nuke every detected cache layer (Elementor CSS files, WP Rocket, SG Optimizer). Use after changes that the automatic hooks may have missed.</p>';
    echo '<form method="post">';
    wp_nonce_field( 'sec_purge_site' );
    echo '<input type="hidden" name="sec_action" value="purge_site">';
    echo '<p><button type="submit" class="button button-primary">Purge everything</button></p>';
    echo '</form>';

    echo '<p>You can also trigger the same purge from the front end by visiting any URL with <code>?sec_purge_all=1</code> while logged in as an admin.</p>';

    echo '</div>';
}

// ---------------------------------------------------------------------------
// Admin notice — surface critical recommendations on every wp-admin page so
// they're not missed if Maria doesn't visit Settings → Sync Elementor Cache.
// Dismissible per-user via the 'sec_dismiss_notice' meta.
// ---------------------------------------------------------------------------
add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( $screen && $screen->id === 'settings_page_sync-elementor-cache' ) {
        return; // already showing on the plugin's own page
    }
    $recs = array_filter( SEC_Detector::recommendations(), function ( $r ) {
        return $r['level'] === 'critical';
    } );
    if ( empty( $recs ) ) {
        return;
    }
    $dismissed = (int) get_user_meta( get_current_user_id(), 'sec_dismiss_notice_v' . SEC_PLUGIN_VERSION, true );
    if ( $dismissed ) {
        return;
    }
    $url = admin_url( 'options-general.php?page=sync-elementor-cache' );
    printf(
        '<div class="notice notice-error is-dismissible" data-sec-notice="1"><p><strong>Sync Elementor Cache:</strong> %d critical configuration issue(s) detected on this site. <a href="%s">Review &amp; fix &rarr;</a></p></div>',
        count( $recs ),
        esc_url( $url )
    );

    // The dismissal has to be persisted server-side — core's dismiss button
    // only hides the node for the current page view. Without this the notice
    // reappeared on every admin page forever: v4.2.0–v4.3.0 read the
    // 'sec_dismiss_notice_v*' user meta but nothing ever wrote it, and the
    // div wasn't even marked is-dismissible.
    $nonce = wp_create_nonce( 'sec_dismiss_notice' );
    printf(
        '<script>jQuery(function($){$(document).on("click","[data-sec-notice] .notice-dismiss",function(){' .
        '$.post(ajaxurl,{action:"sec_dismiss_notice",_ajax_nonce:"%s"});});});</script>',
        esc_js( $nonce )
    );
} );

/**
 * Persist the per-user, per-version dismissal written by the notice above.
 * Keyed on SEC_PLUGIN_VERSION so a new release re-surfaces outstanding issues.
 */
add_action( 'wp_ajax_sec_dismiss_notice', function () {
    check_ajax_referer( 'sec_dismiss_notice' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( null, 403 );
    }
    update_user_meta( get_current_user_id(), 'sec_dismiss_notice_v' . SEC_PLUGIN_VERSION, 1 );
    wp_send_json_success();
} );

// ---------------------------------------------------------------------------
// WP-CLI command — `wp sync-elementor-cache purge` / `... status`
// ---------------------------------------------------------------------------
if ( defined( 'WP_CLI' ) && WP_CLI ) {

    class SEC_CLI {

        /**
         * Show which cache layers are detected.
         *
         * ## EXAMPLES
         *
         *     wp sync-elementor-cache status
         */
        public function status() {
            $detected = SEC_Detector::summary();
            WP_CLI::log( 'Sync Elementor Cache ' . SEC_PLUGIN_VERSION );
            foreach ( $detected as $key => $present ) {
                WP_CLI::log( sprintf( '  %-22s %s', $key, $present ? 'YES' : '-' ) );
            }
            $last = get_option( 'sec_last_purge', array() );
            if ( ! empty( $last ) ) {
                WP_CLI::log( sprintf(
                    'Last purge: %s (%s) at %s UTC — trigger: %s',
                    $last['scope'],
                    $last['post_id'] ? 'post ' . $last['post_id'] : 'site-wide',
                    gmdate( 'Y-m-d H:i:s', $last['time'] ),
                    isset( $last['hook'] ) ? $last['hook'] : 'unknown (pre-4.3 purge)'
                ) );
            }
            $recs = SEC_Detector::recommendations();
            if ( ! empty( $recs ) ) {
                WP_CLI::log( '' );
                WP_CLI::log( 'Recommendations:' );
                foreach ( $recs as $r ) {
                    WP_CLI::log( sprintf( '  [%s] %s', strtoupper( $r['level'] ), $r['title'] ) );
                }
                WP_CLI::log( '' );
                WP_CLI::log( 'Run `wp sync-elementor-cache recommendations` for details and fix instructions.' );
            }
        }

        /**
         * Show full recommendation details with fix instructions.
         *
         * ## EXAMPLES
         *
         *     wp sync-elementor-cache recommendations
         */
        public function recommendations() {
            $recs = SEC_Detector::recommendations();
            if ( empty( $recs ) ) {
                WP_CLI::success( 'No recommendations — configuration looks healthy.' );
                return;
            }
            foreach ( $recs as $i => $r ) {
                if ( $i > 0 ) {
                    WP_CLI::log( '' );
                }
                WP_CLI::log( sprintf( '[%s] %s', strtoupper( $r['level'] ), $r['title'] ) );
                WP_CLI::log( '' );
                WP_CLI::log( '  ' . wp_strip_all_tags( $r['body'] ) );
                WP_CLI::log( '' );
                WP_CLI::log( '  Fix: ' . wp_strip_all_tags( $r['fix'] ) );
            }
        }

        /**
         * Trigger a site-wide nuclear purge of every detected cache layer.
         *
         * ## EXAMPLES
         *
         *     wp sync-elementor-cache purge
         */
        public function purge() {
            SEC_Purger::purge_site();
            WP_CLI::success( 'All caches purged.' );
        }

        /**
         * Rebuild the Elementor CSS every page depends on: the active kit plus
         * in-use theme-builder templates. Does NOT touch per-post CSS.
         *
         * Useful after a manual Elementor "Regenerate CSS", or to confirm the
         * global set is intact without running a full purge.
         *
         * ## EXAMPLES
         *
         *     wp sync-elementor-cache regen
         */
        public function regen() {
            $written = SEC_Purger::regenerate_global_css();
            if ( ! $written ) {
                WP_CLI::warning( 'Nothing regenerated — is Elementor active?' );
                return;
            }
            $last = get_option( 'sec_last_global_regen', array() );
            WP_CLI::log( 'Rebuilt: ' . implode( ', ', array_map(
                function ( $id ) {
                    return $id . ' (' . get_the_title( $id ) . ')';
                },
                isset( $last['ids'] ) ? $last['ids'] : array()
            ) ) );
            WP_CLI::success( sprintf( '%d global CSS file(s) regenerated.', $written ) );
        }
    }

    WP_CLI::add_command( 'sync-elementor-cache', 'SEC_CLI' );
}
