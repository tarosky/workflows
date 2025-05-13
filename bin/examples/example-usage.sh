#!/usr/bin/env bash

# ts-workflow CLIツールの使用例
# このスクリプトはts-workflow CLIツールの使い方を示します

# ディレクトリが存在しない場合は作成
mkdir -p bin/examples

# 例1: 特定のラベルを持つリポジトリ全体にイシューを作成
echo "例1: 特定のラベルを持つリポジトリ全体にイシューを作成"
echo "--------------------------------------------------------------------------------"
echo "コマンド: ts-workflow create-issues --label wordpress-plugin --title \"WordPress互換性の更新\" --body \"プラグインをWordPress 6.0と互換性を持つように更新してください\" --assignee \"tarosky/maintainers\" --org \"tarosky\" --dry-run"
echo ""
echo "このコマンドは、タイトル「WordPress互換性の更新」"
echo "本文「プラグインをWordPress 6.0と互換性を持つように更新してください」のイシューを"
echo "ラベル/トピック「wordpress-plugin」を持つ「tarosky」組織のリポジトリに作成します。"
echo "イシューには「tarosky/maintainers」チームのメンバー全員がアサインされます。"
echo "（チーム名を指定すると、そのチームのメンバー全員にアサインされます）"
echo "--orgオプションにより、指定した組織のリポジトリのみが対象になります。"
echo "--dry-runフラグにより、実際の変更は行われません。"
echo ""

# 例2: ソースリポジトリからラベルを同期
echo "例2: ソースリポジトリからラベルを同期"
echo "--------------------------------------------------------------------------------"
echo "コマンド: ts-workflow sync-labels --label tarosky-project --source tarosky/workflows --org \"tarosky\" --dry-run"
echo ""
echo "このコマンドは、tarosky/workflowsリポジトリからすべてのラベルを"
echo "ラベル/トピック「tarosky-project」を持つ「tarosky」組織のリポジトリに同期します。"
echo "--orgオプションにより、指定した組織のリポジトリのみが対象になります。"
echo "--dry-runフラグにより、実際の変更は行われません。"
echo ""

# 例3: 特定のラベルのみを同期
echo "例3: 特定のラベルのみを同期"
echo "--------------------------------------------------------------------------------"
echo "コマンド: ts-workflow sync-labels --label tarosky-project --source tarosky/workflows --label-filter \"bug\" --org \"tarosky\" --dry-run"
echo ""
echo "このコマンドは、名前に「bug」を含むラベルのみを"
echo "tarosky/workflowsリポジトリからラベル/トピック「tarosky-project」を持つ「tarosky」組織のリポジトリに同期します。"
echo "--orgオプションにより、指定した組織のリポジトリのみが対象になります。"
echo "--dry-runフラグにより、実際の変更は行われません。"
echo ""

echo "注意: 実際に操作を実行するには、--dry-runフラグを削除してください。"
echo "これらのコマンドを実行する前に、GitHub CLI（gh）がインストールされ、認証されていることを確認してください。"
