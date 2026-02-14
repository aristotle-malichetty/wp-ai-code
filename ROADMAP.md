# WP AI Code — Future Builds

## Phase 2: Connection Helper UI

Add a "Connection Setup" section to the plugin settings page:

- Show current logged-in username (auto-filled, editable)
- Field to paste Application Password (client-side only, never saved to DB)
- Auto-generate a ready-to-copy `.env` block as user types
- Auto-generate a test `curl` command
- "Test Connection" button that hits `/status` from the browser and shows green/red result
- Link to generate Application Password (opens WP's built-in profile page)

## Phase 3: Notifications

- Email notification when new deployment is submitted
- Slack/webhook notification support
- Admin bar indicator for pending deployments

## Phase 4: Enhanced Review

- Diff view (compare new files vs existing files)
- Syntax highlighting in file preview
- Inline code comments during review
- Multi-reviewer support

## Phase 5: Agent SDK

- NPM package for easy integration with AI agents
- Claude Code MCP server for direct WordPress deployment
- GitHub Actions integration for CI/CD deployments
