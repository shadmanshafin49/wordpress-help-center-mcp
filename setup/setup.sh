#!/bin/sh
# Sets up the local site in one go, and is safe to re-run:
# WordPress, the MCP Adapter plugin, this project's plugin and theme, the site content,
# an "AI Assistant" user, and the MCP client config (.mcp.json) with its credentials.
#
# Run from the project root:  docker compose run --rm cli sh /project/setup/setup.sh
# Re-runs keep the existing application password. To replace it (connected clients
# then need to reconnect):   docker compose run --rm -e RESET_MCP_PASSWORD=1 cli sh /project/setup/setup.sh
set -eu

MCP_ADAPTER_ZIP="https://github.com/WordPress/mcp-adapter/releases/download/v0.7.1/mcp-adapter.zip"
MCP_URL="$SITE_URL/wp-json/help-center/mcp"

echo "-> Waiting for the WordPress container to finish copying files..."
until [ -f /var/www/html/wp-config.php ]; do sleep 1; done

if ! wp core is-installed 2>/dev/null; then
	echo "-> Installing WordPress..."
	wp core install --url="$SITE_URL" --title="Fernhill" --admin_user=admin \
		--admin_password="$ADMIN_PASSWORD" --admin_email=admin@example.com --skip-email
	wp option update blogdescription "Help Center"
	wp option update timezone_string "America/New_York"
	wp user update admin --display_name="Fernhill Support"
fi

echo "-> Pointing WordPress at $SITE_URL..."
wp option update home "$SITE_URL"
wp option update siteurl "$SITE_URL"

echo "-> Switching to pretty permalinks (needed for /wp-json/... URLs)..."
wp rewrite structure '/%postname%/'

echo "-> Activating the plugins and the theme..."
wp plugin is-installed mcp-adapter || wp plugin install "$MCP_ADAPTER_ZIP"
wp plugin activate mcp-adapter help-center-mcp
wp theme activate fernhill
wp rewrite flush

echo "-> Creating the AI assistant user (Contributor: can write drafts, cannot publish)..."
wp user get ai-assistant --field=ID >/dev/null 2>&1 ||
	wp user create ai-assistant ai-assistant@example.com --role=contributor --display_name="AI Assistant" --porcelain >/dev/null

echo "-> Adding help topics, articles and the Contact page..."
wp eval-file /project/setup/seed.php

if [ -f /project/.mcp.json ] && [ "${RESET_MCP_PASSWORD:-0}" != "1" ] &&
	[ "$(wp user application-password list ai-assistant --format=count)" -gt 0 ]; then
	echo "-> Keeping the existing application password in .mcp.json."
else
	echo "-> Creating an application password for the AI assistant and writing .mcp.json..."
	wp user application-password delete ai-assistant --all >/dev/null 2>&1 || true
	APP_PASSWORD=$(wp user application-password create ai-assistant "MCP client" --porcelain)
	AUTH=$(printf '%s' "ai-assistant:$APP_PASSWORD" | base64 | tr -d '\n')

	cat > /project/.mcp.json <<-EOF
	{
	  "mcpServers": {
	    "help-center": {
	      "type": "http",
	      "url": "$MCP_URL",
	      "headers": {
	        "Authorization": "Basic $AUTH"
	      }
	    }
	  }
	}
	EOF
fi

cat <<EOF

Done!
  Help center: $SITE_URL
  Admin:       $SITE_URL/wp-admin   (user: admin)
  MCP server:  $MCP_URL
  MCP client:  .mcp.json (git-ignored) holds the URL and the Authorization header.
EOF
