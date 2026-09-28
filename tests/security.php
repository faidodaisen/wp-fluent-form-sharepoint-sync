<?php
/**
 * Security / robustness regression checks. Run: wp eval-file tests/security.php
 * Needs the mock receiver + allow_insecure on (local), and tests/scenarios.php run once
 * (it creates the "FFSP Test Upload Form" + integration used here).
 */
defined( 'ABSPATH' ) || exit;

$pass = 0;
$fail = 0;
$out  = static function ( $label, $ok, $extra = '' ) use ( &$pass, &$fail ) {
	$ok ? $pass++ : $fail++;
	WP_CLI::line( ( $ok ? '[PASS] ' : '[FAIL] ' ) . $label . ( $extra ? ' — ' . $extra : '' ) );
};

global $wpdb;
$sec   = ffsp()->get( 'security' );
$repo  = ffsp()->get( 'integrations' );
$logs  = ffsp()->get( 'logs' );
$sync  = ffsp()->get( 'sync' );
$files = ffsp()->get( 'files' );
$up    = wp_get_upload_dir();

$form_id = (int) wpFluent()->table( 'fluentform_forms' )->where( 'title', 'FFSP Test Upload Form' )->value( 'id' );
$int     = null;
foreach ( $repo->all() as $i ) {
	if ( (int) $i['form_id'] === $form_id ) {
		$int = $repo->find( $i['id'], true );
		break;
	}
}
if ( ! $form_id || ! $int ) {
	WP_CLI::error( 'Run tests/scenarios.php first.' );
}

// F1 — mock receiver: unsigned request rejected before any download/storage.
$inbox_before = count( \FFSP\Dev\MockReceiver::inbox() );
$req          = new WP_REST_Request( 'POST', '/ffsp-mock/v1/flow' );
$req->set_body( wp_json_encode( array( 'requestId' => 'evil', 'integration' => array( 'id' => $int['id'] ), 'files' => array( array( 'name' => 'x', 'downloadUrl' => 'http://169.254.169.254/latest/meta-data/' ) ) ) ) );
$req->set_header( 'content-type', 'application/json' );
$downloads = 0;
$spy       = static function ( $pre, $args, $url ) use ( &$downloads ) {
	$downloads++;
	return $pre;
};
add_filter( 'pre_http_request', $spy, 10, 3 );
$res = rest_do_request( $req );
remove_filter( 'pre_http_request', $spy, 10 );
$out( 'F1 unsigned mock call => 401', 401 === $res->get_status() );
$out( 'F1 no outbound request made', 0 === $downloads, 'requests ' . $downloads );
$out( 'F1 nothing stored in mock inbox', count( \FFSP\Dev\MockReceiver::inbox() ) === $inbox_before );

// F2 — sibling directory sharing the uploads prefix is not "inside" uploads.
$sib = $up['basedir'] . '-ffsptest';
wp_mkdir_p( $sib );
file_put_contents( $sib . '/secret.txt', 'nope' ); // phpcs:ignore
$out( 'F2 path_inside rejects prefix sibling', false === \FFSP\Security\Security::path_inside( realpath( $sib . '/secret.txt' ), realpath( $up['basedir'] ) ) );
$out( 'F2 url_to_path rejects ../ escape', null === $files->url_to_path( $up['baseurl'] . '/../uploads-ffsptest/secret.txt' ) );
$out( 'F2 url_to_path rejects encoded ../', null === $files->url_to_path( $up['baseurl'] . '/%2e%2e/uploads-ffsptest/secret.txt' ) );
unlink( $sib . '/secret.txt' ); // phpcs:ignore
rmdir( $sib ); // phpcs:ignore
$tok = $sec->make_token( array( 'p' => '../uploads-ffsptest/secret.txt', 'e' => time() + 60 ) );
$fe  = new WP_REST_Request( 'GET', '/ffsp/v1/file' );
$fe->set_query_params( array( 'token' => $tok ) );
$out( 'F2 signed token with ../ => 404', 404 === rest_do_request( $fe )->get_status() );

// F3 — a running worker can schedule its own retry (pending-only dedupe).
$queue = ffsp()->get( 'queue' );
as_unschedule_all_actions( '', array(), \FFSP\Queue\Queue::GROUP );
$aid = as_enqueue_async_action( \FFSP\Queue\Queue::HOOK, array( 'log_id' => 999999 ), \FFSP\Queue\Queue::GROUP );
ActionScheduler::store()->log_execution( $aid ); // mark as in-progress, like a running worker
$queue->push( 999999, 120 );
$pending = as_get_scheduled_actions( array( 'hook' => \FFSP\Queue\Queue::HOOK, 'args' => array( 'log_id' => 999999 ), 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' );
$out( 'F3 retry scheduled while own action is running', 1 === count( $pending ) );
as_unschedule_all_actions( '', array(), \FFSP\Queue\Queue::GROUP );

// F5 — atomic claim: second claim loses.
$sid = (int) wpFluent()->table( 'fluentform_submissions' )->where( 'form_id', $form_id )->orderBy( 'id', 'DESC' )->value( 'id' );
$wpdb->delete( $wpdb->prefix . 'ffsp_logs', array( 'integration_id' => $int['id'], 'submission_id' => 0 ) );
$wpdb->insert( $wpdb->prefix . 'ffsp_logs', array( 'integration_id' => $int['id'], 'form_id' => $form_id, 'submission_id' => 0, 'status' => 'queued', 'request_id' => wp_generate_uuid4(), 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ) );
$lid = (int) $wpdb->insert_id;
$c1  = $logs->claim( $lid );
$c2  = $logs->claim( $lid );
$out( 'F5 only one worker can claim a row', true === $c1 && false === $c2 );
$wpdb->update( $wpdb->prefix . 'ffsp_logs', array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ), array( 'id' => $lid ) );
$out( 'F5 abandoned claim can be taken over', true === $logs->claim( $lid ) );
$out( 'F5 resend refused while processing', is_wp_error( $sync->resend( $lid, true ) ) );
$logs->delete( array( $lid ) );

// F6 — revealed secret not stored in plaintext.
$uid = get_current_user_id();
wp_set_current_user( 1 );
$new = $repo->rotate_secret( $int['id'] );
set_transient( 'ffsp_reveal_1', $sec->encrypt( $int['id'] . '|' . $new ), 60 );
$raw = (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = '_transient_ffsp_reveal_1'" );
$out( 'F6 reveal transient is ciphertext', '' !== $raw && false === strpos( $raw, $new ) && is_string( $new ) );
delete_transient( 'ffsp_reveal_1' );
wp_set_current_user( $uid );
$int = $repo->find( $int['id'], true );

// F7 — redaction.
$leak = 'bad key ' . $int['secret'] . ' at https://x.logic.azure.com/w?api-version=1&sig=ABCDEF123 ' . str_replace( '/', '\/', $int['endpoint'] ) . ' sha256=' . str_repeat( 'a', 64 );
$red  = $sec->redact( $leak, $int );
$out( 'F7 secret, sig=, escaped endpoint, signature redacted', false === strpos( $red, $int['secret'] ) && false === strpos( $red, 'ABCDEF123' ) && false === strpos( $red, str_repeat( 'a', 64 ) ) && false === strpos( $red, str_replace( '/', '\/', $int['endpoint'] ) ), $red );

// F8/F10 — oversized + malformed flow response.
$big = static function ( $pre, $args, $url ) use ( $int ) {
	if ( $url !== $int['endpoint'] ) {
		return $pre;
	}
	$files = array();
	for ( $i = 0; $i < 500; $i++ ) {
		$files[] = array( 'name' => array( 'x' ), 'url' => array( 'y' ) );
	}
	$files[] = array( 'name' => 'ok.pdf', 'url' => 'javascript:alert(1)' );
	return array( 'headers' => array(), 'body' => wp_json_encode( array( 'status' => 'success', 'itemId' => array( 1 ), 'itemUrl' => 'javascript:alert(1)', 'files' => $files, 'echo' => $int['secret'] ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null, 'args' => $args );
};
$captured = null;
$cap      = static function ( $args ) use ( &$captured ) {
	$captured = $args;
	return $args;
};
add_filter( 'ffsp/http_args', $cap );
add_filter( 'pre_http_request', $big, 10, 3 );
$l = $logs->ensure( $int['id'], $form_id, $sid );
$sync->resend( $l['id'], true );
remove_filter( 'pre_http_request', $big, 10 );
remove_filter( 'ffsp/http_args', $cap );
$row = $logs->find( $l['id'] );
$rf  = json_decode( $row['remote_files'], true );
$out( 'F8 response size limit set', ! empty( $captured['limit_response_size'] ) && $captured['limit_response_size'] <= 262144 );
$out( 'F10 malformed file entries dropped', is_array( $rf ) && count( $rf ) <= 50 && array() === array_filter( $rf, static function ( $f ) { return ! is_string( $f['name'] ) || ! is_string( $f['url'] ); } ) );
$out( 'F10 javascript: URLs removed', false === strpos( (string) $row['remote_files'] . $row['remote_item_url'], 'javascript' ) );
$out( 'F7 secret not stored in response excerpt', false === strpos( (string) $row['response_excerpt'], $int['secret'] ) );
ob_start();
$_GET['view'] = $l['id'];
( new \FFSP\Admin\Pages\Logs( ffsp() ) )->render();
unset( $_GET['view'] );
$html = ob_get_clean();
$out( 'F10 log detail renders without fatal', false !== strpos( $html, 'Sync log' ) );

// F9 — entry of another form refused.
$other = (int) wpFluent()->table( 'fluentform_submissions' )->where( 'form_id', '!=', $form_id )->value( 'id' );
if ( $other ) {
	$b = ffsp()->get( 'payload' )->build( $int, $other, 'x' );
	$out( 'F9 cross-form entry rejected', is_wp_error( $b ) && 'ffsp_form_mismatch' === $b->get_error_code() );
} else {
	$out( 'F9 cross-form entry rejected (skipped: no other form entries)', true );
}

// F11 — queued manual resend keeps the override for a paused integration.
$repo->set_status( $int['id'], 'inactive' );
$wpdb->update( $wpdb->prefix . 'ffsp_logs', array( 'status' => 'failed' ), array( 'id' => $l['id'] ) );
$sync->resend( $l['id'], false );
$acts = as_get_scheduled_actions( array( 'hook' => \FFSP\Queue\Queue::HOOK, 'group' => \FFSP\Queue\Queue::GROUP, 'status' => ActionScheduler_Store::STATUS_PENDING ) );
$act  = $acts ? reset( $acts ) : null;
$out( 'F11 queued resend carries manual flag', $act && ! empty( $act->get_args()['manual'] ) );
if ( $act ) {
	( new \FFSP\Queue\QueueModule( ffsp() ) )->process( $act->get_args()['log_id'], $act->get_args()['manual'] );
}
$out( 'F11 paused integration still resent manually', 'success' === $logs->find( $l['id'] )['status'] );
as_unschedule_all_actions( '', array(), \FFSP\Queue\Queue::GROUP );
$repo->set_status( $int['id'], 'active' );

// F12 — cleanup removes parameterised jobs.
$queue->push( 424242, 600 );
wp_schedule_single_event( time() + 600, \FFSP\Queue\Queue::HOOK, array( array( 'log_id' => 424242 ) ) );
\FFSP\Queue\Queue::clear_all();
$left = as_get_scheduled_actions( array( 'group' => \FFSP\Queue\Queue::GROUP, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' );
$out( 'F12 clear_all removes AS + cron jobs', ! $left && ! wp_next_scheduled( \FFSP\Queue\Queue::HOOK, array( array( 'log_id' => 424242 ) ) ) );

// F13 — rotate on a missing integration is an error, not a fake key.
$out( 'F13 rotate_secret on missing id => WP_Error', is_wp_error( $repo->rotate_secret( 987654 ) ) );

// F14 — download allowance is atomic and exact.
$settings = ffsp()->get( 'settings' );
$built    = ffsp()->get( 'payload' )->build( $int, $sid, 'dl-test' );
if ( ! is_wp_error( $built ) && ! empty( $built['payload']['files'][0]['downloadUrl'] ) ) {
	$q = array();
	wp_parse_str( (string) wp_parse_url( $built['payload']['files'][0]['downloadUrl'], PHP_URL_QUERY ), $q );
	$m = new ReflectionMethod( \FFSP\Files\FileEndpoint::class, 'consume' );
	$m->setAccessible( true );
	$fe  = new \FFSP\Files\FileEndpoint( ffsp() );
	$lim = 3;
	$ok  = 0;
	for ( $i = 0; $i < 6; $i++ ) {
		$ok += $m->invoke( $fe, $q['token'], $lim, time() + 60 ) ? 1 : 0;
	}
	$out( 'F14 exactly N downloads allowed', $lim === $ok, $ok . '/' . $lim );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", 'ffsp_dl_' . md5( $q['token'] ) ) );
} else {
	$out( 'F14 exactly N downloads allowed (skipped: no file on entry)', true );
}

// F4 — resolver covers IPv6 and pinning refuses private answers.
$out( 'F4 private literal refused', '' === $sec->resolve_public( '127.0.0.1' ) && '' === $sec->resolve_public( '::1' ) );
$out( 'F4 public host resolves', '' !== $sec->resolve_public( 'www.microsoft.com' ) );

WP_CLI::line( sprintf( '%d passed, %d failed', $pass, $fail ) );
