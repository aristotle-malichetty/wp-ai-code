# WP AI Code — Development Context

## What This Plugin Does
WP AI Code enables AI coding agents (like Claude Code) to push theme/plugin files to a WordPress site through authenticated REST API endpoints, with mandatory human approval before deployment.

## Architecture
- **Namespace**: `WPAICode\`
- **Prefix**: `wpaic_`
- **PHP**: 8.0+
- **WordPress**: 6.0+
- **No external dependencies** — uses only WordPress core APIs

## Key Files
- `wp-ai-code.php` — Main plugin entry, constants, autoloader, activation/deactivation
- `includes/class-plugin.php` — Singleton that wires hooks
- `includes/class-deployment-store.php` — Database CRUD for deployments table
- `includes/class-auth.php` — Permission checks, rate limiting, audit logging
- `includes/class-validator.php` — File validation pipeline (paths, types, sizes, PHP syntax, dangerous patterns)
- `includes/class-deployer.php` — Stage files, execute deployments, rollback, cleanup
- `includes/class-rest-api.php` — REST API endpoints under `wp-ai-code/v1`
- `admin/class-admin-page.php` — Admin menu, settings, dashboard
- `admin/views/` — Dashboard, detail, and settings templates

## REST API Endpoints (wp-ai-code/v1)
All require `manage_options` capability (admin + application password).

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/deploy` | POST | Submit files for deployment |
| `/deployments` | GET | List deployments |
| `/deployments/{id}` | GET | Get single deployment |
| `/deployments/{id}/approve` | POST | Approve & deploy |
| `/deployments/{id}/reject` | POST | Reject deployment |
| `/deployments/{id}/rollback` | POST | Rollback deployed change |
| `/status` | GET | Health check |

## Deployment Flow
1. Agent POSTs files to `/deploy` → files staged, record created as `pending`
2. Human reviews in wp-admin dashboard or via API
3. Approve → files deployed to target; Reject → marked rejected
4. Rollback available after deployment

## Conventions
- All DB queries use `$wpdb->prepare()`
- All file ops use `WP_Filesystem` API
- All output escaped with `esc_html()`, `esc_attr()`, etc.
- Options prefixed `wpaic_`
- Table: `{prefix}wpaic_deployments`
- Staging dir: `wp-content/wpaic-staging/`
