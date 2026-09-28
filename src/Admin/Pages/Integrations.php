<?php
namespace FFSP\Admin\Pages;

use FFSP\Admin\Admin;
use FFSP\Dev\MockReceiver;
use FFSP\Mapping\FieldMapper;
use FFSP\Repository\IntegrationRepository;

defined( 'ABSPATH' ) || exit;

class Integrations extends Page {

	public function render() {
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( in_array( $action, array( 'new', 'edit' ), true ) ) {
			$this->edit( absint( $_GET['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$this->index();
	}

	private function index() {
		$items = $this->plugin->get( 'integrations' )->all();
		$forms = $this->plugin->get( 'forms' )->forms();
		$logs  = $this->plugin->get( 'logs' );

		echo '<div class="ffsp-toolbar"><a class="button button-primary" href="' . esc_url( Admin::url( 'integrations', array( 'action' => 'new' ) ) ) . '">+ ' . esc_html__( 'New integration', 'fluent-sharepoint-sync' ) . '</a></div>';

		if ( ! $items ) {
			echo '<div class="ffsp-panel ffsp-empty"><p>' . esc_html__( 'No integrations yet. An integration connects one Fluent Form to one Power Automate flow (SharePoint list and/or document library).', 'fluent-sharepoint-sync' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat striped ffsp-table"><thead><tr>';
		foreach ( array( __( 'Name', 'fluent-sharepoint-sync' ), __( 'Form', 'fluent-sharepoint-sync' ), __( 'Destination', 'fluent-sharepoint-sync' ), __( 'Environment', 'fluent-sharepoint-sync' ), __( 'Endpoint', 'fluent-sharepoint-sync' ), __( 'Status', 'fluent-sharepoint-sync' ), __( 'Last success', 'fluent-sharepoint-sync' ), '' ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $items as $i ) {
			$edit = Admin::url( 'integrations', array( 'action' => 'edit', 'id' => $i['id'] ) );
			$to   = 'active' === $i['status'] ? 'inactive' : 'active';
			echo '<tr>';
			echo '<td><strong><a href="' . esc_url( $edit ) . '">' . esc_html( $i['name'] ) . '</a></strong><br><small>' . count( $i['mapping'] ) . ' ' . esc_html__( 'mapped columns', 'fluent-sharepoint-sync' ) . '</small></td>';
			echo '<td>' . esc_html( $forms[ $i['form_id'] ] ?? ( '#' . $i['form_id'] . ' (missing)' ) ) . '</td>';
			echo '<td>' . esc_html( $this->dest_label( $i['destination'] ) ) . '</td>';
			echo '<td><span class="ffsp-env ffsp-env--' . esc_attr( $i['environment'] ) . '">' . esc_html( $i['environment'] ) . '</span></td>';
			echo '<td><code class="ffsp-mono">' . esc_html( $i['endpoint_masked'] ? $i['endpoint_masked'] : '—' ) . '</code></td>';
			echo '<td>' . $this->badge( $i['status'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . wp_kses_post( $this->time( $logs->last_success( $i['id'] ) ) ) . '</td>';
			echo '<td class="ffsp-row-actions"><a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'fluent-sharepoint-sync' ) . '</a> · ';
			echo '<a href="' . esc_url( $this->action_url( 'ffsp_toggle_integration', array( 'id' => $i['id'], 'to' => $to ) ) ) . '">' . esc_html( 'active' === $to ? __( 'Activate', 'fluent-sharepoint-sync' ) : __( 'Pause', 'fluent-sharepoint-sync' ) ) . '</a> · ';
			echo '<a href="' . esc_url( Admin::url( 'logs', array( 'integration_id' => $i['id'] ) ) ) . '">' . esc_html__( 'Logs', 'fluent-sharepoint-sync' ) . '</a> · ';
			echo '<a class="ffsp-danger" data-confirm="1" href="' . esc_url( $this->action_url( 'ffsp_delete_integration', array( 'id' => $i['id'] ) ) ) . '">' . esc_html__( 'Delete', 'fluent-sharepoint-sync' ) . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private function dest_label( $d ) {
		$labels = array(
			'list'    => __( 'SharePoint list', 'fluent-sharepoint-sync' ),
			'library' => __( 'Document library', 'fluent-sharepoint-sync' ),
			'both'    => __( 'List + library', 'fluent-sharepoint-sync' ),
		);
		return $labels[ $d ] ?? $d;
	}

	private function edit( $id ) {
		$repo  = $this->plugin->get( 'integrations' );
		$forms = $this->plugin->get( 'forms' )->forms();
		$item  = $id ? $repo->find( $id ) : null;

		if ( $id && ! $item ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Integration not found.', 'fluent-sharepoint-sync' ) . '</p></div>';
			return;
		}

		$defaults = array(
			'id'              => 0,
			'name'            => '',
			'form_id'         => 0,
			'status'          => 'inactive',
			'environment'     => 'production' === wp_get_environment_type() ? 'production' : 'development',
			'destination'     => 'list',
			'auth_mode'       => 'hmac',
			'mapping'         => array(),
			'options'         => IntegrationRepository::default_options(),
			'has_endpoint'    => false,
			'endpoint_masked' => '',
			'secret_masked'   => '',
		);
		$item = $item ? $item : $defaults;

		// Restore unsaved input after a validation error.
		$state = get_transient( 'ffsp_form_state_' . get_current_user_id() );
		if ( $state ) {
			delete_transient( 'ffsp_form_state_' . get_current_user_id() );
			$item = array_merge( $item, $state );
			$item['options'] = wp_parse_args( $state['options'], IntegrationRepository::default_options() );
			$item['mapping'] = FieldMapper::sanitize_mapping( (array) $state['mapping'] );
		}

		$reveal = get_transient( 'ffsp_reveal_' . get_current_user_id() );
		if ( $reveal ) {
			delete_transient( 'ffsp_reveal_' . get_current_user_id() );
			$parts  = explode( '|', (string) $this->plugin->get( 'security' )->decrypt( $reveal ), 2 );
			$reveal = ( 2 === count( $parts ) && (int) $parts[0] === (int) $item['id'] ) ? $parts[1] : '';
		}

		$transformers = $this->plugin->get( 'mapper' )->transformers()->options();
		$o            = $item['options'];

		echo '<p><a href="' . esc_url( Admin::url( 'integrations' ) ) . '">← ' . esc_html__( 'All integrations', 'fluent-sharepoint-sync' ) . '</a></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="ffsp-integration-form" class="ffsp-form">';
		wp_nonce_field( 'ffsp_save_integration' );
		echo '<input type="hidden" name="action" value="ffsp_save_integration"><input type="hidden" name="id" value="' . (int) $item['id'] . '">';

		echo '<div class="ffsp-grid">';

		/* ---------- Left column ---------- */
		echo '<div class="ffsp-col-main">';

		// 1. Basics.
		echo '<div class="ffsp-panel"><h2><span class="ffsp-step">1</span>' . esc_html__( 'Basics', 'fluent-sharepoint-sync' ) . '</h2><table class="form-table">';
		echo '<tr><th><label for="ffsp-name">' . esc_html__( 'Name', 'fluent-sharepoint-sync' ) . '</label></th><td><input id="ffsp-name" class="regular-text" name="name" required value="' . esc_attr( $item['name'] ) . '" placeholder="e.g. Contact form → SharePoint"></td></tr>';
		echo '<tr><th><label for="ffsp-form">' . esc_html__( 'Fluent Form', 'fluent-sharepoint-sync' ) . '</label></th><td><select id="ffsp-form" name="form_id" required><option value="">— ' . esc_html__( 'Select form', 'fluent-sharepoint-sync' ) . ' —</option>';
		foreach ( $forms as $fid => $title ) {
			printf( '<option value="%d" %s>%s (#%d)</option>', (int) $fid, selected( $item['form_id'], $fid, false ), esc_html( $title ), (int) $fid );
		}
		echo '</select></td></tr>';
		echo '<tr><th>' . esc_html__( 'Destination', 'fluent-sharepoint-sync' ) . '</th><td>';
		foreach ( array( 'list', 'library', 'both' ) as $d ) {
			printf( '<label class="ffsp-radio"><input type="radio" name="destination" value="%s" %s> %s</label>', esc_attr( $d ), checked( $item['destination'], $d, false ), esc_html( $this->dest_label( $d ) ) );
		}
		echo $this->help( __( 'Tells the flow where to write. The flow decides the actual list/library — this value is passed as <code>destination</code> in the payload.', 'fluent-sharepoint-sync' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</td></tr>';
		echo '<tr><th><label for="ffsp-env">' . esc_html__( 'Environment', 'fluent-sharepoint-sync' ) . '</label></th><td><select id="ffsp-env" name="environment">';
		foreach ( IntegrationRepository::ENVIRONMENTS as $e ) {
			printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $e ), selected( $item['environment'], $e, false ) );
		}
		echo '</select>' . $this->help( __( 'Use separate integrations (and flows) for staging and production so test data never lands in the live list.', 'fluent-sharepoint-sync' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</table></div>';

		// 2. Mapping.
		echo '<div class="ffsp-panel"><h2><span class="ffsp-step">2</span>' . esc_html__( 'Field mapping', 'fluent-sharepoint-sync' ) . '</h2>';
		echo $this->help( __( '<b>SharePoint column</b> = the column <i>internal name</i> (List settings → click the column → see <code>Field=</code> in the URL). Spaces become <code>_x0020_</code>.', 'fluent-sharepoint-sync' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<table class="widefat ffsp-mapping" id="ffsp-mapping"><thead><tr><th>' . esc_html__( 'Fluent field', 'fluent-sharepoint-sync' ) . '</th><th>' . esc_html__( 'SharePoint column', 'fluent-sharepoint-sync' ) . '</th><th>' . esc_html__( 'Type', 'fluent-sharepoint-sync' ) . '</th><th>' . esc_html__( 'Default / static value', 'fluent-sharepoint-sync' ) . '</th><th title="Required">' . esc_html__( 'Req.', 'fluent-sharepoint-sync' ) . '</th><th></th></tr></thead><tbody>';
		$rows = $item['mapping'] ? $item['mapping'] : array();
		foreach ( $rows as $idx => $row ) {
			$this->mapping_row( $idx, $row, $transformers );
		}
		echo '</tbody></table>';
		echo '<p class="ffsp-mapping-actions"><button type="button" class="button" id="ffsp-add-row">+ ' . esc_html__( 'Add column', 'fluent-sharepoint-sync' ) . '</button> <button type="button" class="button" id="ffsp-automap">' . esc_html__( 'Auto-map all fields', 'fluent-sharepoint-sync' ) . '</button> <span class="ffsp-muted" id="ffsp-fields-status"></span></p>';
		echo '<script type="text/template" id="ffsp-row-template">';
		$this->mapping_row( '__i__', array(), $transformers );
		echo '</script>';
		echo '</div>';

		// 3. Files + templates.
		echo '<div class="ffsp-panel"><h2><span class="ffsp-step">3</span>' . esc_html__( 'Files & naming', 'fluent-sharepoint-sync' ) . '</h2><table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Send uploaded files', 'fluent-sharepoint-sync' ) . '</th><td><label><input type="checkbox" name="options[send_files]" value="1" ' . checked( $o['send_files'], 1, false ) . '> ' . esc_html__( 'Include files from upload fields', 'fluent-sharepoint-sync' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'File delivery', 'fluent-sharepoint-sync' ) . '</th><td>';
		printf( '<label class="ffsp-radio"><input type="radio" name="options[file_mode]" value="signed_link" %s> <b>%s</b> — %s</label><br>', checked( $o['file_mode'], 'signed_link', false ), esc_html__( 'Signed download link', 'fluent-sharepoint-sync' ), esc_html__( 'recommended; the flow downloads each file (small payload, any size).', 'fluent-sharepoint-sync' ) );
		printf( '<label class="ffsp-radio"><input type="radio" name="options[file_mode]" value="inline_base64" %s> <b>%s</b> — %s</label>', checked( $o['file_mode'], 'inline_base64', false ), esc_html__( 'Inline base64', 'fluent-sharepoint-sync' ), esc_html__( 'file content inside the JSON; use when the site is not reachable from the internet.', 'fluent-sharepoint-sync' ) );
		echo '<p><label>' . esc_html__( 'Inline limit per file (KB)', 'fluent-sharepoint-sync' ) . ' <input type="number" class="small-text" name="options[inline_max_kb]" min="64" max="20480" value="' . (int) $o['inline_max_kb'] . '"></label></p></td></tr>';
		echo '<tr><th><label>' . esc_html__( 'Item title', 'fluent-sharepoint-sync' ) . '</label></th><td><input class="regular-text" name="options[title_template]" value="' . esc_attr( $o['title_template'] ) . '">' . $this->help( __( 'Tokens: <code>{form_title}</code> <code>{submission_id}</code> <code>{serial_number}</code> <code>{date}</code> <code>{year}</code> <code>{month}</code> or any field key e.g. <code>{email}</code>, <code>{names.first_name}</code>', 'fluent-sharepoint-sync' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<tr><th><label>' . esc_html__( 'Folder path', 'fluent-sharepoint-sync' ) . '</label></th><td><input class="regular-text" name="options[folder_template]" value="' . esc_attr( $o['folder_template'] ) . '">' . $this->help( __( 'Folder inside the document library, passed as <code>sharepoint.folderPath</code>.', 'fluent-sharepoint-sync' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</table></div>';

		// 4. Advanced.
		echo '<div class="ffsp-panel"><h2><span class="ffsp-step">4</span>' . esc_html__( 'Payload options', 'fluent-sharepoint-sync' ) . '</h2><table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Privacy', 'fluent-sharepoint-sync' ) . '</th><td><label><input type="checkbox" name="options[only_mapped]" value="1" ' . checked( $o['only_mapped'], 1, false ) . '> ' . esc_html__( 'Send only mapped fields (recommended)', 'fluent-sharepoint-sync' ) . '</label>' . $this->help( __( 'When off, a <code>raw</code> object with every form value is added too.', 'fluent-sharepoint-sync' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<tr><th>' . esc_html__( 'Submission meta', 'fluent-sharepoint-sync' ) . '</th><td><label><input type="checkbox" name="options[include_meta]" value="1" ' . checked( $o['include_meta'], 1, false ) . '> ' . esc_html__( 'Include IP, browser, device, user id', 'fluent-sharepoint-sync' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Retry', 'fluent-sharepoint-sync' ) . '</th><td><label><input type="checkbox" name="options[retry_on_4xx]" value="1" ' . checked( $o['retry_on_4xx'], 1, false ) . '> ' . esc_html__( 'Also auto-retry 4xx errors (normally only timeouts, 429 and 5xx are retried)', 'fluent-sharepoint-sync' ) . '</label></td></tr>';
		echo '</table></div>';

		echo '</div>';

		/* ---------- Right column ---------- */
		echo '<div class="ffsp-col-side">';

		echo '<div class="ffsp-panel"><h2>' . esc_html__( 'Status', 'fluent-sharepoint-sync' ) . '</h2>';
		printf( '<label class="ffsp-switch"><input type="checkbox" name="status" value="active" %s><span></span> %s</label>', checked( $item['status'], 'active', false ), esc_html__( 'Active — sync new submissions', 'fluent-sharepoint-sync' ) );
		echo '<p><button type="submit" class="button button-primary button-large">' . esc_html__( 'Save integration', 'fluent-sharepoint-sync' ) . '</button></p></div>';

		// Connection.
		echo '<div class="ffsp-panel"><h2>' . esc_html__( 'Connection', 'fluent-sharepoint-sync' ) . '</h2>';
		echo '<p><label for="ffsp-endpoint"><b>' . esc_html__( 'Power Automate HTTP URL', 'fluent-sharepoint-sync' ) . '</b></label>';
		echo '<textarea id="ffsp-endpoint" name="endpoint" rows="3" class="large-text ffsp-mono" autocomplete="off" placeholder="' . esc_attr( $item['has_endpoint'] ? __( 'Saved — leave empty to keep', 'fluent-sharepoint-sync' ) : 'https://prod-00.southeastasia.logic.azure.com/workflows/…' ) . '"></textarea>';
		if ( $item['has_endpoint'] ) {
			echo '<span class="ffsp-muted">' . esc_html__( 'Current:', 'fluent-sharepoint-sync' ) . ' <code class="ffsp-mono">' . esc_html( $item['endpoint_masked'] ) . '</code></span>';
		}
		echo '</p>';

		echo '<p><label><b>' . esc_html__( 'Authentication', 'fluent-sharepoint-sync' ) . '</b></label><br>';
		printf( '<label class="ffsp-radio"><input type="radio" name="auth_mode" value="hmac" %s> %s</label><br>', checked( $item['auth_mode'], 'hmac', false ), esc_html__( 'HMAC signature header (X-FFSP-Signature)', 'fluent-sharepoint-sync' ) );
		printf( '<label class="ffsp-radio"><input type="radio" name="auth_mode" value="shared_key" %s> %s</label></p>', checked( $item['auth_mode'], 'shared_key', false ), esc_html__( 'Shared key header (X-FFSP-Key) — simplest to check in a flow', 'fluent-sharepoint-sync' ) );

		echo '<p><label for="ffsp-secret"><b>' . esc_html__( 'Shared secret', 'fluent-sharepoint-sync' ) . '</b></label>';
		if ( $reveal ) {
			echo '<span class="ffsp-reveal"><code class="ffsp-mono" id="ffsp-revealed">' . esc_html( $reveal ) . '</code> <button type="button" class="button button-small" data-copy="#ffsp-revealed">' . esc_html__( 'Copy', 'fluent-sharepoint-sync' ) . '</button></span>';
		} elseif ( $item['id'] ) {
			echo '<br><code class="ffsp-mono">' . esc_html( $item['secret_masked'] ) . '</code> <a href="' . esc_url( $this->action_url( 'ffsp_rotate_secret', array( 'id' => $item['id'] ) ) ) . '" data-confirm="1">' . esc_html__( 'Generate new', 'fluent-sharepoint-sync' ) . '</a>';
		}
		echo '<input id="ffsp-secret" type="password" name="secret" class="large-text" autocomplete="new-password" placeholder="' . esc_attr( $item['id'] ? __( 'Leave empty to keep current', 'fluent-sharepoint-sync' ) : __( 'Leave empty to auto-generate', 'fluent-sharepoint-sync' ) ) . '"></p>';

		if ( $item['id'] ) {
			echo '<hr><p><b>' . esc_html__( 'Test', 'fluent-sharepoint-sync' ) . '</b></p>';
			echo '<p><select id="ffsp-test-sub" class="large-text"><option value="0">' . esc_html__( 'Synthetic ping (no form data)', 'fluent-sharepoint-sync' ) . '</option></select></p>';
			echo '<p><button type="button" class="button" id="ffsp-test" data-id="' . (int) $item['id'] . '">' . esc_html__( 'Send test', 'fluent-sharepoint-sync' ) . '</button> ';
			echo '<button type="button" class="button" id="ffsp-preview" data-id="' . (int) $item['id'] . '">' . esc_html__( 'Preview payload', 'fluent-sharepoint-sync' ) . '</button></p>';
			echo '<div id="ffsp-test-result" class="ffsp-result" hidden></div>';

			if ( MockReceiver::enabled() ) {
				echo '<hr><p><b>' . esc_html__( 'Local testing', 'fluent-sharepoint-sync' ) . '</b></p><p class="ffsp-muted">' . esc_html__( 'Point this integration to the built-in mock flow:', 'fluent-sharepoint-sync' ) . '</p><p>';
				echo '<a class="button button-small" href="' . esc_url( $this->action_url( 'ffsp_use_mock', array( 'id' => $item['id'] ) ) ) . '">' . esc_html__( 'Mock: success', 'fluent-sharepoint-sync' ) . '</a> ';
				echo '<a class="button button-small" href="' . esc_url( $this->action_url( 'ffsp_use_mock', array( 'id' => $item['id'], 'fail' => 'flaky' ) ) ) . '">' . esc_html__( 'Mock: flaky', 'fluent-sharepoint-sync' ) . '</a> ';
				echo '<a class="button button-small" href="' . esc_url( $this->action_url( 'ffsp_use_mock', array( 'id' => $item['id'], 'fail' => '500' ) ) ) . '">' . esc_html__( 'Mock: 500', 'fluent-sharepoint-sync' ) . '</a> ';
				echo '<a class="button button-small" href="' . esc_url( $this->action_url( 'ffsp_use_mock', array( 'id' => $item['id'], 'fail' => '400' ) ) ) . '">' . esc_html__( 'Mock: 400', 'fluent-sharepoint-sync' ) . '</a></p>';
			}
		}
		echo '</div>';

		echo '</div></div></form>';
	}

	private function mapping_row( $idx, array $row, array $transformers ) {
		$row  = wp_parse_args(
			$row,
			array(
				'source'   => '',
				'target'   => '',
				'type'     => 'text',
				'required' => 0,
				'default'  => '',
				'value'    => '',
			)
		);
		$name = 'mapping[' . $idx . ']';
		echo '<tr class="ffsp-map-row">';
		// Source select is filled by JS from the form's fields; keep current value as an option so it survives.
		echo '<td><select class="ffsp-source" name="' . esc_attr( $name ) . '[source]" data-value="' . esc_attr( $row['source'] ) . '">';
		if ( $row['source'] ) {
			echo '<option value="' . esc_attr( $row['source'] ) . '" selected>' . esc_html( $row['source'] ) . '</option>';
		}
		echo '</select></td>';
		echo '<td><input class="ffsp-target" name="' . esc_attr( $name ) . '[target]" value="' . esc_attr( $row['target'] ) . '" placeholder="Title"></td>';
		echo '<td><select name="' . esc_attr( $name ) . '[type]" class="ffsp-type">';
		foreach ( $transformers as $k => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $row['type'], $k, false ), esc_html( $label ) );
		}
		echo '</select></td>';
		$is_static = '@static' === $row['source'];
		$val       = $is_static && '' !== $row['value'] ? $row['value'] : $row['default'];
		echo '<td><input class="ffsp-default" name="' . esc_attr( $name ) . '[default]" value="' . esc_attr( $val ) . '" placeholder="' . esc_attr( $is_static ? 'static value / {token}' : 'optional' ) . '"></td>';
		echo '<td class="ffsp-center"><input type="checkbox" name="' . esc_attr( $name ) . '[required]" value="1" ' . checked( $row['required'], 1, false ) . '></td>';
		echo '<td><button type="button" class="button-link ffsp-remove" aria-label="Remove">×</button></td>';
		echo '</tr>';
	}
}
