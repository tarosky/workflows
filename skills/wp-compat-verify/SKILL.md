---
name: wp-compat-verify
description: WordPress 本体の新バージョン（正式リリースまたは RC）に対して公開済みプラグインを検証し、readme の Tested up to を更新するスキル。「Tested up to を更新したい」「新しい WordPress で動作確認したい」「RC が出たのでテストしたい」「プラグインページの未テスト警告を消したい」といった依頼、または WordPress のメジャーリリース・RC 公開後の棚卸しのときに有効になります。
allowed-tools: [Read, Write, Edit, Glob, Grep, WebFetch, Bash(gh), Bash(git), Bash(curl), Bash(npm), Bash(npx), Bash(composer), Bash(php)]
---

# WordPress 新バージョン対応の検証と Tested up to 更新

WordPress.org は、`Tested up to` が直近 3 メジャーリリースより古いプラグインの公開ページに
「直近3メジャーリリースで未テスト」の警告バナーを表示します。実際にはメンテナンスされていても、
利用者からは「放置されたプラグイン」に見えるため、インストール数に直接影響します。

このスキルは、その警告を消すために**実際に検証を行い**、結果を根拠として残し、
`Tested up to` を更新する PR を出すまでの手順を定めます。

**想定している契機**：主に正式リリース版が出たときです。RC（リリース候補）版でのテストも
同じ手順で行えます（後述の「RC（リリース候補）でのテスト」を参照）。ただし RC の公開を
検知して自動で起動する仕組みは未実装で、今後の改良予定です。

## このスキルが有効になるとき

- WordPress 本体のメジャーバージョンが上がり、公開プラグインの対応状況を棚卸しするとき
- WordPress の RC が公開され、その時点で対応状況を確認したいとき
- 特定のプラグインについて「新しい WordPress で動くか確認したい」と依頼されたとき
- プラグインページの「未テスト」警告を解消したいとき
- `readme.txt` / `README.md` の `Tested up to` を更新するとき

## 検証の原則（ここを外すと作業に意味がない）

### 1. 「インストールしてエラーが出ない」は検証ではない

そのプラグインが**何をするものか**を README から把握し、**本来の機能を実際に動かして**
確認します。有効化してエラーが出ないことの確認は、検証の入口でしかありません。

例：広告を出すプラグインなら「広告が実際にテーマの所定位置に出力されるか」まで見る。
投稿タイプを登録するプラグインなら「登録され、作成・取得できるか」まで見る。

### 2. リポジトリ自身が検証用ハーネスを持っていないか先に探す

自分でテストコードを書き始める前に、リポジトリを調べます。Tarosky のプラグインには
検証の仕込みが最初から入っていることがあります。

例：`taro-ad-fields` には `tests/src/Bootstrap.php` があり、`composer.json` の `autoload-dev`
（`Tarosky\TaroAdFieldsTest\` → `tests/src`）経由で本体が `class_exists()` で読み込む作りでした。
テーマ統合（`wp_head` / `wp_body_open` / `wp_footer` などへの出力）を再現する仕掛けが
用意済みだったため、自前でコードを書く必要はありませんでした。

**これが composer install に `--no-dev` を付けない理由です。** `autoload-dev` のハーネスが
読み込まれなくなります。本番デプロイ側は `--no-dev` なので、この仕込みが利用者に混入することはありません。

### 3. テストデータの日時をずらす

「最新 N 件を表示」のような仕様のプラグインで、テストデータを同一秒に作ると並び順が
非決定的になり、実行ごとに結果が変わります。実際にこれで一度誤った判定をしました。
`post_date` を意図的にずらして作成してください。

### 4. 動かないものを動くと書かない

長く更新されていないプラグインは、実際に新しい WordPress で壊れていることがあります。
その場合は `Tested up to` を上げず、**壊れている内容を issue に記録する**のが正しい結果です。
警告を消すこと自体は目的ではありません。

## 手順

### 1. 対象プラグインの現状確認

WordPress.org API で公開されている値を取得します。

```bash
SLUG=taro-ad-fields
curl -s "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=${SLUG}" \
  | php -r '$d = json_decode( file_get_contents( "php://stdin" ), true ); printf( "version=%s tested=%s requires=%s requires_php=%s\n", $d["version"], $d["tested"], $d["requires"], $d["requires_php"] );'
```

現行の WordPress バージョンは `https://api.wordpress.org/core/version-check/1.7/` で確認します。
ベータ／RC は `?channel=beta` を付けると `offers[0]`（`"response": "development"`）に現れます。

```bash
curl -s "https://api.wordpress.org/core/version-check/1.7/?channel=beta" \
  | php -r '$d = json_decode( file_get_contents( "php://stdin" ), true ); printf( "%s (%s)\n", $d["offers"][0]["version"], $d["offers"][0]["download"] );'
```

警告が出ているかどうかは、API の値から推測せず**公開ページの HTML で判定**します。

```bash
curl -s "https://wordpress.org/plugins/${SLUG}/" | grep -c "been tested with the latest 3 major releases"
```

1 以上なら警告が出ています。判定は必ず英語ページ（`wordpress.org`）で行ってください。
`ja.wordpress.org` は文言が異なります。

### 2. issue を立てる

`tarosky/<slug>` に検証タスクの issue を作ります。現状値・最新バージョン・警告の有無・
プラグインページの URL を本文に含めます。

```bash
gh issue create --repo "tarosky/${SLUG}" \
  --title 'Test with WordPress X.Y and update "Tested up to"' \
  --body-file /tmp/issue-body.md
```

### 3. リポジトリを準備し、検証環境の有無を確認

```bash
gh repo clone "tarosky/${SLUG}"
cd "${SLUG}"
ls .wp-env.json phpunit.xml.dist 2>/dev/null; ls tests/ 2>/dev/null
cat composer.json   # autoload-dev / scripts を必ず読む
```

`.wp-env.json` が無い場合は環境構築から必要です。テストが未整備なら、この検証とは分けて
別途整備を検討してください（このスキルの範囲外）。

### 4. 依存関係をインストールする

```bash
npm install
composer install    # --no-dev は付けない（原則 2 を参照）
```

### 5. wp-env を起動し、WordPress のバージョンを実際に確認する

```bash
npx wp-env start
npx wp-env run cli -- wp core version
npx wp-env run cli -- wp --info | grep -i 'php version'
```

`.wp-env.json` の指定によっては最新版が入りません。**実際に入ったバージョンを確認**し、
それを検証結果に記録します。

### 6. 機能テストを行う

原則 1・2・3 に沿って、プラグイン本来の機能を動かします。

```bash
composer test                       # 既存の PHPUnit があれば
npx wp-env run cli -- wp eval-file /path/to/check.php
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
```

自動テストの件数が少ない場合、その通過だけを根拠にしないこと。件数と限界を報告に明記します。

### 7. debug.log を空にしてから一通り操作する

```bash
npx wp-env run cli -- bash -c ': > /var/www/html/wp-content/debug.log'
# ここで管理画面・フロントを一巡する
npx wp-env run cli -- cat /var/www/html/wp-content/debug.log
```

プラグイン起因のエラー・警告・非推奨通知が **0 件**であることを確認します。
他プラグインやテーマ起因の出力が混ざる場合は、出力元を切り分けて記録します。

### 8. WordPress 本体の非推奨関数を使っていないか照合する

```bash
npx wp-env run cli -- bash -c "grep -oE '^function [a-zA-Z0-9_]+' /var/www/html/wp-includes/deprecated.php" \
  | sed 's/^function //' | sort -u > /tmp/wp-deprecated.txt

grep -rhoE '\b[a-zA-Z0-9_]+\s*\(' --include='*.php' . \
  | grep -v vendor | tr -d ' (' | sort -u > /tmp/plugin-calls.txt

comm -12 /tmp/wp-deprecated.txt /tmp/plugin-calls.txt
```

一致した名前は、プラグイン側で同名の関数を自分で定義しているだけの場合もあるため、
必ず該当箇所を目視で確認してから判断します。照合した関数の総数を報告に書きます。

### 9. スクリーンショットを撮る

管理画面の撮影が必要な場合、**パスワードを入力しません**。認証クッキーを直接発行します。

```bash
# 一時ログインファイルを設置（ユーザー ID 1 = admin）
npx wp-env run cli -- wp eval 'file_put_contents( ABSPATH . "_ts-login.php", "<?php require_once __DIR__ . \"/wp-load.php\"; wp_set_auth_cookie( 1 ); wp_safe_redirect( admin_url() ); exit;" );'

# ヘッドレス Chrome で、同一プロファイルを使って経由 → 撮影
CHROME='/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'
"$CHROME" --headless --disable-gpu --user-data-dir=/tmp/ts-shot --window-size=1440,900 \
  --screenshot=/tmp/_warm.png 'http://localhost:8888/_ts-login.php'
"$CHROME" --headless --disable-gpu --user-data-dir=/tmp/ts-shot --window-size=1440,900 \
  --screenshot=/tmp/admin.png 'http://localhost:8888/wp-admin/'

# 撮影後、必ず削除する
npx wp-env run cli -- wp eval 'unlink( ABSPATH . "_ts-login.php" );'
```

一時ファイルの削除を忘れないこと。この手順は**ローカルの wp-env 限定**で、
本番環境や共有環境では絶対に行いません。

### 10. 結果を issue にコメントし、PR を出す

issue へのコメントには次を含めます。

- **環境**：WordPress / PHP のバージョン、環境の作り方、検証したコミット
- **結果の表**：有効化、PHPUnit（件数）、構文チェック、非推奨関数、`debug.log`、画面の応答
- **機能テストの表**：プラグイン本来の機能ごとの結果
- **限界**：カバーしていない範囲（自動テストの薄さ、エディタ上の操作の未確認など）を正直に書く
- **判断材料**：`Tested up to` を更新して良いと考える根拠。最終判断は維持者に委ねる

`Tested up to` に書ける上限は、**現行の安定版、または RC が出ている場合はその RC のメジャー番号**
までです。それより先の未リリースのメジャー番号を書くと、プラグインページにエラーが出ます。

そのうえで PR を出します。**マージはしません。**

```bash
git switch -c enhancement/tested-up-to-X-Y
# Tested up to を書き換え
gh pr create --repo "tarosky/${SLUG}" --title 'Tested up to を X.Y に更新' --body-file /tmp/pr-body.md
```

## RC（リリース候補）でのテスト

WordPress の RC 公開時、プラグイン作者にはテストと `Tested up to` の更新が期待されています。
RC のリリース記事にも「テストして readme の `Tested up to` を更新してほしい」と明記されます。
また `Tested up to` に上のメジャー番号を書けるようになるのは RC が出た時点からなので、
**RC はこの作業を始めてよくなる合図**でもあります。

**手順 3〜9 はそのまま使えます。** 変わるのは検証対象の WordPress を差し替える 1 か所と、
判断の基準です。

### WordPress を RC に差し替える

`.wp-env.json` の `core` は zip の URL を受け付けます。リポジトリの `.wp-env.json` は書き換えず、
バージョン管理外の `.wp-env.override.json` に置きます。

```json
{
  "core": "https://wordpress.org/wordpress-7.1-RC1.zip"
}
```

```bash
npx wp-env start --update                # core を差し替えたときは --update が必要
npx wp-env run cli -- wp core version    # 7.1-RC1 と表示されることを確認する
```

ベータ版の zip は `https://downloads.wordpress.org/release/wordpress-7.1-beta4.zip` の形式です
（URL は手順 1 の `?channel=beta` の `download` から取れます）。検証が終わったら
`.wp-env.override.json` を削除し、`npx wp-env start --update` で元に戻します。

### RC のときだけ違うこと

| 項目 | 正式リリース版 | RC 版 |
|---|---|---|
| 起動の契機 | 公開ページに警告が出た | RC が公開された |
| 手順 1 の警告判定 | 判定する | 意味がない（警告は公開済みメジャーが基準）。省略してよい |
| `Tested up to` の上限 | 現行の安定版まで | **その RC のメジャー番号まで**（例: `7.1-RC1` が出ていれば `7.1`）。RC が無い段階（ベータのみ）で先の番号を書くとページにエラーが出る |
| 不具合が見つかったとき | 自リポジトリの issue に記録する | それに加えて**上流に報告する**。本体側の回帰と判断できれば Trac、切り分けが済んでいなければサポートフォーラムへ |
| 非推奨関数の照合（手順 8） | 差分は出にくい | **ここが本番**。新しい非推奨化は RC の `wp-includes/deprecated.php` に最初に現れる |

RC の段階で `Tested up to` を上げた場合、正式リリース後に上げ直す必要はありません
（`7.1` はそのブランチの最新パッチに解決されます）。

### 今後の改良予定

RC の公開を検知してこの検証を自動で起動する仕組み（スケジュール実行）は未実装です。
現時点では、人が RC 公開に気づいて起動する前提です。検知自体は上記
`?channel=beta` の `offers[0].version` を定期的に見れば可能なので、将来ここに組み込む想定です。

## つまずきやすい点

| 事象 | 説明 |
|---|---|
| `Tested up to` の所在 | Tarosky のプラグインは `README.md` を正とし、`readme.txt` は `wp-readme` アクションが生成する。編集するのは `README.md`（リポジトリごとに確認する） |
| パッチバージョン | `7.0.2` ではなく `7.0` と書く。WordPress.org はマイナーを最新パッチに解決する |
| `/wp-admin/widgets.php` が 500 | wp-env の既定テーマはブロックテーマでサイドバー未登録のため、WordPress 自身が 500 を返す。**プラグインの不具合ではない**。ウィジェットを確認するなら `npx wp-env run cli -- wp theme activate twentytwenty` でクラシックテーマに切り替える |
| 画像の添付 | `gh` では画像をアップロードできない。スクリーンショットは人が手で貼る前提で、コメント本文に「スクリーンショットは別コメントに添付します」と書いておく |
| 反映のタイミング | PR のマージだけでは WordPress.org に反映されない。リリースの公開が必要 |

## 参考

- 検証結果コメントの実例: [tarosky/taro-ad-fields#68](https://github.com/tarosky/taro-ad-fields/issues/68)
- `Tested up to` 更新 PR の実例: [tarosky/taro-ad-fields#71](https://github.com/tarosky/taro-ad-fields/pull/71)
- [WordPress.org Plugin Readme](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/)
- [Plugin Developer FAQ](https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/) — `Tested up to` の上限（RC を超えてはいけない）の根拠
- [@wordpress/env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) — `core` に zip URL を指定する方法、`.wp-env.override.json`
- [WordPress 7.0 RC3 のリリース記事](https://wordpress.org/news/2026/05/wordpress-7-0-release-candidate-3/) — RC 時にプラグイン作者へ求められること
- 開発時の規約・セットアップは `tarosky-standards` スキルを参照
