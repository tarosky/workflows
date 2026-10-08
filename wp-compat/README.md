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

## 状態（status）

- カタログ全体: `trunk` → `beta` → `rc` → `final`。リリース前は節目ごとに作り直す
- 各変更: `planned`（予告のみ）/ `shipped` / `dropped`（予告されたが出荷されず）/ `reverted`

同じ機能はリリースをまたいで同じ ID を使う（例: `feature:real-time-collaboration` は 7.0・7.1 とも dropped）。

## 既知の限界

- 動的なフック名（`"save_post_{$post_type}"` など）は機械抽出できない
- JS パッケージ・スクリプトハンドル・CSS の変更は機械抽出していない（curated に頼る）
- バンドルライブラリ（SimplePie など）と `block_core_*` は重大度 low
