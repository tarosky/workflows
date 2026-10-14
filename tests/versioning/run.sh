#!/usr/bin/env bash
#
# Tests for actions/versioning/versioning.sh.
#
#   bash tests/versioning/run.sh
#
# On macOS, GNU sed (gsed) is used in place of BSD sed.

set -uo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
SCRIPT="$ROOT/actions/versioning/versioning.sh"
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

if command -v gsed > /dev/null; then
	mkdir -p "$WORK/bin"
	ln -s "$(command -v gsed)" "$WORK/bin/sed"
	export PATH="$WORK/bin:$PATH"
fi

# Fake WordPress.org API.
mkdir -p "$WORK/api"
echo '{"name":"Published","version":"1.2.3"}' > "$WORK/api/published.json"
export VERSIONING_WPORG_API="file://$WORK/api"

FAILED=0

# run <expect: pass|fail> <name> <version> [prefix] [slug]
run() {
	local expect=$1 name=$2 version=$3 prefix=${4:-refs/tags/} slug=${5:-}
	rm -rf "$WORK/files" && mkdir "$WORK/files"
	cp "$ROOT/tests/plugin-main.php" "$ROOT/tests/style.css" "$WORK/files/"
	printf '=== Sample ===\nStable tag: nightly\n' > "$WORK/files/readme.txt"
	printf '/* No header */\n' > "$WORK/files/no-header.css"
	local files=${FILES:-plugin-main.php,style.css,readme.txt}
	local output status
	output=$(cd "$WORK/files" && VERSIONING_VERSION="$version" VERSIONING_FILES="$files" \
		VERSIONING_PREFIX="$prefix" VERSIONING_WPORG_SLUG="$slug" bash "$SCRIPT" 2>&1)
	status=$?
	if { [ "$expect" = pass ] && [ $status -eq 0 ]; } || { [ "$expect" = fail ] && [ $status -ne 0 ]; }; then
		echo "ok   - $name"
	else
		echo "FAIL - $name (exit $status)"
		echo "$output" | sed 's/^/       /'
		FAILED=1
	fi
}

# expect_header <file> <value>
expect_header() {
	if grep -qiE "(version|stable tag):[[:space:]]*$2\$" "$WORK/files/$1"; then
		echo "ok   - $1 has $2"
	else
		echo "FAIL - $1 does not have $2"
		cat "$WORK/files/$1"
		FAILED=1
	fi
}

# Normalization.
run pass "refs/tags/1.2.3" "refs/tags/1.2.3"
run pass "refs/tags/v1.2.3 strips v" "refs/tags/v1.2.3"
expect_header plugin-main.php 1.2.3
expect_header style.css 1.2.3
expect_header readme.txt 1.2.3
run pass "custom prefix" "prefix/tag/99.99.99" "prefix/tag/"
run pass "v prefix as custom prefix" "v2.0" "v"

# Format.
run fail "nightly" "nightly"
run fail "pre-release suffix" "refs/tags/1.2.3-beta"
run fail "single number" "refs/tags/1"
run fail "too many segments" "refs/tags/1.2.3.4.5"
run fail "empty" ""

# WordPress.org.
run fail "same as WordPress.org" "1.2.3" "" published
run fail "lower than WordPress.org" "1.2.2" "" published
run fail "rollback to 0.1.0" "0.1.0" "" published
run pass "1.2.10 > 1.2.3" "1.2.10" "" published
run pass "1.3 > 1.2.3" "1.3" "" published
run pass "not published yet" "0.1.0" "" not-published

# Files.
FILES=plugin-main.php,no-header.css run fail "file without header" "1.2.3"
FILES=plugin-main.php,missing.css run pass "missing file skips" "1.2.3"
FILES=plugin-main.php,missing.css run fail "missing file still checks format" "nightly"

exit $FAILED
