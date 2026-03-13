---
name: tarosky-standards
description: Tarosky WordPress開発標準を適用するスキル。PHPファイルの作成・編集時、composer.jsonの変更時、またはコード品質やコーディング規約に関する質問があった場合に自動的にアクティブになります。
allowed-tools: [Read, Write, Edit, Bash(composer), Bash(php), Bash(phpcs), Bash(phpcbf), Glob, Grep]
---

# Tarosky WordPress 開発標準

このスキルは、Tarosky の WordPress 開発標準に関するガイダンスを提供します。

## このスキルが有効になるとき

- PHP ファイルを作成または編集しているとき
- `composer.json` を変更しているとき
- コーディング規約やコード品質について質問があったとき
- WordPress プラグイン/テーマの開発を行っているとき

## Tarosky コーディング標準

### PHP コーディング規約

Tarosky では WordPress Coding Standards (WPCS) をベースに、以下の除外ルールを適用しています：

1. **ファイル名規約の緩和**
   - `Generic.Files.LowercasedFilename` - PSR-4 オートロードのため
   - `WordPress.Files.FileName` - クラス名に合わせたファイル名を許可

2. **コメント規約の緩和**
   - `Squiz.Commenting.FileComment` - ファイルヘッダコメント不要
   - `Squiz.Commenting.FunctionComment.ParamCommentFullStop` - パラメータ説明の句点不要
   - `Squiz.Commenting.VariableComment.Missing` - 変数コメント不要

3. **コーディングスタイルの緩和**
   - `WordPress.PHP.DisallowShortTernary.Found` - 短縮三項演算子を許可
   - `Universal.Arrays.DisallowShortArraySyntax.Found` - 短縮配列構文を許可
   - `PEAR.Functions.FunctionCallSignature.*` - 関数呼び出しの柔軟なフォーマット

4. **その他**
   - `Squiz.PHP.CommentedOutCode` - コメントアウトされたコードを許可
   - `WordPress.WP.I18n.MissingTranslatorsComment` - 翻訳者コメント不要

### 推奨パッケージ

```json
{
  "require-dev": {
    "squizlabs/php_codesniffer": "^3.0",
    "wp-coding-standards/wpcs": "^3.0",
    "phpcompatibility/phpcompatibility-wp": "^2.0",
    "dealerdirect/phpcodesniffer-composer-installer": "^1.0"
  }
}
```

### Composer Scripts

```json
{
  "scripts": {
    "lint": "phpcs --standard=phpcs.ruleset.xml .",
    "fix": "phpcbf --standard=phpcs.ruleset.xml ."
  }
}
```

## コードレビュー時のチェックポイント

1. `composer lint` がエラーなく通ること
2. PHP 7.4 以上の互換性があること
3. セキュリティ上の問題がないこと（エスケープ、サニタイズ）
4. 適切な国際化（i18n）が行われていること

## 参考リンク

- [Tarosky Workflows](https://github.com/tarosky/workflows)
- [WPCS 公式](https://github.com/WordPress/WordPress-Coding-Standards)
- [WordPress Plugin Handbook](https://developer.wordpress.org/plugins/)
