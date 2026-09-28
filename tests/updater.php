<?php
/**
 * Updater release-selection tests. Run: wp eval-file tests/updater.php
 */
defined( 'ABSPATH' ) || exit;

use FFSP\Update\Updater;

$pass = 0;
$fail = 0;
$out  = static function ( $label, $ok, $extra = '' ) use ( &$pass, &$fail ) {
	$ok ? $pass++ : $fail++;
	WP_CLI::line( ( $ok ? '[PASS] ' : '[FAIL] ' ) . $label . ( $extra ? ' — ' . $extra : '' ) );
};

$rel = static function ( $tag, $extra = array() ) {
	return array_merge(
		array(
			'tag_name'     => $tag,
			'draft'        => false,
			'prerelease'   => false,
			'html_url'     => 'https://github.com/o/r/releases/tag/' . $tag,
			'body'         => "## Changes\n- item",
			'published_at' => '2026-01-01T00:00:00Z',
			'zipball_url'  => 'https://api.github.com/repos/o/r/zipball/' . $tag,
			'assets'       => array( array( 'name' => 'fluent-sharepoint-sync-' . ltrim( $tag, 'v' ) . '.zip', 'browser_download_url' => 'https://github.com/o/r/releases/download/' . $tag . '/fluent-sharepoint-sync.zip' ) ),
		),
		$extra
	);
};

$b = Updater::pick_release( array( $rel( 'v1.0.0' ), $rel( 'v1.2.0' ), $rel( 'v1.1.0' ) ) );
$out( 'Newest release chosen', '1.2.0' === $b['version'] );
$out( 'Built .zip asset preferred', false !== strpos( $b['package'], '/releases/download/' ) );
$b = Updater::pick_release( array( $rel( 'v1.0.0' ), $rel( 'v2.0.0', array( 'draft' => true ) ), $rel( 'v1.5.0', array( 'prerelease' => true ) ) ) );
$out( 'Drafts + pre-releases skipped', '1.0.0' === $b['version'] );
$b = Updater::pick_release( array( $rel( 'nightly' ), $rel( 'v1.0.0-beta' ) ) );
$out( 'Unparseable tags ignored', null === $b );
$b = Updater::pick_release( array( $rel( 'v1.3.0', array( 'assets' => array() ) ) ) );
$out( 'Zipball fallback when no asset', 0 === strpos( $b['package'], 'https://api.github.com/' ) );
$b = Updater::pick_release( array( $rel( 'v1.3.0', array( 'assets' => array(), 'zipball_url' => 'http://evil/x.zip' ) ) ) );
$out( 'Non-https package refused', null === $b );
$out( 'Equal version is not an update', ! Updater::is_newer( FFSP_VERSION ) );
$out( 'Higher version is an update', Updater::is_newer( '99.0.0' ) );

// Transient injection with a stubbed GitHub response.
Updater::clear_cache();
$stub = static function ( $pre, $args, $url ) use ( $rel ) {
	if ( false === strpos( $url, 'api.github.com/repos/' ) ) {
		return $pre;
	}
	return array( 'headers' => array(), 'body' => wp_json_encode( array( $rel( 'v99.0.0' ) ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
};
add_filter( 'pre_http_request', $stub, 10, 3 );
$t           = new stdClass();
$t->response = array();
$t           = ( new Updater( ffsp() ) )->inject_update( $t );
$base        = Updater::basename();
$out( 'Update injected into update_plugins transient', isset( $t->response[ $base ] ) && '99.0.0' === $t->response[ $base ]->new_version );
$calls = 0;
$cnt   = static function ( $pre ) use ( &$calls ) {
	$calls++;
	return $pre;
};
add_filter( 'pre_http_request', $cnt, 1 );
Updater::latest_release();
remove_filter( 'pre_http_request', $cnt, 1 );
$out( 'Second lookup served from cache', 0 === $calls );
remove_filter( 'pre_http_request', $stub, 10 );
Updater::clear_cache();

// Failure path backs off, no exception.
$err = static function () {
	return new WP_Error( 'http_request_failed', 'offline' );
};
add_filter( 'pre_http_request', $err );
$r = Updater::latest_release( true );
remove_filter( 'pre_http_request', $err );
$out( 'Network failure => null + cached error', null === $r && 'offline' === ( Updater::status()['error'] ?? '' ) );
Updater::clear_cache();

WP_CLI::line( sprintf( '%d passed, %d failed', $pass, $fail ) );
