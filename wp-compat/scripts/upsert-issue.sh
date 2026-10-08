#!/usr/bin/env bash
#
# 照合レポートを Issue として作成・更新する。1リポジトリ×1リリースにつき1件。
#
# Usage:
#   upsert-issue.sh <repo> <release> <report.md> <significant> [label] [--dry-run]
#
#   repo         owner/name
#   release      更新先のメジャーリリース（例 7.2）。Issue の特定に使う
#   report.md    check.php の Markdown 出力
#   significant  重大度 中以上の該当があれば 1
#
# 動作:
#   - 同じリリースの open な Issue があれば本文を更新（チェック済みの項目は引き継ぐ）
#   - なければ、significant=1 のときだけ作成する
#   - --dry-run では何も書き込まず、行う操作を表示する

set -euo pipefail

REPO=$1
RELEASE=$2
REPORT=$3
SIGNIFICANT=$4
LABEL=${5:-wp-compat}
DRY_RUN=false
for arg in "$@"; do
	[ "${arg}" = "--dry-run" ] && DRY_RUN=true
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MARKER="<!-- wp-compat:${RELEASE} -->"
TITLE="WordPress ${RELEASE} 互換性チェック"

EXISTING=$(gh issue list -R "${REPO}" --label "${LABEL}" --state open --limit 100 --json number,body \
	--jq "map(select(.body | contains(\"${MARKER}\"))) | first | .number // empty" 2>/dev/null || true)

if [ -n "${EXISTING}" ]; then
	OLD_BODY=$(mktemp)
	NEW_BODY=$(mktemp)
	gh issue view "${EXISTING}" -R "${REPO}" --json body --jq .body > "${OLD_BODY}"
	php "${SCRIPT_DIR}/merge-checks.php" "${OLD_BODY}" "${REPORT}" > "${NEW_BODY}"
	if ${DRY_RUN}; then
		echo "[dry-run] update #${EXISTING} in ${REPO}"
	else
		gh issue edit "${EXISTING}" -R "${REPO}" --body-file "${NEW_BODY}" > /dev/null
		echo "Updated https://github.com/${REPO}/issues/${EXISTING}"
	fi
	exit 0
fi

if [ "${SIGNIFICANT}" != "1" ]; then
	echo "No significant matches for ${RELEASE}; no issue created."
	exit 0
fi

if ${DRY_RUN}; then
	echo "[dry-run] create \"${TITLE}\" with label ${LABEL} in ${REPO}"
	exit 0
fi

if ! gh label list -R "${REPO}" --search "${LABEL}" --json name --jq '.[].name' | grep -qx "${LABEL}"; then
	gh label create "${LABEL}" -R "${REPO}" --color 0E8A16 --description "WordPress本体アップデートの互換性チェック" > /dev/null
fi
gh issue create -R "${REPO}" --title "${TITLE}" --label "${LABEL}" --body-file "${REPORT}"
