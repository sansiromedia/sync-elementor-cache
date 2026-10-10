<?php
/**
 * Sync Elementor Cache - integration tests for the deferred / scoped purge routing.
 *
 * Run inside a real WordPress (STAGING ONLY - it purges caches) with the plugin active:
 *   wp eval-file wp-content/plugins/sync-elementor-cache/tests/integration-test.php
 *
 * Needs SG Optimizer active (checks use its siteground_optimizer_flush_cache action) and the
 * `wp` binary on PATH (the race test books a purge from a genuinely separate process).
 * Not shipped in the release zip.
 */

if ( ! class_exists( 'SEC_Purger' ) ) {
    echo "SKIP: plugin not active\n";
    return;
}

$GLOBALS['sec_results'] = array( 'pass' => 0, 'fail' => 0 );

function sec_t( $name, $ok, $detail = '' ) {
    $GLOBALS['sec_results'][ $ok ? 'pass' : 'fail' ]++;
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $detail ? " - $detail" : '' ) . "\n";
}

/** Everything pending, straight from the DB, in either storage format. Returns post ids + 'site'. */
function sec_pending() {
    global $wpdb;
    $out = array();
    foreach ( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'sec\\_pending\\_%'" ) as $n ) {
        if ( 'sec_pending_purge' === $n ) {
            $v = maybe_unserialize( $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name='sec_pending_purge'" ) );
            if ( is_array( $v ) ) {
                if ( ! empty( $v['site'] ) ) { $out[] = 'site'; }
                foreach ( array_keys( (array) ( $v['posts'] ?? array() ) ) as $p ) { $out[] = (int) $p; }
            }
            continue;
        }
        $suffix = substr( $n, strlen( 'sec_pending_' ) );
        $out[]  = 'site' === $suffix ? 'site' : (int) $suffix;
    }
    sort( $out );
    return $out;
}

function sec_reset() {
    global $wpdb;
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'sec\\_pending\\_%' OR option_name LIKE 'sec\\_purge\\_last\\_%' OR option_name LIKE '\\_transient\\_sec\\_thr\\_%' OR option_name LIKE '\\_transient\\_timeout\\_sec\\_thr\\_%'" );
    wp_cache_delete( 'sec_pending_purge', 'options' );
    wp_cache_delete( 'notoptions', 'options' );
    wp_cache_delete( 'alloptions', 'options' );
    foreach ( array( 1, 2, 3, 900001, 900002, 900003 ) as $id ) { delete_transient( 'sec_thr_' . $id ); }
    delete_transient( 'sec_thr_site' );
    wp_clear_scheduled_hook( SEC_Purger::HOOK_DEFERRED );
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

/** Fire $hook with ONLY the callbacks defined in this plugin's main file (other plugins' listeners can't take fakes). */
function sec_do_own_action( $hook, ...$args ) {
    global $wp_filter;
    $backup = isset( $wp_filter[ $hook ] ) ? clone $wp_filter[ $hook ] : null;
    if ( $backup ) {
        foreach ( $backup->callbacks as $prio => $cbs ) {
            foreach ( $cbs as $id => $cb ) {
                $f = $cb['function'];
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

$sec_ref = new ReflectionClass( 'SEC_Purger' );
$sec_priv = function ( $name ) use ( $sec_ref ) { $m = $sec_ref->getMethod( $name ); $m->setAccessible( true ); return $m; };

// 0. Version constant matches the header.
$hdr = get_file_data( SEC_PLUGIN_FILE, array( 'v' => 'Version' ) );
sec_t( 'version constant matches header', SEC_PLUGIN_VERSION === $hdr['v'], SEC_PLUGIN_VERSION . ' vs ' . $hdr['v'] );

// 1. Race: a purge booked by another process between our read and our write must survive.
sec_reset();
SEC_Purger::defer( SEC_Purger::SCOPE_POST, 900001 );
$cmd = 'wp eval ' . escapeshellarg( 'SEC_Purger::defer( SEC_Purger::SCOPE_POST, 900002 );' ) . ' --path=' . escapeshellarg( ABSPATH ) . ' 2>&1';
shell_exec( $cmd );
SEC_Purger::defer( SEC_Purger::SCOPE_POST, 900003 );
$p = sec_pending();
sec_t( 'concurrent defer from another process is not lost', in_array( 900002, $p, true ) && in_array( 900001, $p, true ) && in_array( 900003, $p, true ), 'pending=' . json_encode( $p ) );

// 2. Throttle is per post: page Y is not held back because page X was just saved.
sec_reset();
$thr = $sec_priv( 'is_throttled' );
$x = $thr->invoke( null, SEC_Purger::SCOPE_POST, 1 );
$y = $thr->invoke( null, SEC_Purger::SCOPE_POST, 2 );
$x2 = $thr->invoke( null, SEC_Purger::SCOPE_POST, 1 );
sec_t( 'first save of a different page is not throttled', false === $x && false === $y, "x=" . var_export( $x, true ) . " y=" . var_export( $y, true ) );
sec_t( 'repeat save of the same page is throttled', true === $x2 );

// 8. The throttle does not write a database option per save.
global $wpdb;
$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'sec\\_purge\\_last\\_%'" );
sec_t( 'throttle writes no sec_purge_last_* option', 0 === $rows, "$rows row(s)" );

// 3. WP-CLI: a purge request is flushed when the process ends, not left to WP-Cron.
sec_reset();
SEC_Purger::purge_all( 900001 );
$on_shutdown = false !== has_action( 'shutdown', array( 'SEC_Purger', 'run_deferred' ) );
sec_t( 'CLI purge request is flushed at shutdown', $on_shutdown );
sec_t( 'overdue fallback hooked on admin_init', false !== has_action( 'admin_init', array( 'SEC_Purger', 'maybe_run_overdue' ) ) );

// 4. A post-scope purge still soft-purges SG domain-wide (home URL), as 4.6.x did.
sec_reset();
$some_post = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish' ORDER BY ID LIMIT 1" );
$urls = sec_capture_sg( function () use ( $some_post ) { SEC_Purger::execute( $some_post, SEC_Purger::SCOPE_POST, false ); } );
sec_t( 'post save soft-purges SG domain-wide, never just the page URL', count( $urls ) >= 1 && array( get_home_url( '/' ) ) === array_values( array_unique( $urls ) ), json_encode( $urls ) );

// 5. Nested purge requests inside purge_site() do not re-run the site-wide purge.
sec_reset();
$prop = $sec_ref->getProperty( 'purging' );
$prop->setAccessible( true );
$prop->setValue( null, true );
$urls = sec_capture_sg( function () { SEC_Purger::request_purge( SEC_Purger::SCOPE_SITE, null ); } );
$prop->setValue( null, false );
sec_t( 'nested request inside purge_site() is a no-op', 0 === count( $urls ) && array() === sec_pending(), count( $urls ) . ' SG purge(s), pending=' . json_encode( sec_pending() ) );

// 6. Many deferred posts collapse to ONE site purge instead of N sequential URL purges.
sec_reset();
$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish' ORDER BY ID LIMIT 25" );
foreach ( $ids as $id ) { SEC_Purger::defer( SEC_Purger::SCOPE_POST, (int) $id ); }
$per_post = array();
$urls = sec_capture_sg( function () use ( &$per_post ) { $per_post = sec_count_post_purges( function () { SEC_Purger::run_deferred(); } ); } );
wp_cache_delete( 'sec_last_purge', 'options' );
$last = get_option( 'sec_last_purge' );
sec_t( count( $ids ) . ' deferred posts collapse to one site purge', 0 === array_sum( $per_post ) && count( $urls ) <= 2 && 'site' === ( $last['scope'] ?? '' ), array_sum( $per_post ) . ' per-post purge(s), ' . count( $urls ) . ' SG call(s), last scope ' . ( $last['scope'] ?? '?' ) );
sec_t( 'run_deferred clears what it ran', array() === sec_pending() );

// 7. Deactivation cleans up the scheduled event and pending rows.
sec_reset();
SEC_Purger::defer( SEC_Purger::SCOPE_POST, 900001 );
if ( ! wp_next_scheduled( SEC_Purger::HOOK_DEFERRED ) ) { wp_schedule_single_event( time() + 60, SEC_Purger::HOOK_DEFERRED ); }
do_action( 'deactivate_' . plugin_basename( SEC_PLUGIN_FILE ), false );
sec_t( 'deactivation removes pending rows + scheduled event', array() === sec_pending() && ! wp_next_scheduled( SEC_Purger::HOOK_DEFERRED ), 'pending=' . json_encode( sec_pending() ) . ' event=' . var_export( (bool) wp_next_scheduled( SEC_Purger::HOOK_DEFERRED ), true ) );


// 9. Interactive: one save firing several hooks purges once and books no trailing purge.
sec_reset();
add_filter( 'sec_is_interactive', '__return_true' );
$n = sec_count_post_purges( function () use ( $some_post ) {
    SEC_Purger::purge_all( $some_post );
    SEC_Purger::purge_all( $some_post );
    SEC_Purger::purge_all( $some_post );
} );
remove_filter( 'sec_is_interactive', '__return_true' );
sec_t( 'one save, three hooks: one purge, nothing deferred', 1 === ( $n[ $some_post ] ?? 0 ) && array() === sec_pending(), ( $n[ $some_post ] ?? 0 ) . ' purge(s), pending=' . json_encode( sec_pending() ) );

// 10. An Elementor autosave does not purge (and so cannot use up the window).
sec_reset();
delete_transient( 'sec_thr_' . $some_post );
$fake = new class( $some_post ) {
    private $id;
    public function __construct( $id ) { $this->id = $id; }
    public function get_main_id() { return $this->id; }
    public function is_autosave() { return true; }
};
add_filter( 'sec_is_interactive', '__return_true' );
$urls = sec_capture_sg( function () use ( $fake ) { sec_do_own_action( 'elementor/document/after_save', $fake, array() ); } );
$thr_after = get_transient( 'sec_thr_' . $some_post );
remove_filter( 'sec_is_interactive', '__return_true' );
sec_t( 'autosave does not purge or start the throttle window', 0 === count( $urls ) && ! $thr_after && array() === sec_pending(), count( $urls ) . ' SG purge(s), throttle=' . var_export( (bool) $thr_after, true ) );

// 11. purge_site() cancels pending work it already covers.
sec_reset();
SEC_Purger::defer( SEC_Purger::SCOPE_POST, 900001 );
SEC_Purger::defer( SEC_Purger::SCOPE_SITE );
SEC_Purger::purge_site();
sec_t( 'purge_site clears pending work + scheduled event', array() === sec_pending() && ! wp_next_scheduled( SEC_Purger::HOOK_DEFERRED ), 'pending=' . json_encode( sec_pending() ) );

sec_reset();
echo "\n" . $GLOBALS['sec_results']['pass'] . ' passed, ' . $GLOBALS['sec_results']['fail'] . " failed\n";
