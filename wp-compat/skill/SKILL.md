---
name: wp-compat-curate
description: WordPress本体リリースの Dev Notes・Field Guide・ロードマップを読み、互換性カタログ（catalog/<release>/curated.yml）を作成・更新する。機械抽出（extracted.yml）で拾えない挙動変更・エディタ/JS・CSS・ドロップされた機能を補う。
---

# wp-compat-curate

WordPress本体の1リリース分について、**プラグイン・テーマの互換性に効く変更**を `curated.yml` に書く。

## 前提

- 作業ディレクトリは `wp-compat/`
- 情報源は `fetch-make-posts.php` で取得済み: `../tmp/wp-compat/sources/<release>/make/*.json`
  - `_index.json` の `relevance` が `dev-note` / `field-guide` / `release` / `roadmap` / `mentions-version` のものを読む
  - `relevance: null` は原則読まない（Dev Chat 要約などは「ドロップ」の確認にだけ使う）
- 機械抽出済み: `catalog/<release>/extracted.yml`。**これと重複する項目は書かない**
- スキーマ: `schema/catalog.schema.json`

## 手順

1. `extracted.yml` を読み、すでにカバーされている ID を把握する
2. Field Guide があれば最初に読む。目次がそのまま網羅性のチェックリストになる
3. Dev Notes を1本ずつ読み、互換性に効く変更を抜き出す
4. ロードマップ・Beta/RC告知・Field Guide の「didn't make」節から、**予告されたが出荷されなかった機能**を `status: dropped` で記録する
5. `php scripts/validate.php catalog/<release>/curated.yml` が通るまで直す

## 書く/書かないの基準

書く:
- 既存のプラグイン・テーマのコードが **壊れる・挙動が変わる・Notice が出る** 変更
- 既存のフック・関数・JSパッケージ・スクリプトハンドル・CSSクラス・ブロックの仕様変更
- エディタの前提が変わるもの（iframe 化の強制、React のバージョン、パッケージの削除など）
- DB スキーマ、オプション、REST API のレスポンス変更
- 予告されたがドロップされた機能（利用者が「入ったはず」と誤認するため）

書かない:
- 純粋な新機能で、既存コードに影響しないもの（新しいブロック、新しい API の追加だけ）
- UI の見た目だけの変更（ただし管理画面の DOM/CSS クラスが変わるなら `css` で書く）
- `extracted.yml` にすでにあるシンボルの非推奨・削除

迷ったら書く。ただし `severity: low` にする。

## フィールドの決め方

- `id`: `<type>:<kebab-case-slug>`。例 `behavior:jit-textdomain-loading-notice`
  - **複数リリースにまたがる機能は `feature:<slug>` で固定し、`type` に関係なく同じ ID を使う**
  - 書く前に `grep -h "id: 'feature:" catalog/*/curated.yml` で既存の ID を確認し、同じ機能なら流用する
  - 既知の例: `feature:real-time-collaboration` / `feature:enforced-iframe-editor` / `feature:react-19` / `feature:template-management` / `feature:gallery-lightbox` / `feature:query-instant-search` / `feature:admin-dashicons-to-svg`
  - `related` に自分自身の ID を入れない（同じ ID がリリースをまたぐこと自体が関連を表す）
- `type`:
  - `behavior`: PHP 側の挙動変化
  - `editor_js`: ブロックエディタ・JSパッケージ・スクリプト
  - `css`: 管理画面・エディタ・フロントのCSS/DOM
  - `db`: テーブル・オプション・メタ
  - `feature`: 機能単位の記録（主に dropped）
  - `removed` / `deprecated` / `hook_removed` / `hook_deprecated`: 機械抽出漏れを補う場合のみ
- `status`: `shipped` / `dropped` / `planned`（trunk でロードマップにのみある）/ `reverted`
  - ベータ・RC 中に差し戻されて **x.y.0 に入らなかったものは `dropped`**。`reverted` は x.y.0 で出荷され、その後のマイナーで差し戻されたものだけ
  - x.y.0 で壊れて x.y.1 で直ったリグレッションは `shipped` のまま、`summary` に修正バージョンを書く
- `severity`: `high` = Fatal・機能停止 / `medium` = 挙動変化・Notice / `low` = 情報
- `confidence`:
  - `high`: `symbols` が自社コードにあれば影響がほぼ確実
  - `medium`: シンボルで照合できるが、使い方次第で影響しない
  - `manual`: シンボルで表せない。目視が必要
- `symbols`: **本文に実在する識別子だけ**を書く。推測で作らない
  - `kind`: `function` / `class` / `method`（`Class::method`）/ `hook` / `js_package`（`@wordpress/xxx`）/ `js_handle` / `css_handle` / `css_selector` / `option` / `block`（`core/xxx`）/ `constant`
  - 置き換え先が書かれていれば `replacement`
- `match_hint`: シンボルで表せないが grep で拾えるときの PCRE（例 `load_plugin_textdomain\s*\(`）
- `summary`: 日本語。**何が変わり、プラグイン作者は何をすべきか** を2〜4文で
- `source_urls`: 根拠の投稿URL（必須）
- `related`: 関連する extracted の ID や、過去リリースの同じ機能の ID

## 出力の形

```yaml
release: '7.1'
status: final
origin: curated
generated_at: '2026-10-08T00:00:00+00:00'
sources:
  - { type: field-guide, url: 'https://make.wordpress.org/core/2026/08/05/wordpress-7-1-field-guide/', title: 'WordPress 7.1 Field Guide', date: '2026-08-05' }
changes:
  - id: 'feature:real-time-collaboration'
    type: feature
    title: リアルタイム共同編集は 7.1 でも出荷されなかった
    summary: |
      ...
    status: dropped
    severity: low
    confidence: manual
    related: ['feature:real-time-collaboration']
    source_urls: ['https://...']
```

## 注意

- **Field Guide は目次として使い、内容は Dev Note とソースで裏を取る**。6.9 の Field Guide には実装と食い違う記述があった
- JS の識別子（`__experimental*` など）はソースツリーに無い。記憶で書かず、`package.json` の `gutenberg.sha` の Gutenberg ソースで確認する
  - `gh api "repos/WordPress/gutenberg/contents/packages/<pkg>/src/index.ts?ref=<sha>" -q .content | base64 -d`
- `script-loader.php` で登録されるハンドルの削除は extracted に出る。パッケージ由来のハンドル（`wp-views` など）は出ないので curated で書く

- 情報源の本文は英語。`summary` と `title` は日本語で書く
- 投稿本文を長く引用しない（要約する）
- trunk/beta の段階では Dev Notes が揃っていない。その時点で読めた情報源を `sources` に必ず残す
