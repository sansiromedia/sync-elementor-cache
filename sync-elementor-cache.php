<?php
/**
 * Plugin Name:       Sync Elementor Cache
 * Plugin URI:        https://github.com/sansiromedia/sync-elementor-cache
 * Description:       Keeps Elementor in sync with WP Rocket and/or SiteGround Optimizer so logged-out visitors don't see stale CSS after editor saves, library template changes, or plugin updates. Auto-detects which caching layers are present and adapts.
 * Version:           4.1.0
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
define( 'SEC_PLUGIN_VERSION', '4.1.0' );
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
}

// ---------------------------------------------------------------------------
// Purger — the actual cache-clearing logic. Pure functions, no side effects
// beyond hitting the detected cache layers.
// ---------------------------------------------------------------------------
final class SEC_Purger {

    private static $purging = false;

    /**
     * Per-post (low-churn) purge. Used for editor saves on a single page.
     * On SG, uses the soft purge (URL-targeted). Does NOT clear cached
     * error responses — that's what purge_site() handles.
     */
    public static function purge_all( $post_id = null ) {

        // WP Rocket page cache: per-post if we have an ID, site-wide otherwise.
        if ( $post_id && function_exists( 'rocket_clean_post' ) ) {
            rocket_clean_post( $post_id );
        } elseif ( function_exists( 'rocket_clean_domain' ) ) {
            rocket_clean_domain();
        }

        // Rocket minified CSS, cache-busting bundles, used-CSS (guarded —
        // older Rocket versions may not have all of these).
        if ( function_exists( 'rocket_clean_minify' ) ) {
            rocket_clean_minify( 'css' );
        }
        if ( function_exists( 'rocket_clean_cache_busting' ) ) {
            rocket_clean_cache_busting();
        }
        if ( function_exists( 'rocket_clean_used_css' ) ) {
            rocket_clean_used_css();
        }

        // SG Optimizer SOFT purge — URL-keyed memcache invalidation. Cheap,
        // safe to call on every save. Doesn't reach cached error responses.
        if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
            sg_cachepress_purge_cache();
        }

        wp_cache_flush();

        self::stamp_last_purge( 'all', $post_id );
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

        // 1. Wipe Elementor's per-post CSS files. They'll regen on next page hit.
        if ( class_exists( '\Elementor\Plugin' ) && ! empty( \Elementor\Plugin::$instance->files_manager ) ) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
        }

        // 2. Standard site-wide cache layer wipe.
        self::purge_all( null );

        // 3. SG NUCLEAR purge — clears cached error responses (403, 5xx) too.
        //    Documented as v4 in the central skill: a WAF rate-limit can leave
        //    a 403 cached and the soft purge above can't touch it.
        if ( function_exists( 'sg_cachepress_purge_everything' ) ) {
            sg_cachepress_purge_everything();
        }

        self::stamp_last_purge( 'site', null );
        self::$purging = false;
    }

    /**
     * Library templates (header/footer/kit) and JetEngine listings are
     * embedded across many pages — saving them requires a site-wide purge,
     * not just the saved post.
     */
    public static function is_site_wide_post( $post_id ) {
        $type = get_post_type( $post_id );
        return in_array( $type, array( 'elementor_library', 'jet-engine' ), true );
    }

    public static function purge_for_elementor_post( $post_id ) {
        $post_id = (int) $post_id;
        if ( ! $post_id ) {
            return;
        }
        if ( self::is_site_wide_post( $post_id ) ) {
            self::purge_site();
        } else {
            self::purge_all( $post_id );
        }
    }

    private static function stamp_last_purge( $scope, $post_id ) {
        update_option( 'sec_last_purge', array(
            'scope'   => $scope,
            'post_id' => $post_id,
            'time'    => time(),
        ), false );
    }
}

// ---------------------------------------------------------------------------
// Hooks — bind purgers to every event that should cause a cache invalidation.
// Same hook set as v4 of the central skill.
// ---------------------------------------------------------------------------

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
    SEC_Purger::purge_for_elementor_post( $document->get_main_id() );
}, 5 );

add_action( 'elementor/editor/after_save', function ( $post_id ) {
    SEC_Purger::purge_for_elementor_post( (int) $post_id );
}, 5 );

// Direct save_post on library templates (belt & braces — some flows skip the
// elementor/document/after_save hook).
add_action( 'save_post_elementor_library', function ( $post_id ) {
    if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
        return;
    }
    SEC_Purger::purge_site();
}, 5 );

// Elementor Kit (global colors/typography) and bulk regen.
add_action( 'update_option_elementor_active_kit',    array( 'SEC_Purger', 'purge_site' ), 5 );
add_action( 'elementor/core/files/after_regenerate', array( 'SEC_Purger', 'purge_site' ), 5 );

// WordPress "Additional CSS" edits.
add_action( 'save_post_custom_css', array( 'SEC_Purger', 'purge_site' ), 5 );

// WPCode snippet saves (changes runtime PHP/JS output cached in HTML).
add_action( 'save_post_wpcode', function ( $post_id ) {
    if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
        return;
    }
    SEC_Purger::purge_site();
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

    echo '<div class="wrap"><h1>Sync Elementor Cache</h1>';
    echo '<p>Version ' . esc_html( SEC_PLUGIN_VERSION ) . ' &mdash; auto-updates from <code>github.com/sansiromedia/sync-elementor-cache</code>.</p>';

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
            '<p>Scope: <code>%s</code>%s<br>When: <code>%s</code> (%s ago)</p>',
            esc_html( $last['scope'] ),
            empty( $last['post_id'] ) ? '' : ' (post ID ' . (int) $last['post_id'] . ')',
            esc_html( gmdate( 'Y-m-d H:i:s', $last['time'] ) . ' UTC' ),
            esc_html( human_time_diff( $last['time'], time() ) )
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
                    'Last purge: %s (%s) at %s UTC',
                    $last['scope'],
                    $last['post_id'] ? 'post ' . $last['post_id'] : 'site-wide',
                    gmdate( 'Y-m-d H:i:s', $last['time'] )
                ) );
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
    }

    WP_CLI::add_command( 'sync-elementor-cache', 'SEC_CLI' );
}
