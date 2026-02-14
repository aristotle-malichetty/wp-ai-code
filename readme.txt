=== WP AI Code ===
Contributors: wpaicode
Tags: ai, deployment, rest-api, developer-tools, automation
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enables AI coding agents to push theme and plugin files to WordPress through authenticated REST API endpoints with mandatory human approval.

== Description ==

WP AI Code fills a gap in the WordPress-AI ecosystem: existing tools handle content management, but none support secure file-level code deployment from AI agents.

**How It Works:**

1. An AI agent (like Claude Code) submits files via the REST API
2. Files are staged and validated automatically (path traversal checks, dangerous pattern scanning, PHP syntax verification)
3. A human administrator reviews the submission in the WordPress dashboard
4. The admin approves or rejects — only approved deployments go live
5. Full rollback capability if anything goes wrong

**Key Features:**

* Authenticated REST API for file submissions
* Mandatory human approval workflow
* Comprehensive file validation pipeline
* Automatic backup and rollback support
* Admin dashboard for reviewing deployments
* Rate limiting and audit logging
* Kill switch for emergency disable

== Installation ==

1. Upload the `wp-ai-code` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu
3. Go to AI Code > Settings to configure
4. Create an Application Password for your admin user (Users > Profile > Application Passwords)
5. Use the Application Password with Basic Auth to authenticate API requests

== Frequently Asked Questions ==

= What AI agents are supported? =

Any tool that can make authenticated HTTP requests to the WordPress REST API. This includes Claude Code, GitHub Copilot Workspace, and custom scripts.

= Is HTTPS required? =

HTTPS is strongly recommended and enforced by default in production. The check can be bypassed in local development environments.

= What file types can be deployed? =

PHP, CSS, JS, JSON, TXT, MD, HTML, Twig templates, SVG, images (PNG, JPG, GIF), and web fonts (WOFF, WOFF2, TTF, EOT).

== Changelog ==

= 1.0.0 =
* Initial release
* REST API endpoints for file deployment
* Human approval workflow
* File validation pipeline
* Admin dashboard
* Backup and rollback support
