#!/usr/bin/env bash
#
# 1リリース分の extracted.yml を生成する。
#
# Usage:
#   extract-release.sh <release> <status> <from-ref> <to-ref> [work-dir]
#
# Examples:
#   extract-release.sh 7.1 final 7.0.0 7.1.0
#   extract-release.sh 7.2 trunk 7.1.0 trunk
#   extract-release.sh 7.2 beta  7.1.0 7.2.0-beta1
#
# work-dir（デフォルト: リポジトリの tmp/wp-compat）に wordpress-develop の
# blobless clone と各 ref の worktree を作る。

set -euo pipefail

if [ $# -lt 4 ]; then
	sed -n '3,15p' "$0"
	exit 1
fi

RELEASE=$1
STATUS=$2
FROM_REF=$3
TO_REF=$4
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORK_DIR=${5:-"${SCRIPT_DIR}/../../tmp/wp-compat"}
REPO="${WORK_DIR}/wp-develop.git"

mkdir -p "${WORK_DIR}/trees"
if [ ! -d "${REPO}" ]; then
	git clone --quiet --filter=blob:none --bare https://github.com/WordPress/wordpress-develop.git "${REPO}"
fi
git -C "${REPO}" fetch --quiet --tags origin '+refs/heads/*:refs/heads/*'

checkout() {
	local ref=$1 dir="${WORK_DIR}/trees/$1"
	if [ -d "${dir}" ]; then
		git -C "${dir}" checkout --quiet --detach "${ref}"
	else
		git -C "${REPO}" worktree add --quiet --detach "${dir}" "${ref}"
	fi
}

checkout "${FROM_REF}"
checkout "${TO_REF}"
TO_COMMIT=$(git -C "${WORK_DIR}/trees/${TO_REF}" rev-parse HEAD)

php "${SCRIPT_DIR}/extract-core.php" \
	--release="${RELEASE}" --status="${STATUS}" \
	--from-dir="${WORK_DIR}/trees/${FROM_REF}" --from-ref="${FROM_REF}" \
	--to-dir="${WORK_DIR}/trees/${TO_REF}" --to-ref="${TO_REF}" \
	--to-commit="${TO_COMMIT}"
