# WP AI Code — Setup Guide

## Prerequisites

- WordPress site running locally (Local by Flywheel, MAMP, wp-env, etc.)
- Admin access to the WordPress site
- HTTPS enabled (Local enables this by default)

---

## Step 1: Install the Plugin

### Option A: Symlink (for development)

```bash
ln -s /path/to/wp-ai-code /path/to/your-site/wp-content/plugins/wp-ai-code
```
```bash
ln -s "/Users/aris/Downloads/Aris Apps - Cursor/wordpress-claude" \ "/Users/aris/Local Sites/aristotle-malichetty/app/public/wp-content/plugins/wp-ai-code"
```

### Option B: Copy

Copy the plugin folder into your site's `wp-content/plugins/` directory.

### Option C: ZIP Upload

1. Download the plugin as a ZIP from GitHub
2. Go to **Plugins > Add New > Upload Plugin** in wp-admin
3. Upload the ZIP and activate

---

## Step 2: Activate the Plugin

1. Go to `https://aristotle.local/wp-admin/plugins.php`
2. Find **WP AI Code** in the list
3. Click **Activate**
4. You should now see **AI Code** in the admin sidebar

---

## Step 3: Create an Application Password

WordPress Application Passwords let external tools (like Claude Code) authenticate with your site without using your actual password.

1. Go to `https://aristotle.local/wp-admin/profile.php`
2. Scroll down to **Application Passwords**
3. Enter a name: `claude-code`
4. Click **Add New Application Password**
5. **Copy the generated password immediately** — you won't see it again
6. It looks like: `XXXX XXXX XXXX XXXX XXXX XXXX`

---

## Step 4: Verify the Connection

Open your terminal and run:

```bash
curl -sk -u "YOUR_USERNAME:XXXX XXXX XXXX XXXX XXXX XXXX" \
  https://aristotle.local/wp-json/wp-ai-code/v1/status
```

Replace `YOUR_USERNAME` with your WordPress admin username.

You should get a JSON response like:

```json
{
  "version": "1.0.0",
  "php_version": "8.2.29",
  "wp_version": "6.7",
  "enabled": true,
  "https": true,
  "writable": {
    "staging": true,
    "themes": true,
    "plugins": true
  },
  "limits": {
    "max_file_size": 512000,
    "max_deployment_size": 5242880
  }
}
```

---

## Step 5: Submit a Test Deployment

```bash
curl -sk -X POST \
  -u "YOUR_USERNAME:XXXX XXXX XXXX XXXX XXXX XXXX" \
  -H "Content-Type: application/json" \
  -d '{
    "deployment_name": "Test Theme",
    "description": "Testing WP AI Code plugin",
    "target_type": "theme",
    "target_slug": "my-test-theme",
    "files": [
      {
        "path": "style.css",
        "content": "/*\nTheme Name: My Test Theme\nDescription: A test theme deployed via AI\nVersion: 1.0.0\n*/\nbody { font-family: sans-serif; color: #333; }"
      },
      {
        "path": "index.php",
        "content": "<?php\nget_header();\necho \"<h1>Hello from AI!</h1>\";\nget_footer();"
      }
    ]
  }' \
  https://aristotle.local/wp-json/wp-ai-code/v1/deploy
```

---

## Step 6: Review and Approve

1. Go to `https://aristotle.local/wp-admin/admin.php?page=wpaic-dashboard`
2. You'll see the pending deployment
3. Click **View** to inspect files and validation results
4. Click **Approve & Deploy** to push files live, or **Reject** to deny

---

## Step 7: Test Rollback

After approving a deployment:

1. Go back to the deployment detail page
2. Click **Rollback** to restore original files

Or via API:

```bash
curl -sk -X POST \
  -u "YOUR_USERNAME:XXXX XXXX XXXX XXXX XXXX XXXX" \
  https://aristotle.local/wp-json/wp-ai-code/v1/deployments/1/rollback
```

---

## Connecting with Claude Code

Claude Code can use the REST API directly to deploy code to your WordPress site. Here's how to set it up:

### 1. Store credentials securely

Add to your environment (e.g. `.env` or shell profile):

```bash
export WP_SITE_URL="https://aristotle.local"
export WP_USERNAME="your-admin-username"
export WP_APP_PASSWORD="XXXX XXXX XXXX XXXX XXXX XXXX"
```

### 2. Use Claude Code to deploy

Tell Claude Code something like:

> Deploy a new theme called "starter-theme" to my WordPress site at https://aristotle.local.
> Use Basic Auth with username `$WP_USERNAME` and app password `$WP_APP_PASSWORD`.
> POST the files to the `/wp-json/wp-ai-code/v1/deploy` endpoint.

Claude Code can then construct and send the API request with your theme/plugin files.

### 3. Example: Claude Code pushing a plugin

Claude Code would run:

```bash
curl -sk -X POST \
  -u "$WP_USERNAME:$WP_APP_PASSWORD" \
  -H "Content-Type: application/json" \
  -d '{
    "deployment_name": "My Custom Plugin v1.0",
    "description": "Custom plugin built by Claude Code",
    "target_type": "plugin",
    "target_slug": "my-custom-plugin",
    "files": [
      {
        "path": "my-custom-plugin.php",
        "content": "<?php\n/**\n * Plugin Name: My Custom Plugin\n * Version: 1.0.0\n */\nadd_action(\"init\", function() {\n    // plugin code here\n});"
      }
    ]
  }' \
  "$WP_SITE_URL/wp-json/wp-ai-code/v1/deploy"
```

Then you review and approve in wp-admin before anything goes live.

### 4. Useful commands for Claude Code

```bash
# Check plugin status
curl -sk -u "$WP_USERNAME:$WP_APP_PASSWORD" \
  "$WP_SITE_URL/wp-json/wp-ai-code/v1/status"

# List all deployments
curl -sk -u "$WP_USERNAME:$WP_APP_PASSWORD" \
  "$WP_SITE_URL/wp-json/wp-ai-code/v1/deployments"

# List only pending deployments
curl -sk -u "$WP_USERNAME:$WP_APP_PASSWORD" \
  "$WP_SITE_URL/wp-json/wp-ai-code/v1/deployments?status=pending"

# View a specific deployment (with file contents)
curl -sk -u "$WP_USERNAME:$WP_APP_PASSWORD" \
  "$WP_SITE_URL/wp-json/wp-ai-code/v1/deployments/1"

# Approve a deployment
curl -sk -X POST -u "$WP_USERNAME:$WP_APP_PASSWORD" \
  "$WP_SITE_URL/wp-json/wp-ai-code/v1/deployments/1/approve"

# Reject a deployment
curl -sk -X POST -u "$WP_USERNAME:$WP_APP_PASSWORD" \
  "$WP_SITE_URL/wp-json/wp-ai-code/v1/deployments/1/reject"

# Rollback a deployment
curl -sk -X POST -u "$WP_USERNAME:$WP_APP_PASSWORD" \
  "$WP_SITE_URL/wp-json/wp-ai-code/v1/deployments/1/rollback"
```

---

## Security Notes

- **Application Passwords** are separate from your login password — you can revoke them anytime
- **Human approval is mandatory** — nothing gets deployed without you clicking Approve
- **Rollback is always available** — original files are backed up before every deployment
- **Kill switch** — go to AI Code > Settings and uncheck "Enable" to block all API access instantly
- The `-sk` flag in curl skips SSL verification for local self-signed certs — do NOT use `-k` in production

---

## Troubleshooting

| Problem | Fix |
|---------|-----|
| Plugin not showing in Plugins list | Check symlink path is correct, run `ls wp-content/plugins/wp-ai-code/wp-ai-code.php` |
| 401 Unauthorized on API calls | Verify username and app password are correct, check for extra spaces |
| 403 Forbidden | Your user needs the `manage_options` capability (Administrator role) |
| 503 Service Unavailable | Plugin is disabled — go to AI Code > Settings and enable it |
| Deployment stuck in "pending" | You need to approve it in wp-admin or via the approve API endpoint |
| Files not deploying | Check AI Code > Settings > System Status — staging/themes/plugins dirs must be writable |
