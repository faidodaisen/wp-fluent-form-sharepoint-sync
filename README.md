# Fluent Forms → SharePoint Sync

WordPress plugin that sends Fluent Forms submissions (fields + uploaded files) to Microsoft SharePoint through a Power Automate HTTP endpoint.

## Install

Download `fluent-sharepoint-sync-<version>.zip` from the latest [release](../../releases/latest) and upload it in **Plugins → Add New → Upload Plugin**.

Updates then appear in **Dashboard → Updates** and on the Plugins screen like any other plugin. Use **Check for updates** on the plugin row to look for a new release straight away.

## Requirements

- WordPress 6.2+, PHP 7.4+
- Fluent Forms (free or Pro; file uploads need Pro)
- A Power Automate flow with the "When a HTTP request is received" trigger (premium connector)
- HTTPS on the WordPress site

## Setup

The full flow recipe, trigger JSON schema, headers and response contract are in **Fluent Forms → SharePoint Sync → Setup Guide** inside wp-admin.

## Development

```
wp eval-file wp-content/plugins/fluent-sharepoint-sync/tests/scenarios.php
wp eval-file wp-content/plugins/fluent-sharepoint-sync/tests/security.php
wp eval-file wp-content/plugins/fluent-sharepoint-sync/tests/updater.php
```

The suites need a local site with Fluent Forms active, "Allow insecure" and "Mock receiver" turned on in Settings.

## Releasing

Bump `Version:` in the plugin header, `FFSP_VERSION`, and `Stable tag` in `readme.txt`, then push to `main`. The release workflow tags `v<version>`, builds `fluent-sharepoint-sync-<version>.zip` and publishes the release. A push whose version is already tagged publishes nothing.

## License

GPL-2.0-or-later
