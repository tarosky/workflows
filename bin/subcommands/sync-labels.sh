#!/usr/bin/env bash

# sync-labels.sh - 特定のラベルがついたリポジトリのラベルを共通化
#
# 使用方法: ts-workflow sync-labels --label <ラベル> --source <ソースリポジトリ> [オプション]

set -e

# スクリプトディレクトリ
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LIB_DIR="${SCRIPT_DIR}/../lib"

# 共通関数の読み込み
source "${LIB_DIR}/common.sh"

# ヘルプの表示
function show_help {
  echo "使用方法: ts-workflow sync-labels [オプション]"
  echo ""
  echo "オプション:"
  echo "  --label <ラベル>           リポジトリをフィルタリングするラベル/トピック（必須）"
  echo "  --source <ソースリポジトリ> ラベルのコピー元リポジトリ（必須、形式: owner/repo）"
  echo "  --org <組織名>             特定の組織のリポジトリのみを対象にする（必須）"
  echo "  --label-filter <フィルタ>   特定のラベルのみを同期（glob パターン）"
  echo "  --dry-run                   実際に変更を加えずに実行内容を表示"
  echo "  --help                      このヘルプメッセージを表示"
  echo ""
  exit 0
}

# 引数の解析
LABEL=""
SOURCE_REPO=""
ORG=""
LABEL_FILTER=""
DRY_RUN=false

while [[ $# -gt 0 ]]; do
  case "$1" in
    --label)
      LABEL="$2"
      shift 2
      ;;
    --source)
      SOURCE_REPO="$2"
      shift 2
      ;;
    --org)
      ORG="$2"
      shift 2
      ;;
    --label-filter)
      LABEL_FILTER="$2"
      shift 2
      ;;
    --dry-run)
      DRY_RUN=true
      shift
      ;;
    --help)
      show_help
      ;;
    *)
      echo "エラー: 不明なオプション '$1'"
      echo ""
      show_help
      ;;
  esac
done

# 必須パラメータの検証
if [[ -z "$LABEL" ]]; then
  error "必須パラメータがありません: --label"
  show_help
fi

if [[ -z "$SOURCE_REPO" ]]; then
  error "必須パラメータがありません: --source"
  show_help
fi

if [[ -z "$ORG" ]]; then
  error "必須パラメータがありません: --org"
  show_help
fi

# 指定されたラベルを持つリポジトリを取得
echo "ラベル/トピック「$LABEL」を持つリポジトリを検索中..."
echo "組織「$ORG」のリポジトリのみをフィルタリングします..."
echo "アーカイブまたは無効化されたリポジトリはスキップされます..."
REPOS=($(get_repos_with_label "$LABEL" "$ORG"))

if [[ ${#REPOS[@]} -eq 0 ]]; then
  error "ラベル/トピック「$LABEL」を持つリポジトリが見つかりません"
  exit 1
fi

echo "ラベル/トピック「$LABEL」を持つリポジトリが ${#REPOS[@]} 件見つかりました"

# ソースリポジトリからラベルを取得
echo "ソースリポジトリからラベルを取得中: $SOURCE_REPO"
TEMP_LABELS_FILE=$(mktemp)
trap 'rm -f "$TEMP_LABELS_FILE"' EXIT

# ソースリポジトリからラベルをJSON形式で取得
gh label list --repo "$SOURCE_REPO" --json name,color,description > "$TEMP_LABELS_FILE"

# ラベルが取得できたか確認
LABEL_COUNT=$(jq length "$TEMP_LABELS_FILE")
if [[ "$LABEL_COUNT" -eq 0 ]]; then
  error "ソースリポジトリにラベルが見つかりません: $SOURCE_REPO"
  exit 1
fi

echo "ソースリポジトリで $LABEL_COUNT 件のラベルが見つかりました"

# フィルタが指定されている場合は適用
if [[ -n "$LABEL_FILTER" ]]; then
  echo "フィルタを適用中: $LABEL_FILTER"
  # これは単純なフィルタ実装です。適切なglobマッチングで拡張できます
  FILTERED_LABELS_FILE=$(mktemp)
  trap 'rm -f "$FILTERED_LABELS_FILE"' EXIT
  
  jq "[.[] | select(.name | contains(\"$LABEL_FILTER\"))]" "$TEMP_LABELS_FILE" > "$FILTERED_LABELS_FILE"
  mv "$FILTERED_LABELS_FILE" "$TEMP_LABELS_FILE"
  
  LABEL_COUNT=$(jq length "$TEMP_LABELS_FILE")
  echo "フィルタ適用後: $LABEL_COUNT 件のラベル"
  
  if [[ "$LABEL_COUNT" -eq 0 ]]; then
    error "フィルタに一致するラベルがありません: $LABEL_FILTER"
    exit 1
  fi
fi

# ターゲットリポジトリにラベルを同期
for repo in "${REPOS[@]}"; do
  # ソースリポジトリをスキップ
  if [[ "$repo" == "$SOURCE_REPO" ]]; then
    echo "ソースリポジトリをスキップします: $repo"
    continue
  fi
  
  echo "リポジトリを処理中: $repo"
  
  # ターゲットリポジトリの既存ラベルを取得
  TARGET_LABELS_FILE=$(mktemp)
  trap 'rm -f "$TARGET_LABELS_FILE"' EXIT
  
  gh label list --repo "$repo" --json name,color,description > "$TARGET_LABELS_FILE"
  
  # ソースリポジトリの各ラベルを処理
  jq -c '.[]' "$TEMP_LABELS_FILE" | while read -r label_json; do
    name=$(echo "$label_json" | jq -r '.name')
    color=$(echo "$label_json" | jq -r '.color')
    description=$(echo "$label_json" | jq -r '.description')
    
    # ラベルがターゲットリポジトリに存在するか確認
    label_exists=$(jq -r ".[] | select(.name == \"$name\") | .name" "$TARGET_LABELS_FILE")
    
    if [[ -n "$label_exists" ]]; then
      # ラベルが存在する場合、更新
      if [[ "$DRY_RUN" == true ]]; then
        echo "  [ドライラン] $repo のラベル '$name' を更新します"
      else
        echo "  $repo のラベル '$name' を更新中..."
        gh label edit "$name" --repo "$repo" --color "$color" --description "$description"
      fi
    else
      # ラベルが存在しない場合、作成
      if [[ "$DRY_RUN" == true ]]; then
        echo "  [ドライラン] $repo にラベル '$name' を作成します"
      else
        echo "  $repo にラベル '$name' を作成中..."
        gh label create "$name" --repo "$repo" --color "$color" --description "$description"
      fi
    fi
  done
done

echo "完了しました！"
