#!/bin/sh
# Deletes every help article the AI assistant wrote (drafts, and any an editor has
# since published), so the help center is back to the sample articles. Safe to re-run.
# Leaves the application password and .mcp.json alone, so connected clients keep working.
#
# Run from the project root:  docker compose run --rm cli sh /project/setup/reset.sh
set -eu

AI_USER_ID=$(wp user get ai-assistant --field=ID)
IDS=$(wp post list --post_type=help_article --post_status=any,trash --author="$AI_USER_ID" --format=ids)

if [ -n "$IDS" ]; then
	wp post delete $IDS --force
else
	echo "Nothing to delete: the AI assistant hasn't written any articles."
fi
