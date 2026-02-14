#!/bin/bash
#
# Deploy plugin files to WordPress via WP AI Code API.
# Reads credentials from .env file.
#
# Usage:
#   ./deploy.sh                          # Deploy the plugin itself
#   ./deploy.sh theme my-theme file1 file2  # Deploy specific files as a theme
#   ./deploy.sh plugin my-plugin file1 file2

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# Load .env
if [ -f "$SCRIPT_DIR/.env" ]; then
    while IFS='=' read -r key value; do
        # Skip comments and empty lines
        [[ "$key" =~ ^#.*$ || -z "$key" ]] && continue
        # Trim whitespace from key
        key=$(echo "$key" | xargs)
        # Export with value preserved (including spaces)
        export "$key=$value"
    done < "$SCRIPT_DIR/.env"
else
    echo "Error: .env file not found. Copy .env.example to .env and fill in your credentials."
    exit 1
fi

if [ -z "$WP_SITE_URL" ] || [ -z "$WP_USERNAME" ] || [ -z "$WP_APP_PASSWORD" ]; then
    echo "Error: WP_SITE_URL, WP_USERNAME, and WP_APP_PASSWORD must be set in .env"
    exit 1
fi

API_URL="$WP_SITE_URL/wp-json/wp-ai-code/v1"

# Helper: convert a file to JSON object { "path": "...", "content": "..." }
file_to_json() {
    local filepath="$1"
    local relative_path="$2"
    local content
    content=$(python3 -c "
import json, sys
with open(sys.argv[1], 'r') as f:
    print(json.dumps(f.read()))
" "$filepath")
    echo "{\"path\":\"$relative_path\",\"content\":$content}"
}

# Check connection first
echo "Checking connection to $WP_SITE_URL..."
STATUS=$(curl -sk -u "$WP_USERNAME:$WP_APP_PASSWORD" "$API_URL/status" 2>&1)
if echo "$STATUS" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('version',''))" 2>/dev/null | grep -q "1.0.0"; then
    echo "Connected! Plugin v1.0.0 is active."
else
    echo "Connection failed. Response:"
    echo "$STATUS"
    exit 1
fi

# Determine what to deploy
TARGET_TYPE="${1:-plugin}"
TARGET_SLUG="${2:-wp-ai-code}"
shift 2 2>/dev/null || true

# If no specific files given, deploy the plugin's own source files
if [ $# -eq 0 ] && [ "$TARGET_TYPE" = "plugin" ] && [ "$TARGET_SLUG" = "wp-ai-code" ]; then
    echo "Deploying WP AI Code plugin files..."
    FILES_JSON="["
    FIRST=true
    # Find all plugin PHP, CSS, JS files
    while IFS= read -r file; do
        relative="${file#$SCRIPT_DIR/}"
        # Skip non-plugin files
        case "$relative" in
            .env*|.git*|deploy.sh|SETUP.md|ROADMAP.md|CLAUDE.md|readme.txt|*.md) continue ;;
        esac
        if [ "$FIRST" = true ]; then
            FIRST=false
        else
            FILES_JSON+=","
        fi
        FILES_JSON+=$(file_to_json "$file" "$relative")
    done < <(find "$SCRIPT_DIR" -type f \( -name "*.php" -o -name "*.css" -o -name "*.js" \) | sort)
    FILES_JSON+="]"
else
    echo "Deploying $TARGET_TYPE/$TARGET_SLUG..."
    FILES_JSON="["
    FIRST=true
    for file in "$@"; do
        if [ ! -f "$file" ]; then
            echo "Warning: $file not found, skipping."
            continue
        fi
        relative=$(basename "$file")
        if [ "$FIRST" = true ]; then
            FIRST=false
        else
            FILES_JSON+=","
        fi
        FILES_JSON+=$(file_to_json "$file" "$relative")
    done
    FILES_JSON+="]"
fi

# Build the deployment payload
DEPLOYMENT_NAME="Deploy $TARGET_TYPE/$TARGET_SLUG ($(date '+%Y-%m-%d %H:%M'))"
PAYLOAD=$(python3 -c "
import json, sys
files = json.loads(sys.argv[1])
payload = {
    'deployment_name': sys.argv[2],
    'description': 'Deployed via deploy.sh',
    'target_type': sys.argv[3],
    'target_slug': sys.argv[4],
    'files': files
}
print(json.dumps(payload))
" "$FILES_JSON" "$DEPLOYMENT_NAME" "$TARGET_TYPE" "$TARGET_SLUG")

FILE_COUNT=$(echo "$FILES_JSON" | python3 -c "import sys,json; print(len(json.load(sys.stdin)))")
echo "Submitting $FILE_COUNT file(s)..."

RESPONSE=$(curl -sk -X POST \
    -u "$WP_USERNAME:$WP_APP_PASSWORD" \
    -H "Content-Type: application/json" \
    -d "$PAYLOAD" \
    "$API_URL/deploy" 2>&1)

# Check if deployment was created
DEP_ID=$(echo "$RESPONSE" | python3 -c "import sys,json; print(json.load(sys.stdin).get('id',''))" 2>/dev/null)

if [ -n "$DEP_ID" ] && [ "$DEP_ID" != "None" ]; then
    echo ""
    echo "Deployment #$DEP_ID created (status: pending)"
    echo ""
    echo "Review it at: $WP_SITE_URL/wp-admin/admin.php?page=wpaic-dashboard&deployment_id=$DEP_ID"
    echo ""
    echo "Or approve via API:"
    echo "  curl -sk -X POST -u \"\$WP_USERNAME:\$WP_APP_PASSWORD\" $API_URL/deployments/$DEP_ID/approve"
else
    echo "Deployment failed:"
    echo "$RESPONSE" | python3 -m json.tool 2>/dev/null || echo "$RESPONSE"
    exit 1
fi
