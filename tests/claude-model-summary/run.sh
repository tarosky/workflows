#!/usr/bin/env bash
# shellcheck disable=SC2016 # jq 式と Markdown のバッククォートは意図的にリテラル
#
# Tests for actions/claude-model-summary/summarize.sh.
#
#   bash tests/claude-model-summary/run.sh

set -uo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
SCRIPT="$ROOT/actions/claude-model-summary/summarize.sh"
FIXTURES="$ROOT/tests/claude-model-summary"
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

FAILED=0

ok() { echo "ok   - $1"; }
ng() { echo "FAIL - $1"; FAILED=1; }

# assert_contains <name> <haystack> <needle>
assert_contains() {
	if grep -qF -- "$3" <<< "$2"; then ok "$1"; else ng "$1 (missing: $3)"; fi
}

# assert_not_contains <name> <haystack> <needle>
assert_not_contains() {
	if grep -qF -- "$3" <<< "$2"; then ng "$1 (leaked: $3)"; else ok "$1"; fi
}

# assert_eq <name> <actual> <expected>
assert_eq() {
	if [ "$2" = "$3" ]; then ok "$1"; else ng "$1 (expected: $3, actual: $2)"; fi
}

# run_summary <fixture> [metrics_file] -> sets $SUMMARY_OUT and $STDOUT_OUT
run_summary() {
	: > "$WORK/summary.md"
	STDOUT_OUT=$(EXECUTION_FILE="$1" REQUESTED_MODEL=sonnet METRICS_FILE="${2:-}" \
		GITHUB_STEP_SUMMARY="$WORK/summary.md" GITHUB_REPOSITORY=tarosky/hamelp \
		GITHUB_WORKFLOW="Claude Assistant" GITHUB_JOB=assistant GITHUB_EVENT_NAME=issue_comment \
		GITHUB_RUN_ID=123 bash "$SCRIPT" 2>&1)
	STATUS=$?
	SUMMARY_OUT=$(cat "$WORK/summary.md")
}

# --- denials ---
run_summary "$FIXTURES/execution.json" "$WORK/metrics.json"
assert_eq "exit 0" "$STATUS" 0
assert_contains "notice has turns" "$STDOUT_OUT" "turns=55 duration=6m12s permission_denials=9"
assert_contains "summary row" "$SUMMARY_OUT" '| `sonnet` | `claude-sonnet-5` | claude-haiku-5, claude-sonnet-5 | 1.4 | 55 | 6m12s | 9 |'
assert_contains "composer grouped by subcommand" "$SUMMARY_OUT" '`Bash(composer test)` ×2'
assert_contains "gh keeps 2 subcommands" "$SUMMARY_OUT" '`Bash(gh api)` ×1'
assert_contains "env assignment skipped" "$SUMMARY_OUT" '`Bash(npm run)` ×1'
assert_contains "single-word command" "$SUMMARY_OUT" '`Bash(cat)` ×1'
assert_contains "unsafe head masked" "$SUMMARY_OUT" '`Bash(?)` ×1'
assert_contains "git subcommand" "$SUMMARY_OUT" '`Bash(git log)` ×1'
assert_contains "non-Bash tool name" "$SUMMARY_OUT" '`Edit` ×1'
assert_contains "WebFetch tool name" "$SUMMARY_OUT" '`WebFetch` ×1'

ALL="$STDOUT_OUT$SUMMARY_OUT$(cat "$WORK/metrics.json")"
for secret in SecretTest repos/tarosky .secret curl evil oneline /secret example.com FOO=bar "secret prompt"; do
	assert_not_contains "no leak: $secret" "$ALL" "$secret"
done

metrics=$(cat "$WORK/metrics.json")
assert_eq "metrics repository" "$(jq -r .repository <<< "$metrics")" "tarosky/hamelp"
assert_eq "metrics workflow" "$(jq -r .workflow <<< "$metrics")" "Claude Assistant"
assert_eq "metrics resolved" "$(jq -r .resolved_model <<< "$metrics")" "claude-sonnet-5"
assert_eq "metrics turns" "$(jq -r .num_turns <<< "$metrics")" "55"
assert_eq "metrics duration" "$(jq -r .duration_ms <<< "$metrics")" "372500"
assert_eq "metrics denials total" "$(jq '[.permission_denials[].count] | add' <<< "$metrics")" "9"
assert_eq "metrics denials sorted" "$(jq -r '.permission_denials[0].tool' <<< "$metrics")" "Bash(composer test)"

# --- no denials, no metrics file ---
rm -f "$WORK/metrics.json"
run_summary "$FIXTURES/no-denials.json"
assert_eq "exit 0 (no denials)" "$STATUS" 0
assert_contains "zero denials" "$SUMMARY_OUT" '| 0.12 | 3 | 0m42s | 0 |'
assert_not_contains "no denial line" "$SUMMARY_OUT" "拒否されたツール"
if [ -e "$WORK/metrics.json" ]; then ng "metrics not written without METRICS_FILE"; else ok "metrics not written without METRICS_FILE"; fi

# --- missing execution file ---
run_summary "$WORK/missing.json"
assert_eq "exit 0 (missing file)" "$STATUS" 0
assert_contains "warning on missing file" "$STDOUT_OUT" "::warning::"

exit $FAILED
