=== Fluent Forms → SharePoint Sync ===
Contributors: armienagroup
Tags: fluent forms, sharepoint, power automate, microsoft 365, integration
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send Fluent Forms submissions — fields and uploaded files — to Microsoft SharePoint through a Power Automate HTTP endpoint.

== Description ==

Every new Fluent Forms entry is queued and posted as signed JSON to a Power Automate flow ("When a HTTP request is received"). The flow writes the SharePoint list item and stores the files, then replies with the item ID, which is saved on the entry.

* Per-form integration profiles with a visual field mapper (auto-map, transformers, required columns, static values).
* Files sent as short-lived signed download links or inline base64.
* Background queue (Action Scheduler) with automatic retry and exponential backoff.
* Idempotent: a stable request ID per entry, so a resend never creates a duplicate item.
* HMAC-SHA256 signature or shared-key header; endpoint URL and secret encrypted at rest.
* SSRF protection (HTTPS only, private addresses blocked, optional host allow-list).
* Sync logs with masked payloads, bulk resend and retention.
* Built-in mock receiver for local testing, and WP-CLI commands (`wp ffsp`).
* Updates delivered from GitHub Releases through the normal WordPress update screen.

Requires Fluent Forms (free or Pro). Uploads need a file-upload field from Fluent Forms Pro.

== Installation ==

1. Upload the plugin zip from Plugins → Add New → Upload Plugin and activate it.
2. Open Fluent Forms → SharePoint Sync → Setup Guide and build the Power Automate flow.
3. Add an integration, paste the flow URL, map the fields and run "Send test".

== Frequently Asked Questions ==

= Does Power Automate need a premium licence? =

The "When a HTTP request is received" trigger is a premium connector, so the flow owner needs a Power Automate Premium (or per-flow) licence.

= Does the site need HTTPS? =

Yes. Power Automate downloads uploaded files from the site through signed links.

== Changelog ==

= 1.0.1 =
* Plugin row shows a "Check for updates" link; updates are delivered from GitHub Releases.

= 1.0.0 =
* First public release.
