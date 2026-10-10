<?php
/**
 * Sync Elementor Cache - integration tests for the deferred / scoped purge routing.
 *
 * Run inside a real WordPress (STAGING ONLY - it purges caches and inserts/deletes test rows)
 * with the plugin active:
 *   wp eval-file wp-content/plugins/sync-elementor-cache/tests/integration-test.php
 *
 * Needs SG Optimizer with dynamic caching on (SG checks are skipped otherwise), at least one
 * published page, and the `wp` binary on PATH (test 1 books a purge from a separate process).
 * Not shipped in the release zip.
 */

if ( ! class_exists( 'SEC_Purger' ) ) {
    echo "SKIP: plugin not active\n";
    return;
}

$GLOBALS['sec_results'] = array( 'pass' => 0, 'fail' => 0, 'skip' => 0 );

function sec_t( $name, $ok, $detail = '' ) {
    $GLOBALS['sec_results'][ $ok ? 'pass' : 'fail' ]++;
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? " - $detail" : '' ) . "\n";
}

function sec_skip( $name, $why ) {
    $GLOBALS['sec_results']['skip']++;
    echo "SKIP $name - $why\n";
}

/** Everything pending, straight from the DB, in either storage format. Post ids + 'site'. */
function sec_pending() {
    global $wpdb;
    $out = array();
    foreach ( $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'sec\\_pending\\_%'" ) as $r ) {
        if ( 'sec_pending_purge' === $r->option_name ) {
            $v = maybe_unserialize( $r->option_value );
            if ( is_array( $v ) ) {
                if ( ! empty( $v['site'] ) ) { $out[] = 'site'; }
                foreach ( array_keys( (array) ( $v['posts'] ?? array() ) ) as $p ) { $out[] = (int) $p; }
            }
            continue;
        }
        $suffix = substr( $r->option_name, strlen( 'sec_pending_' ) );
        $out[]  = 'site' === $suffix ? 'site' : (int) $suffix;
    }
    sort( $out );
    return $out;
}

function sec_reset( $extra_ids = array() ) {
    global $wpdb;
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'sec\\_pending\\_%' OR option_name LIKE 'sec\\_purge\\_last\\_%' OR option_name = 'sec_deferred_lock'" );
    foreach ( array( 'sec_pending_purge', 'notoptions', 'alloptions' ) as $k ) { wp_cache_delete( $k, 'options' ); }
    foreach ( array_merge( array( 1, 2, 3, 900001, 900002, 900003, 'site' ), (array) $extra_ids ) as $id ) {
        delete_transient( 'sec_thr_' . $id );
    }
    // SG's memcached drop-in keeps an in-process copy when memcached's delete "fails" because the
    // key already expired there (15s throttle, long test run). Drop those copies too.
    if ( isset( $GLOBALS['wp_object_cache']->cache ) && is_array( $GLOBALS['wp_object_cache']->cache ) ) {
        foreach ( array_keys( $GLOBALS['wp_object_cache']->cache ) as $k ) {
            if ( false !== strpos( (string) $k, 'sec_thr_' ) ) {
                unset( $GLOBALS['wp_object_cache']->cache[ $k ] );
            }
        }
    }
    wp_clear_scheduled_hook( SEC_Purger::HOOK_DEFERRED );
    $ref = new ReflectionClass( 'SEC_Purger' );
    foreach ( array( 'request_flush_ran' => false, 'origin_hook' => '' ) as $prop => $val ) {
        if ( $ref->hasProperty( $prop ) ) {
            $p = $ref->getProperty( $prop );
            $p->setAccessible( true );
            $p->setValue( null, $val );
        }
    }
    if ( $ref->hasProperty( 'request_items' ) ) {
        $p = $ref->getProperty( 'request_items' );
        $p->setAccessible( true );
        $p->setValue( null, array() );
    }
}

/** Count SG soft purges and capture their URLs while $fn runs. */
function sec_capture_sg( callable $fn ) {
    $urls = array();
    $cb   = function ( $url ) use ( &$urls ) { $urls[] = $url; };
    add_action( 'siteground_optimizer_flush_cache', $cb );
    $fn();
    remove_action( 'siteground_optimizer_flush_cache', $cb );
    return $urls;
}

/** Run $fn while counting clean_post_cache() calls per post (one per SCOPE_POST execution). */
function sec_count_post_purges( callable $fn ) {
    $n  = array();
    $cb = function ( $id ) use ( &$n ) { $n[ (int) $id ] = ( $n[ (int) $id ] ?? 0 ) + 1; };
    add_action( 'clean_post_cache', $cb );
    $fn();
    remove_action( 'clean_post_cache', $cb );
    return $n;
}

/** Fire $hook with ONLY the closures defined in this plugin's main file. */
function sec_do_own_action( $hook, ...$args ) {
    global $wp_filter;
    $backup = isset( $wp_filter[ $hook ] ) ? clone $wp_filter[ $hook ] : null;
    if ( $backup ) {
        foreach ( $backup->callbacks as $prio => $cbs ) {
            foreach ( $cbs as $cb ) {
                $f    = $cb['function'];
                $file = $f instanceof Closure ? ( new ReflectionFunction( $f ) )->getFileName() : '';
                if ( realpath( $file ) !== realpath( SEC_PLUGIN_FILE ) ) {
                    remove_action( $hook, $f, $prio );
                }
            }
        }
    }
    do_action( $hook, ...$args );
    if ( $backup ) {
        $wp_filter[ $hook ] = $backup;
    }
}

/** Last purge record, read past any cache. */
function sec_last_scope() {
    wp_cache_delete( 'sec_last_purge', 'options' );
    $l = get_option( 'sec_last_purge' );
    return $l['scope'] ?? '?';
}

global $wpdb;
$sec_ref  = new ReflectionClass( 'SEC_Purger' );
$sec_priv = function ( $name ) use ( $sec_ref ) { $m = $sec_ref->getMethod( $name ); $m->setAccessible( true ); return $m; };
$sg_on    = function_exists( 'sg_cachepress_purge_cache' ) && get_option( 'siteground_optimizer_enable_cache' );
// Not the front page / posts page: Rocket purges those via rocket_clean_home, not by URL.
$not_home  = array_filter( array( (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) ) );
$not_home  = $not_home ? ' AND ID NOT IN (' . implode( ',', $not_home ) . ')' : '';
$some_post = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish'{$not_home} ORDER BY ID LIMIT 1" );
$templates = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='elementor_library' AND post_status='publish' ORDER BY ID LIMIT 2" ) );
$template  = $templates[0] ?? 0;
$interactive_on  = function () { add_filter( 'sec_is_interactive', '__return_true' ); };
$interactive_off = function () { remove_filter( 'sec_is_interactive', '__return_true' ); };

if ( ! $some_post ) {
    echo "SKIP ALL: no published page to test with\n";
    return;
}

// 0. Version constant matches the header.
$hdr = get_file_data( SEC_PLUGIN_FILE, array( 'v' => 'Version' ) );
sec_t( 'version constant matches header', SEC_PLUGIN_VERSION === $hdr['v'], SEC_PLUGIN_VERSION . ' vs ' . $hdr['v'] );

// 1. A booking made by another process (between our own bookings) is not overwritten.
//    Sequential, not truly concurrent: it catches an in-process stale read of shared state.
sec_reset();
SEC_Purger::defer( SEC_Purger::SCOPE_POST, 900001 );
shell_exec( 'wp eval ' . escapeshellarg( 'SEC_Purger::defer( SEC_Purger::SCOPE_POST, 900002 );' ) . ' --path=' . escapeshellarg( ABSPATH ) . ' 2>&1' );
SEC_Purger::defer( SEC_Purger::SCOPE_POST, 900003 );
$p = sec_pending();
sec_t( "another process's booking survives our next booking", array( 900001, 900002, 900003 ) === $p, 'pending=' . json_encode( $p ) );

// 2. Throttle is per post.
sec_reset();
$thr = $sec_priv( 'is_throttled' );
$x   = $thr->invoke( null, SEC_Purger::SCOPE_POST, 1 );
$y   = $thr->invoke( null, SEC_Purger::SCOPE_POST, 2 );
$x2  = $thr->invoke( null, SEC_Purger::SCOPE_POST, 1 );
sec_t( 'first save of a different page is not throttled', false === $x && false === $y, 'x=' . var_export( $x, true ) . ' y=' . var_export( $y, true ) );
sec_t( 'repeat save of the same page is throttled', true === $x2 );

// 3. The throttle writes no plain option; with a persistent object cache, no DB row at all.
$legacy = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'sec\\_purge\\_last\\_%'" );
$trans  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_sec\\_thr\\_%'" );
sec_t( 'throttle writes no sec_purge_last_* option', 0 === $legacy, "$legacy row(s)" );
if ( wp_using_ext_object_cache() ) {
    sec_t( 'with an object cache the throttle never touches the DB', 0 === $trans, "$trans transient row(s)" );
} else {
    sec_skip( 'throttle never touches the DB', 'no persistent object cache here (transients live in wp_options)' );
}

// 4. WP-CLI: a purge request is booked and flushed at shutdown, and that flush purges it.
sec_reset( array( $some_post ) );
SEC_Purger::purge_all( $some_post );
$booked      = sec_pending();
$on_shutdown = false !== has_action( 'shutdown', array( 'SEC_Purger', 'run_deferred' ) );
$n           = sec_count_post_purges( function () { SEC_Purger::run_deferred(); } );
sec_t( 'CLI purge is booked, flushed at shutdown, and the flush purges it',
    array( $some_post ) === $booked && $on_shutdown && 1 === ( $n[ $some_post ] ?? 0 ) && array() === sec_pending(),
    'booked=' . json_encode( $booked ) . ' shutdown=' . var_export( $on_shutdown, true ) . ' purges=' . ( $n[ $some_post ] ?? 0 ) );

// 5. Overdue fallback: runs work older than the threshold, leaves fresh work alone.
if ( false === has_action( 'admin_init', array( 'SEC_Purger', 'maybe_run_overdue' ) ) || ! method_exists( 'SEC_Purger', 'maybe_run_overdue' ) ) {
    sec_t( 'overdue fallback hooked on admin_init', false );
} else {
    sec_reset();
    $wpdb->insert( $wpdb->options, array( 'option_name' => 'sec_pending_900001', 'option_value' => ( time() - 600 ) . ':old', 'autoload' => 'no' ) );
    SEC_Purger::maybe_run_overdue();
    $old_gone = array() === sec_pending();
    SEC_Purger::defer( SEC_Purger::SCOPE_POST, 900002 );
    SEC_Purger::maybe_run_overdue();
    $fresh_kept = array( 900002 ) === sec_pending();
    sec_t( 'overdue work runs on admin_init; fresh work waits', $old_gone && $fresh_kept, 'old_gone=' . var_export( $old_gone, true ) . ' fresh_kept=' . var_export( $fresh_kept, true ) );
}

// 6. A post-scope purge soft-purges SG domain-wide (home URL), never just the page.
if ( $sg_on ) {
    sec_reset();
    $urls = sec_capture_sg( function () use ( $some_post ) { SEC_Purger::execute( $some_post, SEC_Purger::SCOPE_POST, false ); } );
    sec_t( 'post save soft-purges SG domain-wide, never just the page URL', count( $urls ) >= 1 && array( get_home_url( '/' ) ) === array_values( array_unique( $urls ) ), json_encode( $urls ) );
} else {
    sec_skip( 'post save soft-purges SG domain-wide', 'SG dynamic cache not enabled' );
}

// 7. Nested purge requests inside purge_site() are no-ops.
sec_reset();
$prop = $sec_ref->getProperty( 'purging' );
$prop->setAccessible( true );
$prop->setValue( null, true );
$n    = 0;
$urls = sec_capture_sg( function () use ( &$n ) {
    $c = sec_count_post_purges( function () { SEC_Purger::request_purge( SEC_Purger::SCOPE_SITE, null ); } );
    $n = array_sum( $c );
} );
$prop->setValue( null, false );
sec_t( 'nested request inside purge_site() is a no-op', 0 === count( $urls ) && 0 === $n && array() === sec_pending(), count( $urls ) . ' SG purge(s), pending=' . json_encode( sec_pending() ) );

// 8. Many deferred posts collapse to ONE site purge.
sec_reset();
$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ('page','post','product') ORDER BY ID LIMIT 25" );
if ( count( $ids ) <= 20 ) {
    sec_skip( 'deferred posts collapse to one site purge', 'needs 21+ published posts' );
} else {
    foreach ( $ids as $id ) { SEC_Purger::defer( SEC_Purger::SCOPE_POST, (int) $id ); }
    $per_post = sec_count_post_purges( function () { SEC_Purger::run_deferred(); } );
    $scope    = sec_last_scope();
    sec_t( count( $ids ) . ' deferred posts collapse to one site purge', 0 === array_sum( $per_post ) && 'site' === $scope && array() === sec_pending(), array_sum( $per_post ) . ' per-post purge(s), last scope ' . $scope );
}

// 9. Browser request: nothing is purged mid-request (before the save finishes); the request's
//    purges run once at shutdown.
sec_reset( array( $some_post ) );
$interactive_on();
$during = sec_count_post_purges( function () use ( $some_post ) {
    SEC_Purger::purge_all( $some_post );
    SEC_Purger::purge_all( $some_post );
    SEC_Purger::purge_all( $some_post );
} );
$registered = method_exists( 'SEC_Purger', 'run_request_purges' ) && false !== has_action( 'shutdown', array( 'SEC_Purger', 'run_request_purges' ) );
$after      = $registered ? sec_count_post_purges( function () { SEC_Purger::run_request_purges(); } ) : array();
$interactive_off();
sec_t( 'browser save: no purge mid-request, exactly one at shutdown, nothing deferred',
    0 === array_sum( $during ) && 1 === ( $after[ $some_post ] ?? 0 ) && array() === sec_pending(),
    'during=' . array_sum( $during ) . ' at_shutdown=' . ( $after[ $some_post ] ?? 0 ) . ' pending=' . json_encode( sec_pending() ) );

// 10. Real template-save hook order: three hooks, one site purge, run after the last hook.
if ( $template ) {
    sec_reset( array( $template ) );
    $interactive_on();
    $doc = new class( $template ) {
        private $id;
        public function __construct( $id ) { $this->id = $id; }
        public function get_main_id() { return $this->id; }
        public function is_autosave() { return false; }
    };
    $during = sec_capture_sg( function () use ( $template, $doc ) {
        sec_do_own_action( 'save_post_elementor_library', $template );
        sec_do_own_action( 'elementor/editor/after_save', $template, array() );
        sec_do_own_action( 'elementor/document/after_save', $doc, array() );
    } );
    $at_end = method_exists( 'SEC_Purger', 'run_request_purges' ) ? sec_capture_sg( function () { SEC_Purger::run_request_purges(); } ) : array();
    $scope  = sec_last_scope();
    $interactive_off();
    sec_t( 'template save (3 hooks): no purge until the last hook, then one site purge, nothing deferred',
        0 === count( $during ) && count( $at_end ) >= 1 && 'site' === $scope && array() === sec_pending(),
        count( $during ) . ' SG purge(s) during hooks, ' . count( $at_end ) . ' at end, scope ' . $scope . ', pending=' . json_encode( sec_pending() ) );
} else {
    sec_skip( 'template save hook order', 'no published elementor_library template' );
}

// 11. Autosaves: neither hook purges, books, or starts the throttle window.
sec_reset( array( $some_post ) );
$interactive_on();
$fake = new class( $some_post ) {
    private $id;
    public function __construct( $id ) { $this->id = $id; }
    public function get_main_id() { return $this->id; }
    public function is_autosave() { return true; }
};
$rev = wp_insert_post( array(
    'post_type'   => 'revision',
    'post_status' => 'inherit',
    'post_parent' => $some_post,
    'post_name'   => $some_post . '-autosave-v1',
    'post_title'  => 'sec autosave test',
) );
$urls = sec_capture_sg( function () use ( $fake, $rev ) {
    sec_do_own_action( 'elementor/document/after_save', $fake, array() );
    sec_do_own_action( 'elementor/editor/after_save', $rev, array() );
} );
$collected = $sec_ref->hasProperty( 'request_items' ) ? ( function () use ( $sec_ref ) { $p = $sec_ref->getProperty( 'request_items' ); $p->setAccessible( true ); return $p->getValue(); } )() : array();
$thr_after = get_transient( 'sec_thr_' . $some_post ) || get_transient( 'sec_thr_' . $rev );
if ( $rev ) { wp_delete_post( $rev, true ); }
$interactive_off();
sec_t( 'autosave (document + editor hooks) does not purge, collect, book or throttle',
    $rev && 0 === count( $urls ) && array() === $collected && ! $thr_after && array() === sec_pending(),
    count( $urls ) . ' SG purge(s), collected=' . json_encode( $collected ) . ' throttle=' . var_export( (bool) $thr_after, true ) . ' pending=' . json_encode( sec_pending() ) );

// 12. purge_site() clears work it covered, but keeps work booked while it ran.
if ( ! $sg_on ) {
    sec_skip( 'purge_site keeps work booked mid-purge', 'needs SG dynamic cache (the mid-purge booking hooks its flush)' );
} else {
sec_reset();
SEC_Purger::defer( SEC_Purger::SCOPE_POST, 900001 );
SEC_Purger::defer( SEC_Purger::SCOPE_SITE );
$book_mid = function () { SEC_Purger::defer( SEC_Purger::SCOPE_POST, 900002 ); };
add_action( 'siteground_optimizer_flush_cache', $book_mid );
add_action( 'clean_post_cache', $book_mid );
SEC_Purger::purge_site();
remove_action( 'siteground_optimizer_flush_cache', $book_mid );
remove_action( 'clean_post_cache', $book_mid );
sec_t( 'purge_site clears covered work, keeps work booked mid-purge', array( 900002 ) === sec_pending(), 'pending=' . json_encode( sec_pending() ) );
}

// 13. Only one deferred runner at a time; an abandoned lock expires.
if ( ! defined( 'SEC_Purger::LOCK_NAME' ) ) {
    sec_t( 'deferred runner takes a lock', false, 'no LOCK_NAME' );
} else {
    sec_reset();
    SEC_Purger::defer( SEC_Purger::SCOPE_POST, 900001 );
    $wpdb->insert( $wpdb->options, array( 'option_name' => SEC_Purger::LOCK_NAME, 'option_value' => (string) time(), 'autoload' => 'no' ) );
    SEC_Purger::run_deferred();
    $held = array( 900001 ) === sec_pending();
    $wpdb->update( $wpdb->options, array( 'option_value' => (string) ( time() - 3600 ) ), array( 'option_name' => SEC_Purger::LOCK_NAME ) );
    SEC_Purger::run_deferred();
    $stale_ok  = array() === sec_pending();
    $lock_left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name=%s", SEC_Purger::LOCK_NAME ) );
    sec_t( 'held lock blocks a second runner; stale lock is taken over and released', $held && $stale_ok && 0 === $lock_left, 'held=' . var_export( $held, true ) . ' stale_ok=' . var_export( $stale_ok, true ) . " lock_left=$lock_left" );
}

// 14. Re-booking an item while it is being purged keeps it pending for the next run.
sec_reset();
SEC_Purger::defer( SEC_Purger::SCOPE_POST, $some_post );
$rebooked = false;
$rebook   = function ( $id ) use ( $some_post, &$rebooked ) {
    if ( ! $rebooked && (int) $id === $some_post ) {
        $rebooked = true;
        SEC_Purger::defer( SEC_Purger::SCOPE_POST, $some_post );
    }
};
add_action( 'clean_post_cache', $rebook );
SEC_Purger::run_deferred();
remove_action( 'clean_post_cache', $rebook );
sec_t( 're-booking an item mid-purge keeps it pending', $rebooked && array( $some_post ) === sec_pending(), 'rebooked=' . var_export( $rebooked, true ) . ' pending=' . json_encode( sec_pending() ) );

// 15. Deactivation removes pending rows and the scheduled event.
sec_reset();
SEC_Purger::defer( SEC_Purger::SCOPE_POST, 900001 );
if ( ! wp_next_scheduled( SEC_Purger::HOOK_DEFERRED ) ) { wp_schedule_single_event( time() + 60, SEC_Purger::HOOK_DEFERRED ); }
do_action( 'deactivate_' . plugin_basename( SEC_PLUGIN_FILE ), false );
sec_t( 'deactivation removes pending rows + scheduled event', array() === sec_pending() && ! wp_next_scheduled( SEC_Purger::HOOK_DEFERRED ), 'pending=' . json_encode( sec_pending() ) );


// 16. Rocket: the shutdown purge of a page really purges, even though Rocket already ran its
//     once-per-request rocket_clean_post() for that page earlier in the save.
if ( ! function_exists( 'rocket_clean_post' ) ) {
    sec_skip( 'shutdown purge beats Rocket per-request guard', 'WP Rocket not active' );
} else {
    sec_reset( array( $some_post ) );
    rocket_clean_post( $some_post ); // what wp_update_post -> clean_post_cache does mid-save
    $interactive_on();
    SEC_Purger::purge_all( $some_post );
    $files = array();
    $after = array();
    $cb    = function ( $urls ) use ( &$files ) { $files = array_merge( $files, (array) $urls ); };
    $cb2   = function ( $post, $urls, $lang ) use ( &$after ) { $after[] = (int) $post->ID; };
    add_action( 'before_rocket_clean_files', $cb );
    add_action( 'after_rocket_clean_post', $cb2, 10, 3 );
    if ( method_exists( 'SEC_Purger', 'run_request_purges' ) ) { SEC_Purger::run_request_purges(); }
    remove_action( 'before_rocket_clean_files', $cb );
    remove_action( 'after_rocket_clean_post', $cb2, 10 );
    $interactive_off();
    $permalink = get_permalink( $some_post );
    sec_t( 'shutdown purge clears the page in Rocket despite its once-per-request guard', in_array( $permalink, $files, true ), 'rocket files purged: ' . count( $files ) );
    sec_t( 'shutdown purge fires after_rocket_clean_post (archives, preload, CDN listeners)', in_array( $some_post, $after, true ), 'after_rocket_clean_post for: ' . json_encode( $after ) );
}

// 17. A held lock never leaves pending work without a scheduled event.
sec_reset();
if ( ! defined( 'SEC_Purger::LOCK_NAME' ) ) {
    sec_t( 'busy lock reschedules', false, 'no LOCK_NAME' );
} else {
    $wpdb->insert( $wpdb->options, array( 'option_name' => 'sec_pending_900001', 'option_value' => time() . ':x', 'autoload' => 'no' ) );
    $wpdb->insert( $wpdb->options, array( 'option_name' => SEC_Purger::LOCK_NAME, 'option_value' => (string) time(), 'autoload' => 'no' ) );
    wp_clear_scheduled_hook( SEC_Purger::HOOK_DEFERRED );
    SEC_Purger::run_deferred();
    $scheduled = (bool) wp_next_scheduled( SEC_Purger::HOOK_DEFERRED );
    sec_t( 'busy lock: pending work gets a scheduled event', $scheduled && array( 900001 ) === sec_pending(), 'scheduled=' . var_export( $scheduled, true ) );
}

// 18. A purge asked for after the shutdown flush ran (another plugin writing at shutdown) still runs.
sec_reset( array( $some_post ) );
$interactive_on();
if ( method_exists( 'SEC_Purger', 'run_request_purges' ) ) { SEC_Purger::run_request_purges(); }
$n = sec_count_post_purges( function () use ( $some_post ) { SEC_Purger::purge_all( $some_post ); } );
$interactive_off();
sec_t( 'purge requested after the shutdown flush still runs', 1 === ( $n[ $some_post ] ?? 0 ), ( $n[ $some_post ] ?? 0 ) . ' purge(s)' );

// 19. "Triggered by" records the hook that asked for the purge, not 'shutdown'.
sec_reset( array( $some_post ) );
$interactive_on();
$ask = function () use ( $some_post ) { SEC_Purger::purge_all( $some_post ); };
add_action( 'sec_test_origin', $ask );
do_action( 'sec_test_origin' );
remove_action( 'sec_test_origin', $ask );
$run = function () { if ( method_exists( 'SEC_Purger', 'run_request_purges' ) ) { SEC_Purger::run_request_purges(); } };
add_action( 'sec_test_shutdown', $run );
do_action( 'sec_test_shutdown' );
remove_action( 'sec_test_shutdown', $run );
$interactive_off();
wp_cache_delete( 'sec_last_purge', 'options' );
$l = get_option( 'sec_last_purge' );
sec_t( 'last purge records the originating hook', 'sec_test_origin' === ( $l['hook'] ?? '' ), 'hook=' . ( $l['hook'] ?? '?' ) );


// 20. Site-wide saves of DIFFERENT documents (header, then footer) are not throttled together.
if ( count( $templates ) < 2 ) {
    sec_skip( 'header then footer both purge', 'needs 2 published templates' );
} else {
    sec_reset( $templates );
    $interactive_on();
    $new_request = function () use ( $sec_ref ) {
        foreach ( array( 'request_items' => array(), 'request_flush_ran' => false ) as $prop => $val ) {
            $p = $sec_ref->getProperty( $prop ); $p->setAccessible( true ); $p->setValue( null, $val );
        }
    };
    SEC_Purger::purge_for_elementor_post( $templates[0] );
    $first = $sec_ref->getProperty( 'request_items' ); $first->setAccessible( true ); $c1 = $first->getValue();
    $new_request();
    SEC_Purger::purge_for_elementor_post( $templates[1] );
    $c2 = $first->getValue();
    $interactive_off();
    sec_t( 'header then footer (separate requests, within the window): both purge, neither deferred',
        isset( $c1['site'] ) && isset( $c2['site'] ) && array() === sec_pending(),
        'req1=' . json_encode( array_keys( $c1 ) ) . ' req2=' . json_encode( array_keys( $c2 ) ) . ' pending=' . json_encode( sec_pending() ) );
}

// 21. An Elementor autosave request of a DRAFT (written into the draft itself) does not purge;
//     a real save request does.
sec_reset( array( $some_post ) );
$interactive_on();
add_filter( 'wp_doing_ajax', '__return_true' );
$saved_request = $_REQUEST;
$items_prop    = $sec_ref->getProperty( 'request_items' ); $items_prop->setAccessible( true );
$_REQUEST['action']  = 'elementor_ajax';
$_REQUEST['actions'] = wp_slash( wp_json_encode( array( 'save_builder_' . $some_post => array( 'action' => 'save_builder', 'data' => array( 'status' => 'autosave' ) ) ) ) );
SEC_Purger::purge_for_elementor_post( $some_post );
$auto = $items_prop->getValue();
sec_reset( array( $some_post ) );
$_REQUEST['actions'] = wp_slash( wp_json_encode( array( 'save_builder_' . $some_post => array( 'action' => 'save_builder', 'data' => array( 'status' => 'publish' ) ) ) ) );
SEC_Purger::purge_for_elementor_post( $some_post );
$real = $items_prop->getValue();
$_REQUEST = $saved_request;
remove_filter( 'wp_doing_ajax', '__return_true' );
$interactive_off();
sec_t( 'draft autosave request does not purge; a real save does', array() === $auto && isset( $real[ $some_post ] ) && array() === sec_pending(),
    'autosave collected=' . json_encode( array_keys( $auto ) ) . ' real collected=' . json_encode( array_keys( $real ) ) );

// 22. WP-CLI / cron purges book no WP-Cron event (they flush at process end).
sec_reset( array( $some_post ) );
SEC_Purger::purge_all( $some_post );
$scheduled = (bool) wp_next_scheduled( SEC_Purger::HOOK_DEFERRED );
sec_t( 'CLI purge books no WP-Cron event', ! $scheduled && array( $some_post ) === sec_pending(), 'scheduled=' . var_export( $scheduled, true ) );

sec_reset( array( $some_post, $template ) );
echo "\n" . $GLOBALS['sec_results']['pass'] . ' passed, ' . $GLOBALS['sec_results']['fail'] . ' failed, ' . $GLOBALS['sec_results']['skip'] . " skipped\n";
