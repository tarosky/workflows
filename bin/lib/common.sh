#!/usr/bin/env bash

# ts-workflowスクリプト用の共通関数

# コマンドが存在するか確認
function command_exists() {
  command -v "$1" &> /dev/null
}

# 必要なツールが利用可能か確認
function check_requirements() {
  if ! command_exists "gh"; then
    echo "エラー: GitHub CLI (gh) がインストールされていないか、PATHに含まれていません。"
    echo "https://cli.github.com/ からインストールしてください。"
    exit 1
  fi
  
  if ! gh auth status &> /dev/null; then
    echo "エラー: GitHub CLI (gh) が認証されていません。"
    echo "'gh auth login' を実行して認証してください。"
    exit 1
  fi
}

# 特定のラベルを持つリポジトリを取得
function get_repos_with_label() {
  local label="$1"
  local org="$2"
  
  if [[ -z "$label" ]]; then
    echo "エラー: ラベルパラメータが必要です。"
    return 1
  fi
  
  # 指定されたラベルを持つリポジトリを検索するためにGitHub CLIを使用
  # 形式: owner/repo
  # アーカイブまたは無効化されたリポジトリはスキップ
  if [[ -n "$org" ]]; then
    # 組織が指定されている場合、その組織のリポジトリのみをフィルタリング
    gh search repos --topic "$label" --json owner,name,isArchived,isDisabled --jq '.[] | select(.owner.login == "'"$org"'") | select(.isArchived == false) | select(.isDisabled == false) | .owner.login + "/" + .name'
  else
    # 組織が指定されていない場合、すべてのリポジトリを返す
    gh search repos --topic "$label" --json owner,name,isArchived,isDisabled --jq '.[] | select(.isArchived == false) | select(.isDisabled == false) | .owner.login + "/" + .name'
  fi
}

# 値が配列に含まれているか確認
function in_array() {
  local needle="$1"
  shift
  local haystack=("$@")
  
  for item in "${haystack[@]}"; do
    if [[ "$item" == "$needle" ]]; then
      return 0
    fi
  done
  
  return 1
}

# エラーメッセージを表示
function error() {
  echo "エラー: $1" >&2
}

# 警告メッセージを表示
function warning() {
  echo "警告: $1" >&2
}

# 情報メッセージを表示
function info() {
  echo "情報: $1"
}

# デバッグメッセージを表示（DEBUGが設定されている場合のみ）
function debug() {
  if [[ -n "$DEBUG" ]]; then
    echo "デバッグ: $1"
  fi
}

# パラメータが提供されているか確認
function require_param() {
  local param_name="$1"
  local param_value="$2"
  
  if [[ -z "$param_value" ]]; then
    error "パラメータ '$param_name' が必要です。"
    return 1
  fi
  
  return 0
}

# 共通機能の初期化
check_requirements
