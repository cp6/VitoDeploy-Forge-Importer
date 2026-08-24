# VitoDeploy Forge Importer

[![Latest release](https://img.shields.io/github/v/release/cp6/VitoDeploy-Forge-Importer)](https://github.com/cp6/VitoDeploy-Forge-Importer/releases)
[![License](https://img.shields.io/github/license/cp6/VitoDeploy-Forge-Importer)](LICENSE)

A VitoDeploy 4.x plugin for previewing and importing one or many Laravel Forge sites into an existing Vito server. Every site and resource can be reviewed, edited, enabled, or skipped before the import starts.

The plugin uses Laravel Forge's current organization-scoped API. It only reads from Forge and never changes or deletes the source sites.

## Features

- Import a single site or multiple sites in one run (10 by default).
- Browse Forge organizations, servers, and sites from a Vito GUI.
- Compare Forge settings with the selected Vito server and show matched or blocked checks.
- Edit the proposed domain, aliases, site type, Linux user, PHP version, source control, repository, branch, web directory, Node version, port, and start command.
- Independently include or skip aliases, `.env`, deployment scripts, scheduled jobs, and background processes.
- Translate common Forge paths and deployment variables to their Vito equivalents.
- Run imports asynchronously, display progress, retain per-resource results, and retry partial failures.
- Encrypt import snapshots and user selections using Vito's Laravel application key.

## What can be imported

| Forge setting | Vito result | Notes |
| --- | --- | --- |
| Site/application | New Vito site | Domain, type, user, runtime, repository, branch, and web directory remain editable. |
| Primary domain and aliases | Domain and aliases | DNS is not changed. |
| Environment variables | Site `.env` | Values are never exposed in the preview response. |
| Deployment script | Vito deployment script | Common Forge variables and paths are translated; the script is not run by the importer. |
| Scheduled jobs | Vito cron jobs | Existing matching commands are skipped. |
| Background processes | Vito workers | Imported workers are configured for automatic start and restart. |

## Requirements

- VitoDeploy 4.x
- PHP 8.4 or newer
- A running Vito queue worker
- A ready destination server in the current Vito project
- The web server, PHP version, and process-manager services required by the selected sites
- A Vito source-control connection for repository-backed sites
- A Laravel Forge API token with these read-only scopes:
  - `organization:view`
  - `server:view`
  - `resources:view`

The plugin does not need a user-profile scope and does not use Forge's deprecated `/api/v1` API. See the [current Forge API documentation](https://laravel.com/forge/docs/api-reference/introduction).

## Installation

1. In Vito, open **Admin → Plugins**.
2. Choose the GitHub/quick-install option.
3. Enter `https://github.com/cp6/VitoDeploy-Forge-Importer`.
4. Install and enable **Forge Site Importer**.
5. Ensure the Vito queue worker is running.
6. Open a destination server and select **Features → Forge Importer → Open Importer**.

For local development, clone the repository into the Vito application at:

```text
app/Vito/Plugins/Cp6/VitoDeployForgeImporter
```

Then install and enable it from Vito's plugin administration screen.

## Using the importer

1. Create a read-only token in Laravel Forge with the scopes listed above.
2. Open the importer and connect the token.
3. Choose a Forge organization and server.
4. Select one or more Forge sites and a Vito destination server.
5. Tick the resource types Forge should inspect and generate the preview.
6. Review the matched/blocked checks and customize every proposed value.
7. Untick any site or resource that should not be imported.
8. Start the import and follow the progress report.
9. Transfer databases and application files separately, test the destination, change DNS, and issue new SSL certificates in Vito.

The Forge token remains in the encrypted Vito session while connected. Use **Disconnect** when finished.

## Configuration

Defaults are defined in [`config/forge-import.php`](config/forge-import.php):

| Option | Default | Purpose |
| --- | --- | --- |
| `base_url` | `https://forge.laravel.com/api` | Current Forge API base URL. |
| `request_timeout` | `25` | Forge request timeout in seconds. |
| `plan_ttl_minutes` | `30` | Lifetime of a generated preview before it must be regenerated. |
| `max_sites_per_run` | `10` | Maximum selected sites in one import. |
| `poll_seconds` | `20` | Delay while waiting for Vito site installation. |
| `drop_tables_on_uninstall` | `false` | Whether uninstalling removes importer history tables. |

Override these values through the host application's `forge-import` configuration when needed.

## Not migrated

- Database schemas, contents, users, or passwords
- Uploaded files, storage directories, or other shared application files
- DNS records
- SSL certificates or private keys
- Forge deploy keys and source-control credentials
- Deployment history and logs
- Raw Nginx configuration, redirects, or security rules
- Server provisioning and system-level services

These need separate transfer or re-creation because they involve live data, credentials, certificates, or infrastructure outside a Vito site definition.

## Safety and security

- Forge requests made by this plugin are read-only.
- The API token is encrypted in the authenticated Vito session and is not stored in an import run or queue payload.
- Import snapshots and selections are encrypted at rest with Vito's application key.
- Preview responses reveal `.env` key names, but not their values.
- Import routes require Vito authentication, access to the current project, and normal site-creation authorization.
- Cancelling an import stops future work; it does not delete Vito resources already created.
- Make backups and test the imported site before changing production DNS.

## Updating

Releases and tags are used by Vito's plugin updater. Review the release notes, update through Vito, and keep the queue worker running while an import is active.

## Development and tests

The tests run inside a VitoDeploy 4.x checkout after the plugin is placed at the local-development path shown above:

```bash
php artisan test app/Vito/Plugins/Cp6/VitoDeployForgeImporter/tests
```

## License

[MIT](LICENSE)
