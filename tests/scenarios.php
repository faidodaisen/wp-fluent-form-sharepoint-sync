<?php
// Usage: wp eval-file tests/scenarios.php
// Simulates a file-upload form (like Fluent Forms Pro) and runs the main sync scenarios against the mock.
$out = function ( $label, $ok, $extra = '' ) {
	WP_CLI::line( ( $ok ? '[PASS] ' : '[FAIL] ' ) . $label . ( $extra ? ' — ' . $extra : '' ) );
};
$repo = ffsp()->get( 'integrations' );
$logs = ffsp()->get( 'logs' );
$sync = ffsp()->get( 'sync' );

// 1. A form with name, ID number, email, date, checkbox and file upload.
$fields = array(
	'fields' => array(
		array( 'element' => 'input_text', 'attributes' => array( 'name' => 'full_name' ), 'settings' => array( 'label' => 'Full Name' ) ),
		array( 'element' => 'input_text', 'attributes' => array( 'name' => 'id_no' ), 'settings' => array( 'label' => 'ID Number' ) ),
		array( 'element' => 'input_email', 'attributes' => array( 'name' => 'email' ), 'settings' => array( 'label' => 'Email' ) ),
		array( 'element' => 'input_date', 'attributes' => array( 'name' => 'dob' ), 'settings' => array( 'label' => 'Date of Birth' ) ),
		array( 'element' => 'input_checkbox', 'attributes' => array( 'name' => 'interests' ), 'settings' => array( 'label' => 'Interests' ) ),
		array( 'element' => 'input_file', 'attributes' => array( 'name' => 'documents' ), 'settings' => array( 'label' => 'Dokumen' ) ),
	),
);
$form_id = (int) wpFluent()->table( 'fluentform_forms' )->where( 'title', 'FFSP Test Upload Form' )->value( 'id' );
if ( ! $form_id ) {
	$form_id = wpFluent()->table( 'fluentform_forms' )->insertGetId( array( 'title' => 'FFSP Test Upload Form', 'status' => 'published', 'form_fields' => wp_json_encode( $fields ), 'has_payment' => 0, 'type' => 'form', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
}
$parsed = ffsp()->get( 'forms' )->fields( $form_id );
$out( 'Field parser finds 6 fields incl. file', 6 === count( $parsed ) && $parsed['documents']['is_file'] );

// 2. Upload files into uploads/fluentform like Fluent does.
$up  = wp_get_upload_dir();
$dir = $up['basedir'] . '/fluentform';
wp_mkdir_p( $dir );
file_put_contents( $dir . '/ff-0123456789abcdef0123-id-copy.pdf', "%PDF-1.4\n% test pdf " . wp_generate_password( 40 ) );
file_put_contents( $dir . '/ff-fedcba9876543210fedc-photo#1.png', random_bytes( 2048 ) );
$files = array( $up['baseurl'] . '/fluentform/ff-0123456789abcdef0123-id-copy.pdf', $up['baseurl'] . '/fluentform/' . rawurlencode( 'ff-fedcba9876543210fedc-photo#1.png' ) );

$make_entry = function ( $response ) use ( $form_id ) {
	return wpFluent()->table( 'fluentform_submissions' )->insertGetId( array( 'form_id' => $form_id, 'serial_number' => wp_rand( 1, 9999 ), 'response' => wp_json_encode( $response ), 'source_url' => home_url( '/apply/' ), 'status' => 'unread', 'ip' => '127.0.0.1', 'browser' => 'Chrome', 'device' => 'Desktop', 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ) );
};
$entry = array( 'full_name' => 'Jane Doe', 'id_no' => '900101-14-5566', 'email' => 'jane@example.com', 'dob' => '15/03/1990', 'interests' => array( 'Design', 'Support' ), 'documents' => $files );

// 3. Integration (inline + link modes tested).
$int_id = 0;
foreach ( $repo->all() as $i ) {
	if ( 'upload-test' === $i['slug'] ) {
		$int_id = $i['id'];
	}
}
$base = array(
	'name' => 'Upload test', 'slug' => 'upload-test', 'form_id' => $form_id, 'status' => 'active', 'environment' => 'development', 'destination' => 'both', 'auth_mode' => 'shared_key',
	'endpoint' => \FFSP\Dev\MockReceiver::url(),
	'options'  => array( 'send_files' => 1, 'file_mode' => 'signed_link', 'include_meta' => 1, 'only_mapped' => 1, 'folder_template' => '{form_title}/{year}/{full_name}-{submission_id}', 'title_template' => '{full_name} ({submission_id})' ),
	'mapping'  => array(
		array( 'source' => 'full_name', 'target' => 'Title', 'type' => 'text', 'required' => 1 ),
		array( 'source' => 'id_no', 'target' => 'IdNumber', 'type' => 'text' ),
		array( 'source' => 'email', 'target' => 'Email', 'type' => 'email', 'required' => 1 ),
		array( 'source' => 'dob', 'target' => 'DateOfBirth', 'type' => 'date' ),
		array( 'source' => 'interests', 'target' => 'Interests', 'type' => 'multichoice' ),
		array( 'source' => '@submission_id', 'target' => 'WPEntryId', 'type' => 'number' ),
	),
);
$int_id = $repo->save( $base, $int_id );
$out( 'Integration saved', is_int( $int_id ), 'id ' . $int_id );

// 4. Happy path with signed links.
$sid = $make_entry( $entry );
$sync->handle_new_submission( $form_id, $sid ); // async -> queued
$log = $logs->find_by( $int_id, $sid );
$out( 'New entry queued (async)', 'queued' === $log['status'] );
$sync->process( $log['id'] ); // run the worker now
$log = $logs->find( $log['id'] );
$inbox = \FFSP\Dev\MockReceiver::inbox();
$p     = $inbox[0]['payload'];
$fc    = $inbox[0]['checks']['files'];
$out( 'Sent OK with signed links', 'success' === $log['status'] && $log['remote_item_id'], 'item ' . $log['remote_item_id'] );
$out( 'Signature/key verified by mock', 'valid' === $inbox[0]['checks']['signature'] );
$out( '2 files downloaded, sha256 match', 2 === count( $fc ) && $fc[0]['sha256_ok'] && $fc[1]['sha256_ok'], wp_json_encode( array_column( $fc, 'name' ) ) );
$out( 'Date d/m/Y -> Y-m-d', '1990-03-15' === $p['fields']['DateOfBirth'] );
$out( 'Multichoice array', array( 'Design', 'Support' ) === $p['fields']['Interests'] );
$out( 'Folder path rendered', false !== strpos( $p['sharepoint']['folderPath'], 'FFSP Test Upload Form/' . gmdate( 'Y' ) . '/Jane Doe-' . $sid ), $p['sharepoint']['folderPath'] );
$out( 'Clean file names', 'id-copy.pdf' === $fc[0]['name'] && 'photo-1.png' === $fc[1]['name'] );
$out( 'Remote files stored on log', 2 === count( json_decode( $log['remote_files'], true ) ) );

// 5. Signed link: reused beyond limit / tampered.
$tok_url = null;
$built   = ffsp()->get( 'payload' )->build( $repo->find( $int_id, true ), $sid, 'x' );
$tok_url = $built['payload']['files'][0]['downloadUrl'];
$bad     = wp_remote_get( $tok_url . 'x', array( 'sslverify' => false ) );
$out( 'Tampered token rejected (403)', 403 === wp_remote_retrieve_response_code( $bad ) );

// 6. Idempotency: resend same log -> mock returns duplicate with same item id.
$first_item = $log['remote_item_id'];
$sync->resend( $log['id'], true );
$log = $logs->find( $log['id'] );
$inbox = \FFSP\Dev\MockReceiver::inbox();
$out( 'Resend is idempotent (duplicate, same item)', 'duplicate' === $inbox[0]['response']['status'] && $first_item === $log['remote_item_id'] );

// 7. Inline base64 mode.
$base['options']['file_mode'] = 'inline_base64';
$repo->save( $base, $int_id );
$sid2 = $make_entry( $entry );
$l2   = $logs->ensure( $int_id, $form_id, $sid2 );
$sync->process( $l2['id'] );
$inbox = \FFSP\Dev\MockReceiver::inbox();
$out( 'Inline base64 mode delivers files', 'success' === $logs->find( $l2['id'] )['status'] && 'inline' === $inbox[0]['checks']['files'][0]['via'] && $inbox[0]['checks']['files'][0]['sha256_ok'] );
$base['options']['file_mode'] = 'signed_link';

// 8. Validation failure (required email empty) -> failed, no retry.
$sid3 = $make_entry( array_merge( $entry, array( 'email' => '' ) ) );
$l3   = $logs->ensure( $int_id, $form_id, $sid3 );
$sync->process( $l3['id'] );
$l3 = $logs->find( $l3['id'] );
$out( 'Required field empty => failed/validation, no retry', 'failed' === $l3['status'] && 'validation' === $l3['error_code'], $l3['error_message'] );

// 9. Missing file on disk.
$sid4 = $make_entry( array_merge( $entry, array( 'documents' => array( $up['baseurl'] . '/fluentform/nope.pdf' ) ) ) );
$l4   = $logs->ensure( $int_id, $form_id, $sid4 );
$sync->process( $l4['id'] );
$l4 = $logs->find( $l4['id'] );
$out( 'Missing file => failed with clear message', 'failed' === $l4['status'], $l4['error_message'] );

// 10. 503 => retrying with backoff; flaky => succeeds on 3rd attempt.
$base['endpoint'] = \FFSP\Dev\MockReceiver::url( array( 'fail' => 'flaky' ) );
$repo->save( $base, $int_id );
$sid5 = $make_entry( $entry );
$l5   = $logs->ensure( $int_id, $form_id, $sid5 );
$sync->process( $l5['id'] );
$a = $logs->find( $l5['id'] );
$out( 'Flaky #1 => retrying + next_attempt_at', 'retrying' === $a['status'] && 503 === (int) $a['http_code'] && $a['next_attempt_at'], 'next ' . $a['next_attempt_at'] );
$sched = as_has_scheduled_action( 'ffsp_process_log', array( 'log_id' => (int) $l5['id'] ), 'fluent-sharepoint-sync' );
$out( 'Retry scheduled in Action Scheduler', (bool) $sched );
$sync->process( $l5['id'] );
$sync->process( $l5['id'] );
$a = $logs->find( $l5['id'] );
$out( 'Flaky #3 => success', 'success' === $a['status'] && 3 === (int) $a['attempt_count'], 'attempts ' . $a['attempt_count'] );

// 11. 400 => failed immediately (no retry).
$base['endpoint'] = \FFSP\Dev\MockReceiver::url( array( 'fail' => '400' ) );
$repo->save( $base, $int_id );
$sid6 = $make_entry( $entry );
$l6   = $logs->ensure( $int_id, $form_id, $sid6 );
$sync->process( $l6['id'] );
$a = $logs->find( $l6['id'] );
$out( '400 => failed, not retried', 'failed' === $a['status'] && 1 === (int) $a['attempt_count'], $a['error_message'] );

// 12. Wrong secret => 401 failed.
$base['endpoint'] = \FFSP\Dev\MockReceiver::url();
$repo->save( $base, $int_id );
add_filter( 'ffsp/http_args', $f = function ( $args ) { $args['headers']['X-FFSP-Key'] = 'wrong'; return $args; } );
$sid7 = $make_entry( $entry );
$l7   = $logs->ensure( $int_id, $form_id, $sid7 );
$sync->process( $l7['id'] );
remove_filter( 'ffsp/http_args', $f );
$a = $logs->find( $l7['id'] );
$out( 'Wrong key => 401 failed', 'failed' === $a['status'] && 401 === (int) $a['http_code'] );
// ...then fix & manual resend recovers.
$sync->resend( $l7['id'], true );
$out( 'Manual resend after fix => success', 'success' === $logs->find( $l7['id'] )['status'] );

// 13. Inactive integration => new entries not synced.
$repo->set_status( $int_id, 'inactive' );
$sid8 = $make_entry( $entry );
$sync->handle_new_submission( $form_id, $sid8 );
$out( 'Inactive integration ignores new entries', null === $logs->find_by( $int_id, $sid8 ) );
$repo->set_status( $int_id, 'active' );

// 14. Secrets encrypted at rest + masked payload in log.
global $wpdb;
$raw = $wpdb->get_row( $wpdb->prepare( "SELECT endpoint, secret FROM {$wpdb->prefix}ffsp_integrations WHERE id=%d", $int_id ) );
$out( 'Endpoint + secret encrypted in DB', 0 === strpos( $raw->endpoint, 'ffsp1:' ) && 0 === strpos( $raw->secret, 'ffsp1:' ) );
$stored = $logs->find( $l7['id'] )['payload'];
$out( 'Stored payload masks ID + email', false === strpos( $stored, '900101-14-5566' ) && false === strpos( $stored, 'jane@example.com' ) );

// 15. SSRF guard with insecure off.
ffsp()->get( 'settings' )->update( array_merge( ffsp()->get( 'settings' )->all(), array( 'allow_insecure' => 0 ) ) );
$v1 = ffsp()->get( 'security' )->validate_endpoint( 'http://example.com/x' );
$v2 = ffsp()->get( 'security' )->validate_endpoint( 'https://127.0.0.1/x' );
$v3 = ffsp()->get( 'security' )->validate_endpoint( 'https://prod-12.southeastasia.logic.azure.com:443/workflows/abc/triggers/manual/paths/invoke?api-version=2016-06-01&sig=xyz' );
$out( 'http:// blocked, private IP blocked, logic.azure.com allowed', is_wp_error( $v1 ) && is_wp_error( $v2 ) && true === $v3 );
ffsp()->get( 'settings' )->update( array_merge( ffsp()->get( 'settings' )->all(), array( 'allow_insecure' => 1 ) ) );

WP_CLI::line( 'form_id=' . $form_id . ' integration_id=' . $int_id );
