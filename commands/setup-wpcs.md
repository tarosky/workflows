---
description: WordPress Coding Standards (WPCS) のセットアップ - composer.json、phpcs.ruleset.xml、composer scriptsを設定
allowed-tools: [Read, Write, Edit, Bash(composer), Bash(php), Bash(ls), Bash(cat), Glob, Grep]
---

# WPCS セットアップ

このコマンドは、WordPress Coding Standards (WPCS) をプロジェクトにセットアップします。

## 手順

以下の手順で WPCS をセットアップしてください：

### 1. composer.json の確認

まず、プロジェクトルートに `composer.json` が存在するか確認してください。

- **存在する場合**: 次のステップに進む
- **存在しない場合**: `composer init` を実行して作成を提案する
  - name: ユーザーに確認（例: `tarosky/project-name`）
  - description: プロジェクトの説明
  - type: `wordpress-plugin` または `wordpress-theme`
  - license: `GPL-3.0-or-later`
  - minimum-stability: `stable`
  - require php: `>=7.4`

### 2. WPCS 関連パッケージのインストール

以下のパッケージを `require-dev` に追加：

```bash
composer require --dev squizlabs/php_codesniffer wp-coding-standards/wpcs phpcompatibility/phpcompatibility-wp dealerdirect/phpcodesniffer-composer-installer
```

### 3. phpcs.ruleset.xml の作成

プロジェクトルートに `phpcs.ruleset.xml` を作成します。

**既存ファイルがある場合**: 差分を確認し、マージを提案してください。

**新規作成の場合**: 以下のテンプレートを使用：

```xml
<?xml version="1.0"?>
<ruleset name="WordPress Coding Standards for Plugins">
	<description>Generally-applicable sniffs for WordPress plugins</description>

	<arg name="colors" />
	<arg value="ps" />

	<rule ref="WordPress-Core">
		<exclude name="Generic.Files.LowercasedFilename" />
		<exclude name="Generic.Commenting.DocComment.ShortNotCapital" />
		<exclude name="Squiz.Commenting.FunctionComment.ParamCommentFullStop" />
		<exclude name="WordPress.Files.FileName" />
		<exclude name="Squiz.Commenting.FileComment" />
		<exclude name="Squiz.PHP.CommentedOutCode" />
		<exclude name="Squiz.PHP.DisallowMultipleAssignments.Found" />
		<exclude name="Squiz.Commenting.VariableComment.Missing" />
		<exclude name="Squiz.Commenting.LongConditionClosingComment.Missing" />
		<exclude name="WordPress.WP.I18n.SingleUnderscoreGetTextFunction" />
		<exclude name="WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid" />
		<exclude name="WordPress.PHP.DisallowShortTernary.Found" />
		<exclude name="WordPress.DateTime.CurrentTimeTimestamp.RequestedUTC" />
		<exclude name="Universal.Arrays.DisallowShortArraySyntax.Found" />
		<exclude name="PEAR.Functions.FunctionCallSignature.CloseBracketLine" />
		<exclude name="PEAR.Functions.FunctionCallSignature.ContentAfterOpenBracket" />
		<exclude name="PEAR.Functions.FunctionCallSignature.MultipleArguments"/>
		<exclude name="WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound" />
		<exclude name="WordPress.WP.I18n.MissingTranslatorsComment" />
	</rule>

	<!-- 除外パターン -->
	<exclude-pattern>*/node_modules/*</exclude-pattern>
	<exclude-pattern>*.js</exclude-pattern>
	<exclude-pattern>*/vendor/*</exclude-pattern>
	<exclude-pattern>*/wordpress/*</exclude-pattern>
	<exclude-pattern>*/build/*</exclude-pattern>
	<exclude-pattern>*/wp/*</exclude-pattern>
	<exclude-pattern>*/tests/*</exclude-pattern>
</ruleset>
```

### 4. プロジェクト構造の分析

プロジェクト構造を分析し、除外パターンを自動調整してください：

- `src/` ディレクトリがあれば対象に含める
- `includes/` ディレクトリがあれば対象に含める
- `dist/` ディレクトリがあれば除外に追加
- `assets/` 内の PHP 以外を除外
- その他、プロジェクト固有のディレクトリを確認

### 5. composer scripts の追加

`composer.json` に以下のスクリプトを追加（既存の場合はマージ）：

```json
{
  "scripts": {
    "lint": "phpcs --standard=phpcs.ruleset.xml .",
    "fix": "phpcbf --standard=phpcs.ruleset.xml ."
  }
}
```

**注意**: 既存の `scripts` がある場合は、内容をマージしてください。

### 6. 動作確認

セットアップ完了後、以下のコマンドで動作確認を実行：

```bash
composer lint
```

エラーがあれば内容を報告し、修正方法を提案してください。

## 参考情報

- Tarosky 標準設定: https://github.com/tarosky/workflows/tree/main/boilerplate
- WPCS 公式: https://github.com/WordPress/WordPress-Coding-Standards
- PHPCompatibility: https://github.com/PHPCompatibility/PHPCompatibility
