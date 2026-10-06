#!/usr/bin/env bash
#
# Upload a zip to Kunoichi Market's deploy API.
#
#   POST {endpoint}/wp-json/makibishi/v1/deploy
#
# The deploy key is always sent as `X-Makibishi-Deploy-Key`, not as
# `Authorization: Bearer`. Basic auth (staging) occupies `Authorization`,
# and the API accepts only one of them, so a single channel keeps it simple.
#
# Secrets are handed to curl through a config on stdin, so they never
# appear in argv (`ps`).
#
# Can be run locally for testing:
#   KUNOICHI_DEPLOY_KEY=mkbs_... KUNOICHI_FILE=foo.zip KUNOICHI_VERSION=1.0.0 \
#   KUNOICHI_ENDPOINT=https://local.kunoichiwp.com bash deploy.sh

set -euo pipefail

error() {
	echo "::error::$1"
	exit 1
}

# Escape a value for a double-quoted curl config string.
curl_quote() {
	local value="${1//\\/\\\\}"
	printf '"%s"' "${value//\"/\\\"}"
}

KEY="${KUNOICHI_DEPLOY_KEY:-}"
FILE="${KUNOICHI_FILE:-}"
VERSION="${KUNOICHI_VERSION:-}"
ENDPOINT="${KUNOICHI_ENDPOINT:-https://kunoichiwp.com}"
SLUG="${KUNOICHI_SLUG:-}"
FORCE="${KUNOICHI_FORCE:-false}"
AUTH_USER="${KUNOICHI_BASIC_AUTH_USER:-}"
AUTH_PASSWORD="${KUNOICHI_BASIC_AUTH_PASSWORD:-}"

# Validate inputs before touching the network.
[ -n "$KEY" ] || error "deploy-key is empty. Did you set the secret?"
[ -n "$FILE" ] || error "file is required."
[ -f "$FILE" ] || error "File not found: $FILE"
[[ "$VERSION" =~ ^[vV]?[0-9]+\.[0-9]+\.[0-9]+$ ]] || error "Invalid version: '$VERSION'. Expected x.y.z (a leading v is accepted)."
if [ -n "$AUTH_USER" ] && [ -z "$AUTH_PASSWORD" ]; then
	error "basic-auth-password is required when basic-auth-user is set."
fi
if [ -z "$AUTH_USER" ] && [ -n "$AUTH_PASSWORD" ]; then
	error "basic-auth-user is required when basic-auth-password is set."
fi
case "$ENDPOINT" in
	https://*) ;;
	*) error "endpoint must start with https:// to protect the deploy key: $ENDPOINT" ;;
esac

URL="${ENDPOINT%/}/wp-json/makibishi/v1/deploy"
RESPONSE="$(mktemp)"
trap 'rm -f "$RESPONSE"' EXIT

echo "Uploading $FILE (version $VERSION) to $URL"

# Build the curl config. Non-secret form fields go here too so that
# file names with spaces need no extra quoting.
build_config() {
	echo "url = $(curl_quote "$URL")"
	echo "header = $(curl_quote "X-Makibishi-Deploy-Key: $KEY")"
	echo "form = $(curl_quote "file=@$FILE;type=application/zip")"
	echo "form-string = $(curl_quote "version=$VERSION")"
	if [ -n "$SLUG" ]; then
		echo "form-string = $(curl_quote "slug=$SLUG")"
	fi
	if [ "$FORCE" = "true" ]; then
		echo 'form-string = "force=true"'
	fi
	if [ -n "$AUTH_USER" ]; then
		echo "user = $(curl_quote "$AUTH_USER:$AUTH_PASSWORD")"
	fi
}

STATUS="$(
	build_config | curl --silent --show-error --request POST \
		--output "$RESPONSE" --write-out '%{http_code}' \
		--config -
)" || error "Request to $URL failed (network error)."

if [ "$STATUS" = "201" ]; then
	REGISTERED="$(jq -r '.file // empty' "$RESPONSE")"
	jq -r '.message // empty' "$RESPONSE"
	if [ -n "${GITHUB_OUTPUT:-}" ]; then
		echo "file=$REGISTERED" >> "$GITHUB_OUTPUT"
	fi
	exit 0
fi

# Failure: show the API's code/message when JSON, otherwise the head of the body
# (e.g. an HTML page from basic auth or a proxy).
if CODE="$(jq -er '.code' "$RESPONSE" 2> /dev/null)"; then
	MESSAGE="$(jq -r '.message // empty' "$RESPONSE")"
	case "$CODE" in
		invalid_username | invalid_email | incorrect_password | application_passwords_disabled*)
			MESSAGE="$MESSAGE (WordPress tried to treat the basic auth credentials as an application password.)"
			;;
	esac
	error "HTTP $STATUS [$CODE] $MESSAGE"
fi
head -c 500 "$RESPONSE"
echo
error "HTTP $STATUS from $URL"
