# tarosky/workflows — Claude向け開発ガイド

## このリポジトリの役割

Tarosky Organization の全プラグインから呼び出される共有 GitHub Actions ワークフロー集。
各ワークフローは `.github/workflows/` に格納され、`workflow_call` で各プラグインリポジトリから利用される。

## Wikiの編集

ドキュメント（利用側プラグインへの説明）は GitHub Wiki で管理している。

### セットアップ（初回のみ）

```bash
git clone git@github.com:tarosky/workflows.wiki.git wiki
```

`wiki/` ディレクトリは `.gitignore` に登録済みのため、本リポジトリには含まれない。

### 編集・プッシュ手順

```bash
# 編集
vi wiki/Home.md

# コミット＆プッシュ
cd wiki
git add Home.md
git commit -m "Update: xxx の説明を追加"
git push
```

### Wiki のページ構成

| ファイル | 内容 |
|---|---|
| `wiki/Home.md` | 全ワークフロー・アクションの使い方リファレンス |
| `wiki/boiler‐plate.md` | ボイラープレートのセットアップ手順 |

### ドキュメント更新が必要なケース

- 新しいワークフロー（`.github/workflows/*.yml`）を追加したとき
- 既存ワークフローの inputs / デフォルト値を変更したとき
- 呼び出し例が変わるような仕様変更をしたとき

`wiki/Home.md` の記述スタイルは既存セクションに倣うこと（日本語説明 + YAMLコード例）。
