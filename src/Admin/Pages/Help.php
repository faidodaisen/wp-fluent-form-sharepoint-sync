<?php
namespace FFSP\Admin\Pages;

use FFSP\Dev\MockReceiver;

defined( 'ABSPATH' ) || exit;

/**
 * In-plugin setup guide: how to build the Power Automate flow that receives submissions.
 */
class Help extends Page {

	public function render() {
		$sample = array(
			'type'       => 'object',
			'properties' => array(
				'schemaVersion' => array( 'type' => 'string' ),
				'requestId'     => array( 'type' => 'string' ),
				'event'         => array( 'type' => 'string' ),
				'environment'   => array( 'type' => 'string' ),
				'destination'   => array( 'type' => 'string' ),
				'source'        => array(
					'type'       => 'object',
					'properties' => array(
						'formId'       => array( 'type' => 'integer' ),
						'formTitle'    => array( 'type' => 'string' ),
						'submissionId' => array( 'type' => 'integer' ),
						'submittedAt'  => array( 'type' => 'string' ),
						'entryUrl'     => array( 'type' => 'string' ),
					),
				),
				'sharepoint'    => array(
					'type'       => 'object',
					'properties' => array(
						'title'      => array( 'type' => 'string' ),
						'folderPath' => array( 'type' => 'string' ),
					),
				),
				'fields'        => array( 'type' => 'object' ),
				'files'         => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'field'        => array( 'type' => 'string' ),
							'name'         => array( 'type' => 'string' ),
							'size'         => array( 'type' => 'integer' ),
							'mimeType'     => array( 'type' => 'string' ),
							'sha256'       => array( 'type' => 'string' ),
							'downloadUrl'  => array( 'type' => 'string' ),
							'contentBytes' => array( 'type' => 'string' ),
						),
					),
				),
			),
		);
		?>
		<div class="ffsp-panel ffsp-guide">
			<h2><?php esc_html_e( 'How it works', 'fluent-sharepoint-sync' ); ?></h2>
			<p><code>Fluent Forms</code> → <b>this plugin</b> (map + queue + sign) → <code>Power Automate HTTP trigger</code> → <code>SharePoint list item / document library</code> → response <code>{ itemId, itemUrl }</code> → <b>Sync Logs</b>.</p>
			<p><?php esc_html_e( 'WordPress never stores Microsoft credentials. Your Power Automate flow owns the SharePoint connection; this plugin only needs the flow URL.', 'fluent-sharepoint-sync' ); ?></p>

			<h2><?php esc_html_e( 'Before you start', 'fluent-sharepoint-sync' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'A SharePoint site with the target list (and a document library if you want to store uploaded files).', 'fluent-sharepoint-sync' ); ?></li>
				<li><?php esc_html_e( 'The internal name and type of every column you want to fill.', 'fluent-sharepoint-sync' ); ?></li>
				<li><?php esc_html_e( 'Power Automate with access to the premium "When a HTTP request is received" trigger. Build the flow from the steps below and copy its HTTP POST URL. Tip: use a separate flow for staging and production.', 'fluent-sharepoint-sync' ); ?></li>
				<li><?php esc_html_e( 'For file uploads: decide the folder naming and check your library\'s size and file-type limits.', 'fluent-sharepoint-sync' ); ?></li>
			</ol>

			<h2><?php esc_html_e( 'Flow steps (Power Automate)', 'fluent-sharepoint-sync' ); ?></h2>
			<ol>
				<li><b>When a HTTP request is received</b> — <?php esc_html_e( 'paste the JSON schema below. Who can trigger: Anyone (the URL signature plus the shared key or HMAC signature protect it).', 'fluent-sharepoint-sync' ); ?></li>
				<li><b>Condition</b> — <code>triggerOutputs()?['headers']?['X-FFSP-Key']</code> <?php esc_html_e( 'equals your shared key (Shared key mode). Otherwise → Response 401.', 'fluent-sharepoint-sync' ); ?></li>
				<li><b>Get items</b> (<?php esc_html_e( 'optional, idempotency', 'fluent-sharepoint-sync' ); ?>) — <?php esc_html_e( 'filter', 'fluent-sharepoint-sync' ); ?> <code>RequestId eq '@{triggerBody()?['requestId']}'</code>. <?php esc_html_e( 'If found → Response 200 with status "duplicate" and the existing ID.', 'fluent-sharepoint-sync' ); ?></li>
				<li><b>Create item</b> — <?php esc_html_e( 'map each column to', 'fluent-sharepoint-sync' ); ?> <code>triggerBody()?['fields']?['ColumnName']</code>; Title ← <code>sharepoint.title</code>; RequestId ← <code>requestId</code>.</li>
				<li><b>Apply to each</b> <code>files</code>:
					<ul>
						<li><b>HTTP</b> GET <code>items('Apply_to_each')?['downloadUrl']</code> (<?php esc_html_e( 'or use', 'fluent-sharepoint-sync' ); ?> <code>base64ToBinary(contentBytes)</code> <?php esc_html_e( 'in inline mode', 'fluent-sharepoint-sync' ); ?>)</li>
						<li><b>Add attachment</b> (<?php esc_html_e( 'list', 'fluent-sharepoint-sync' ); ?>) <?php esc_html_e( 'or', 'fluent-sharepoint-sync' ); ?> <b>Create file</b> (<?php esc_html_e( 'library, folder =', 'fluent-sharepoint-sync' ); ?> <code>sharepoint.folderPath</code>)</li>
					</ul>
				</li>
				<li><b>Response</b> 200 — <?php esc_html_e( 'body:', 'fluent-sharepoint-sync' ); ?>
<pre class="ffsp-pre">{ "status": "success", "itemId": "@{outputs('Create_item')?['body/ID']}", "itemUrl": "@{outputs('Create_item')?['body/{Link}']}" }</pre></li>
				<li><?php esc_html_e( 'Configure run-after on a Scope so failures return Response 500 with', 'fluent-sharepoint-sync' ); ?> <code>{"status":"error","message":"..."}</code> — <?php esc_html_e( 'the plugin will retry.', 'fluent-sharepoint-sync' ); ?></li>
			</ol>

			<h2><?php esc_html_e( 'Trigger JSON schema', 'fluent-sharepoint-sync' ); ?></h2>
			<pre class="ffsp-pre" id="ffsp-schema"><?php echo esc_html( wp_json_encode( $sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
			<button type="button" class="button" data-copy="#ffsp-schema"><?php esc_html_e( 'Copy schema', 'fluent-sharepoint-sync' ); ?></button>

			<h2><?php esc_html_e( 'Headers sent', 'fluent-sharepoint-sync' ); ?></h2>
			<table class="widefat striped"><tbody>
				<tr><td><code>X-FFSP-Request-Id</code></td><td><?php esc_html_e( 'Idempotency key, stable across retries of the same entry.', 'fluent-sharepoint-sync' ); ?></td></tr>
				<tr><td><code>X-FFSP-Timestamp</code></td><td><?php esc_html_e( 'Unix time of the request.', 'fluent-sharepoint-sync' ); ?></td></tr>
				<tr><td><code>X-FFSP-Signature</code></td><td><code>sha256=HMAC_SHA256(secret, timestamp + "." + body)</code></td></tr>
				<tr><td><code>X-FFSP-Key</code></td><td><?php esc_html_e( 'Shared secret (only in Shared key mode).', 'fluent-sharepoint-sync' ); ?></td></tr>
			</tbody></table>

			<h2><?php esc_html_e( 'Developer hooks', 'fluent-sharepoint-sync' ); ?></h2>
			<ul class="ffsp-mono">
				<li>ffsp/modules — add/remove modules</li>
				<li>ffsp/should_sync ( bool, integration, submission_id, form_id )</li>
				<li>ffsp/payload ( payload, integration, submission, form )</li>
				<li>ffsp/transformers — register value transformers</li>
				<li>ffsp/http_args ( args, integration )</li>
				<li>ffsp/file_descriptor ( item, path, integration )</li>
				<li>ffsp/sync_success, ffsp/sync_failed — actions</li>
				<li>ffsp/capability — default manage_options</li>
				<li>WP-CLI: wp ffsp status | integrations | send &lt;entry&gt; | resend &lt;log&gt; | retry-failed | test &lt;integration&gt;</li>
			</ul>
		</div>
		<?php
	}
}
