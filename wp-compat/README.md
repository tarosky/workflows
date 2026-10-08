# wp-compat

WordPress本体のアップデートが自社プラグイン・テーマに影響するかを判断するための、リリースごとの変更カタログ。
（tarosky/workflows#131）

## 構成

```
wp-compat/
├── catalog/<release>/
│   ├── extracted.yml   # 機械抽出（毎回再生成。手で編集しない）
│   └── curated.yml     # Dev Notes・Field Guide から作成（PRでレビュー）
├── schema/             # カタログの JSON Schema
├── scripts/            # 取得・抽出・検証・サイト生成
├── site/               # GitHub Pages のテンプレート
└── skill/SKILL.md      # curated.yml を作る手順（Claude Code 用）
```

## カタログの2つの層

| | extracted.yml | curated.yml |
|---|---|---|
| 作り方 | wordpress-develop の2つの ref をトークン解析して差分 | Make/Core の投稿を Claude が読んで要約 |
| 内容 | 削除・非推奨（関数/クラス/メソッド/フック/ファイル）、発火しなくなったフック、PHP/MySQL要件 | 挙動変更、エディタ/JS、CSS/DOM、DB、ドロップされた機能 |
| 再現性 | 完全に再現可能 | レビュー前提 |

## 使い方

```bash
cd wp-compat
composer install

# 1. Make/Core の投稿を取得（../tmp/wp-compat/sources に保存）
php scripts/fetch-make-posts.php --version=7.2 --after=2026-07-20 --before=2026-10-09 --out=../tmp/wp-compat/sources

# 2. 機械抽出（wordpress-develop を ../tmp/wp-compat に blobless clone する）
scripts/extract-release.sh 7.2 trunk 7.1.0 trunk

# 3. curated.yml を作る（Claude Code で skill/SKILL.md に従う）

# 4. 検証とサイト生成
php scripts/validate.php
php scripts/build-site.php      # → _site/
```

## 自動更新

`.github/workflows/wp-compat-catalog.yml` が毎日 06:00 JST に動く。

1. `detect-updates.php` が作業を判定する
   - 進行中リリースの段階（`version.php` の `$wp_version` が alpha → beta → RC）とコミット。wordpress-develop にはベータ・RC のタグが無いため
   - 正式版のタグ（`x.y.0`）が打たれたら final で再抽出し、次のリリースの trunk カタログを作る
   - 対象リリースの Dev Notes・Field Guide・リリース告知のうち、`curated.yml` の `sources` に無いもの
2. 機械抽出を再生成する（変更内容と段階が同じなら書き換えない）
3. 未取り込みの投稿があれば Claude が `skill/SKILL.md` に従って `curated.yml` を更新する
4. テスト・スキーマ検証・**シンボル実在チェック**（`verify-symbols.php`）が通れば PR を自動マージ。通らなければ PR を残し、Slack でレビューを依頼する
5. マージ後、`wp-compat-pages.yml` が Pages を更新し、公開中のカタログとの差分を Slack に通知する

必要な設定（tarosky/workflows）:

| 種類 | 名前 | 内容 |
|---|---|---|
| Variable | `WP_COMPAT_APP_CLIENT_ID` | GitHub App の Client ID |
| Secret | `WP_COMPAT_APP_PRIVATE_KEY` | GitHub App の秘密鍵 |
| Secret | `ANTHROPIC_API_KEY` | Claude（org のシークレットでも可） |
| Secret | `SLACK_WEBHOOK_URL` | Slack Incoming Webhook（任意。無ければ通知しない） |

GitHub App の権限: Contents / Pull requests は Read and write。tarosky/workflows にだけインストールする。`GITHUB_TOKEN` で作った PR では必須チェック（Status Check）も Pages のデプロイも起動しないため、App のトークンを使う。

## 照合（自社リポジトリ側）

各リポジトリには薄い呼び出し側だけを置く。カタログは実行時に Pages から取得するので、ワークフローのタグを固定してもカタログは最新になる。

```yaml
name: WP Compat Check

on:
  schedule:
    - cron: '0 0 * * 1'   # 毎週月曜
  workflow_dispatch:
    inputs:
      to:
        description: 更新先（例 7.2、latest）
        default: latest
      from:
        description: 現在のバージョン（空なら readme の Tested up to）
        default: ''

jobs:
  check:
    uses: tarosky/workflows/.github/workflows/wp-compat-check.yml@main
    permissions:
      contents: read
      issues: write
    with:
      to: ${{ inputs.to || 'latest' }}
      from: ${{ inputs.from || '' }}
```

ローカルでの実行:

```bash
php scripts/check.php --path=../my-plugin --from=6.6 --to=7.1          # Markdown
php scripts/check.php --path=../my-plugin --format=json                 # Tested up to → 最新
```

判定の分類:

| 区分 | 条件 | Issue での扱い |
|---|---|---|
| 該当 | 確度が high/medium の項目で、シンボルか `match_hint` が一致 | 重大度 高・中は本文、低は折りたたみ |
| 目視チェック | 重大度 high・確度 manual | 一致箇所を候補として表示 |
| 参考 | `js_package` だけが一致、または manual 項目のヒント一致 | 折りたたみ |

Issue の扱い（`scripts/lib/issues.php`）:

- 1リポジトリ×1リリースにつき1件。open な Issue があれば本文を更新し、`- [x]` の項目は引き継ぐ
- **閉じた Issue は「確認済み」とみなす**。前回に無かった変更（重大度 中以上でコードに該当したもの）が出たときだけ再オープンしてコメントする
- 新しいリリースの Issue ができたら、古いリリースの open な Issue は「#N に引き継ぎ」としてクローズする（新しい Issue は範囲が広く、古い Issue の内容を含む）

`--from` を省略すると readme（readme.txt / README.md）の `Tested up to` を起点にする。無ければ更新先の直前のリリース。Issue はリリースごとに1件なので、起点が実行ごとに変わらないようにするため。

`fixed_in` がある項目は、修正済みバージョン以降への更新なら除外する（`--to=7.1` は 7.1 系の最新とみなす）。

## 状態（status）

- カタログ全体: `trunk` → `beta` → `rc` → `final`。リリース前は節目ごとに作り直す
- 各変更: `planned`（予告のみ）/ `shipped` / `dropped`（予告されたが出荷されず）/ `reverted`

同じ機能はリリースをまたいで同じ ID を使う（例: `feature:real-time-collaboration` は 7.0・7.1 とも dropped）。

## 既知の限界

- 動的なフック名（`"save_post_{$post_type}"` など）は機械抽出できない
- JS パッケージ、パッケージ由来のスクリプトハンドル（`wp-views` など）、CSS の変更は機械抽出していない（curated に頼る）。`script-loader.php` で登録されるハンドルは抽出する
- バンドルライブラリ（SimplePie など）と `block_core_*` は重大度 low
- 照合では `vendor/` を除外する。同梱したサードパーティライブラリ（plugin-update-checker など）が壊れても検出できない
