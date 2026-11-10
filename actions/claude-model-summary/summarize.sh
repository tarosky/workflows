#!/usr/bin/env bash
# shellcheck disable=SC2016 # jq 式と Markdown のバッククォートは意図的にリテラル
#
# claude-code-action の execution_file から、モデル・コスト・ターン数・所要時間・
# 拒否されたツールを集計して Job Summary に出力する。
#
# 環境変数:
#   EXECUTION_FILE      claude-code-action の outputs.execution_file（必須）
#   REQUESTED_MODEL     --model に渡した値
#   METRICS_FILE        指定時のみ、集計結果の JSON をこのパスに書き出す
#   GITHUB_STEP_SUMMARY Job Summary の出力先（未設定なら標準出力）
#
# ツールの入力内容は公開ログに出さない。Bash は先頭のコマンド名（gh / git などは
# サブコマンドまで）だけを出し、引数やパスは捨てる。

set -euo pipefail

SUMMARY=${GITHUB_STEP_SUMMARY:-/dev/stdout}

if [ -z "${EXECUTION_FILE:-}" ] || [ ! -f "$EXECUTION_FILE" ]; then
	echo "::warning::execution_file が見つからないため、解決されたモデルを確認できません"
	exit 0
fi

metrics=$(jq -c --arg requested "${REQUESTED_MODEL:-}" '
	# Bash コマンドを「コマンド名 + サブコマンド」に縮める。先頭の環境変数代入は読み飛ばす。
	def command_head:
		(.command // "" | tostring)
		| sub("^(\\s*[A-Za-z_][A-Za-z0-9_]*=\\S*)*\\s*"; "")
		| [splits("\\s+")] | map(select(length > 0))
		| if length == 0 then "?"
		  else
			(if .[0] == "gh" then 3
			 elif (.[0] | IN("git", "npm", "npx", "composer", "wp", "yarn", "pnpm")) then 2
			 else 1 end) as $depth
			| if (.[0] | test("^[A-Za-z0-9._/-]+$")) then
				[.[0]] + ([.[1:$depth][] | select(test("^[a-z][a-z0-9:_-]*$"))] | .[0:($depth - 1)])
				| join(" ")
			  else "?" end
		  end;
	def denial_label:
		if .tool_name == "Bash" then "Bash(\(.tool_input // {} | command_head))"
		else (.tool_name // "unknown") end;
	([.[] | select(.type == "result")]) as $results
	| {
		requested_model: $requested,
		resolved_model: ([.[] | select(.type == "system" and .subtype == "init") | .model][0] // "unknown"),
		models_used: ([$results[] | .modelUsage // {} | keys[]] | unique),
		cost_usd: ([$results[] | .total_cost_usd // 0] | add // 0),
		num_turns: ([$results[] | .num_turns // 0] | add // 0),
		duration_ms: ([$results[] | .duration_ms // 0] | add // 0),
		permission_denials: (
			[$results[] | .permission_denials // [] | .[] | denial_label]
			| group_by(.) | map({tool: .[0], count: length}) | sort_by(-.count, .tool)
		)
	}
' "$EXECUTION_FILE")

field() { jq -r "$1" <<< "$metrics"; }

resolved=$(field '.resolved_model')
cost=$(field '.cost_usd')
turns=$(field '.num_turns')
duration=$(field '(.duration_ms / 1000 | floor) as $s | "\($s / 60 | floor)m\($s % 60)s"')
used=$(field '.models_used | join(", ")')
denial_count=$(field '[.permission_denials[].count] | add // 0')
denials=$(field '.permission_denials | map("`\(.tool)` ×\(.count)") | join(", ")')

echo "::notice title=Claude model::requested=${REQUESTED_MODEL:-} resolved=${resolved} cost=\$${cost} turns=${turns} duration=${duration} permission_denials=${denial_count}"

{
	echo "### Claude model"
	echo ""
	echo "| 指定 | 解決 | 使用モデル | コスト (USD) | ターン数 | 所要時間 | 拒否 |"
	echo "|---|---|---|---|---|---|---|"
	echo "| \`${REQUESTED_MODEL:-}\` | \`${resolved}\` | ${used:-—} | ${cost} | ${turns} | ${duration} | ${denial_count} |"
	if [ "$denial_count" != "0" ]; then
		echo ""
		echo "**拒否されたツール:** ${denials}"
	fi
} >> "$SUMMARY"

if [ -n "${METRICS_FILE:-}" ]; then
	jq --arg repository "${GITHUB_REPOSITORY:-}" \
		--arg workflow "${GITHUB_WORKFLOW:-}" \
		--arg job "${GITHUB_JOB:-}" \
		--arg event "${GITHUB_EVENT_NAME:-}" \
		--arg run_id "${GITHUB_RUN_ID:-}" \
		--arg recorded_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
		'{schema: 1, repository: $repository, workflow: $workflow, job: $job, event: $event, run_id: $run_id, recorded_at: $recorded_at} + .' \
		<<< "$metrics" > "$METRICS_FILE"
fi
