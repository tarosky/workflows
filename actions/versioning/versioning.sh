#!/usr/bin/env bash
#
# Rewrite `Version:` / `Stable Tag:` headers and verify the result
# before the release reaches WordPress.org.
#
# 1. Normalize: strip the prefix, then `refs/tags/` and a leading `v`.
# 2. Format:    the version must be numeric dotted (1.2 – 1.2.3.4).
# 3. WordPress.org (opt-in): must be greater than the published version.
# 4. Rewrite each file and check every header equals the version exactly.
#
# Can be run locally for testing (GNU sed required):
#   VERSIONING_VERSION=refs/tags/v1.2.3 VERSIONING_FILES=style.css bash versioning.sh

set -euo pipefail

error() {
	echo "::error::$1"
	exit 1
}

VERSION_STR="${VERSIONING_VERSION:-}"
FILES="${VERSIONING_FILES:-}"
PREFIX="${VERSIONING_PREFIX:-refs/tags/}"
WPORG_SLUG="${VERSIONING_WPORG_SLUG:-}"
WPORG_API="${VERSIONING_WPORG_API:-https://api.wordpress.org/plugins/info/1.0}"

# 1. Normalize.
VERSION="${VERSION_STR#"$PREFIX"}"
VERSION="${VERSION#refs/tags/}"
VERSION="${VERSION#v}"

# 2. Format.
if ! [[ "$VERSION" =~ ^[0-9]+(\.[0-9]+){1,3}$ ]]; then
	error "Invalid version '${VERSION}' (from '${VERSION_STR}'). Expected e.g. 1.2.3."
fi
echo "Version: ${VERSION}"

# 3. Compare with WordPress.org.
if [ -n "$WPORG_SLUG" ]; then
	CURRENT=$(curl -sf "${WPORG_API}/${WPORG_SLUG}.json" | jq -r '.version // empty' 2>/dev/null || true)
	if [ -z "$CURRENT" ]; then
		echo "::notice::${WPORG_SLUG} is not found on WordPress.org. Skip version comparison."
	else
		echo "WordPress.org: ${CURRENT}"
		# Numeric dotted only, so `sort -V` agrees with PHP's version_compare().
		HIGHER=$(printf '%s\n%s\n' "$CURRENT" "$VERSION" | sort -V | tail -n 1)
		if [ "$VERSION" = "$CURRENT" ] || [ "$HIGHER" != "$VERSION" ]; then
			error "Version ${VERSION} must be greater than ${CURRENT} on WordPress.org."
		fi
	fi
fi

if [ -n "${GITHUB_OUTPUT:-}" ]; then
	echo "version=${VERSION}" >> "$GITHUB_OUTPUT"
fi

# 4. Rewrite and verify.
FILE_LIST=()
for file in ${FILES//,/ }; do
	if [ ! -f "$file" ]; then
		echo "::warning::${file} does not exist. Skip versioning."
		exit 0
	fi
	FILE_LIST+=("$file")
done

for file in "${FILE_LIST[@]}"; do
	sed -i.bak "s/\(Version\|Stable Tag\)\(:[[:space:]]*\).*$/\1\2$VERSION/i" "$file"
	rm "$file.bak"
	cat "$file"
	VALUES=$(sed -n 's/.*\(Version\|Stable Tag\):[[:space:]]*\(.*\)$/\2/ip' "$file" | sed 's/[[:space:]]*$//')
	if [ -z "$VALUES" ]; then
		error "No Version / Stable Tag header found in ${file}."
	fi
	while IFS= read -r value; do
		if [ "$value" != "$VERSION" ]; then
			error "${file} has '${value}', expected '${VERSION}'."
		fi
	done <<< "$VALUES"
	echo "${file} is updated to ${VERSION}."
done
