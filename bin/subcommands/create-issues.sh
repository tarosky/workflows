#!/usr/bin/env bash

# create-issues.sh - 特定のラベルがついたリポジトリすべてに同じ内容のイシューを登録
#
# 使用方法: ts-workflow create-issues --label <ラベル> --title <タイトル> [オプション]
# 例: ts-workflow create-issues --label wordpress-plugin --title "セキュリティアップデート" --issue-labels "security,urgent" --create-missing-labels

set -e

# スクリプトディレクトリ
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LIB_DIR="${SCRIPT_DIR}/../lib"

# 共通関数の読み込み
source "${LIB_DIR}/common.sh"

# ヘルプの表示
function show_help {
  echo "使用方法: ts-workflow create-issues [オプション]"
  echo ""
  echo "オプション:"
  echo "  --label <ラベル>         リポジトリをフィルタリングするラベル/トピック（必須）"
  echo "  --title <タイトル>       イシューのタイトル（必須）"
  echo "  --body <本文>            イシューの本文"
  echo "  --body-file <ファイル>   イシューの本文をファイルから読み込む"
  echo "  --issue-labels <ラベル>  イシューに付けるラベル（カンマ区切り）"
  echo "  --create-missing-labels   指定したラベルが存在しない場合、自動的に作成する"
  echo "  --assignee <アサイン先>  イシューのアサイン先（ユーザー名またはチーム名、例: tarosky/maintainers）。"
  echo "                          チーム名を指定した場合、そのチームのメンバー全員にアサインされます。"
  echo "  --org <組織名>           特定の組織のリポジトリのみを対象にする"
  echo "  --dry-run                実際に変更を加えずに実行内容を表示"
  echo "  --help                   このヘルプメッセージを表示"
  echo ""
  exit 0
}

# 引数の解析
LABEL=""
TITLE=""
BODY=""
BODY_FILE=""
ISSUE_LABELS=""
ASSIGNEE=""
ORG=""
DRY_RUN=false
CREATE_MISSING_LABELS=false

while [[ $# -gt 0 ]]; do
  case "$1" in
    --label)
      LABEL="$2"
      shift 2
      ;;
    --title)
      TITLE="$2"
      shift 2
      ;;
    --body)
      BODY="$2"
      shift 2
      ;;
    --body-file)
      BODY_FILE="$2"
      shift 2
      ;;
    --issue-labels)
      ISSUE_LABELS="$2"
      shift 2
      ;;
    --assignee)
      ASSIGNEE="$2"
      shift 2
      ;;
    --org)
      ORG="$2"
      shift 2
      ;;
    --dry-run)
      DRY_RUN=true
      shift
      ;;
    --create-missing-labels)
      CREATE_MISSING_LABELS=true
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

if [[ -z "$TITLE" ]]; then
  error "必須パラメータがありません: --title"
  show_help
fi

# ファイルから本文を読み込む
if [[ -n "$BODY_FILE" ]]; then
  if [[ ! -f "$BODY_FILE" ]]; then
    error "本文ファイルが見つかりません: $BODY_FILE"
    exit 1
  fi
  
  BODY=$(cat "$BODY_FILE")
fi

# 本文が提供されていない場合、入力を促す
if [[ -z "$BODY" ]]; then
  echo "イシューの本文を入力してください（入力完了したらCtrl+Dを押してください）:"
  BODY=$(cat)
fi

# 指定されたラベルを持つリポジトリを取得
echo "ラベル/トピック「$LABEL」を持つリポジトリを検索中..."
if [[ -n "$ORG" ]]; then
  echo "組織「$ORG」のリポジトリのみをフィルタリングします..."
  echo "アーカイブまたは無効化されたリポジトリはスキップされます..."
  REPOS=($(get_repos_with_label "$LABEL" "$ORG"))
else
  echo "アーカイブまたは無効化されたリポジトリはスキップされます..."
  REPOS=($(get_repos_with_label "$LABEL"))
fi

if [[ ${#REPOS[@]} -eq 0 ]]; then
  error "ラベル/トピック「$LABEL」を持つリポジトリが見つかりません"
  exit 1
fi

echo "ラベル/トピック「$LABEL」を持つリポジトリが ${#REPOS[@]} 件見つかりました"

# アサイン先の処理
ASSIGNEES=""
if [[ -n "$ASSIGNEE" ]]; then
  # チーム（org/team）が指定されているか確認
  if [[ "$ASSIGNEE" == *"/"* ]]; then
    echo "チームがアサイン先として指定されました: $ASSIGNEE"
    echo "チームメンバーを取得中..."
    
    # チーム名を分解
    ORG=${ASSIGNEE%/*}
    TEAM=${ASSIGNEE#*/}
    
    # チームメンバーを取得
    TEAM_MEMBERS=$(gh api "orgs/$ORG/teams/$TEAM/members" --jq '.[].login' 2>/dev/null)
    
    if [[ -z "$TEAM_MEMBERS" ]]; then
      echo "警告: チーム $ASSIGNEE のメンバーを取得できませんでした。"
      echo "チームが存在するか、アクセス権があるか確認してください。"
    else
      # 改行をカンマに置換
      ASSIGNEES=$(echo "$TEAM_MEMBERS" | tr '\n' ',' | sed 's/,$//')
      echo "チームメンバー: $ASSIGNEES"
    fi
  else
    # 個人ユーザーの場合はそのまま使用
    ASSIGNEES="$ASSIGNEE"
  fi
fi

# イシュー作成コマンドの準備
ISSUE_CMD="gh issue create --title \"$TITLE\" --body \"$BODY\""

if [[ -n "$ISSUE_LABELS" ]]; then
  ISSUE_CMD="$ISSUE_CMD --label \"$ISSUE_LABELS\""
fi

# 各リポジトリにイシューを作成
for repo in "${REPOS[@]}"; do
  echo "リポジトリを処理中: $repo"
  
  if [[ "$DRY_RUN" == true ]]; then
    echo "  [ドライラン] $repo にイシューを作成します"
    echo "  タイトル: $TITLE"
    echo "  本文: ${BODY:0:50}..."
    if [[ -n "$ISSUE_LABELS" ]]; then
      echo "  ラベル: $ISSUE_LABELS"
      
      # 自動作成フラグが有効な場合、ラベルの作成も表示
      if [[ "$CREATE_MISSING_LABELS" == true ]]; then
        echo "  存在しないラベルは自動的に作成されます"
      fi
    fi
    if [[ -n "$ASSIGNEES" ]]; then
      echo "  アサイン先: $ASSIGNEES"
    fi
  else
    echo "  $repo にイシューを作成中..."
    
    # ラベルが指定されていて、自動作成フラグが有効な場合、ラベルの存在を確認して作成
    if [[ -n "$ISSUE_LABELS" && "$CREATE_MISSING_LABELS" == true ]]; then
      echo "  ラベルの存在を確認中..."
      
      # カンマ区切りのラベルを配列に変換
      IFS=',' read -ra LABEL_ARRAY <<< "$ISSUE_LABELS"
      
      # リポジトリの既存ラベルを取得
      EXISTING_LABELS=$(gh label list --repo "$repo" --json name --jq '.[].name')
      
      # 各ラベルを確認
      for label_name in "${LABEL_ARRAY[@]}"; do
        # 前後の空白を削除
        label_name=$(echo "$label_name" | xargs)
        
        # ラベルが存在するか確認
        if ! echo "$EXISTING_LABELS" | grep -q "^$label_name$"; then
          echo "  ラベル '$label_name' が存在しません。作成します..."
          if [[ "$DRY_RUN" == true ]]; then
            echo "  [ドライラン] $repo にラベル '$label_name' を作成します"
          else
            # デフォルトの色を使用してラベルを作成
            gh label create "$label_name" --repo "$repo" --color "CCCCCC" --description "自動作成されたラベル"
            echo "  ラベル '$label_name' を作成しました"
          fi
        else
          echo "  ラベル '$label_name' は既に存在します"
        fi
      done
    fi
    
    # イシュー作成コマンドの実行
    # 一時ファイルを作成して本文を保存
    TEMP_BODY_FILE=$(mktemp)
    echo "$BODY" > "$TEMP_BODY_FILE"
    
    # 本文をファイルから読み込むようにコマンドを構築
    FULL_CMD="gh issue create --repo $repo --title \"$TITLE\" --body-file \"$TEMP_BODY_FILE\""
    
    if [[ -n "$ISSUE_LABELS" ]]; then
      FULL_CMD="$FULL_CMD --label \"$ISSUE_LABELS\""
    fi
    
    if [[ -n "$ASSIGNEES" ]]; then
      FULL_CMD="$FULL_CMD --assignee \"$ASSIGNEES\""
    fi
    
    # コマンドの実行
    eval "$FULL_CMD"
    
    # 一時ファイルを削除
    rm -f "$TEMP_BODY_FILE"
    
    echo "  $repo にイシューが正常に作成されました"
  fi
done

echo "完了しました！"
