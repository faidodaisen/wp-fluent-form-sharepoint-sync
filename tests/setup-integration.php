<?php
// Usage: wp eval-file tests/setup-integration.php  — creates/updates a demo integration for form 1 against the mock receiver.
$repo = ffsp()->get( 'integrations' );
$existing = array_values( array_filter( $repo->all(), function ( $i ) { return 'demo-contact' === $i['slug']; } ) );
$id = $existing ? $existing[0]['id'] : 0;

ffsp()->get( 'settings' )->update( array_merge( ffsp()->get( 'settings' )->all(), array( 'log_payload' => 1, 'allow_insecure' => 1, 'mock_receiver' => 1 ) ) );

$res = $repo->save(
	array(
		'name'        => 'Demo Contact → SharePoint (mock)',
		'slug'        => 'demo-contact',
		'form_id'     => 1,
		'status'      => 'active',
		'environment' => 'development',
		'destination' => 'both',
		'auth_mode'   => 'hmac',
		'endpoint'    => \FFSP\Dev\MockReceiver::url(),
		'options'     => array( 'send_files' => 1, 'file_mode' => 'signed_link', 'include_meta' => 1, 'only_mapped' => 1 ),
		'mapping'     => array(
			array( 'source' => 'names.first_name', 'target' => 'FirstName', 'type' => 'text', 'required' => 1 ),
			array( 'source' => 'names.last_name', 'target' => 'LastName', 'type' => 'text' ),
			array( 'source' => 'email', 'target' => 'Email', 'type' => 'email', 'required' => 1 ),
			array( 'source' => 'subject', 'target' => 'Subject', 'type' => 'text', 'default' => '(no subject)' ),
			array( 'source' => 'message', 'target' => 'Message', 'type' => 'multiline' ),
			array( 'source' => '@submission_id', 'target' => 'WPEntryId', 'type' => 'number' ),
			array( 'source' => '@submitted_at', 'target' => 'SubmittedAt', 'type' => 'datetime' ),
			array( 'source' => '@static', 'target' => 'Source', 'type' => 'text', 'default' => 'Website - {form_title}' ),
		),
	),
	$id
);
var_dump( $res );
