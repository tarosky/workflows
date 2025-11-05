#!/usr/bin/env node

/**
 * @wordpress/create-block を使用して新しいブロックを作成するシンタックスシュガー
 *
 * 使い方: npm run block:create ブロック名
 *
 * 以下がデフォルト値。変更したい場合はpackage.jsonに次の項目を追加する。
 *
 * - 名前空間はbin/..のディレクトリ名になる。
 * - assets/blocks/* にブロックを作成する。
 *
 * ```
 * {
 *    "scripts": {
 *      "block:create": "node bin/create-block.js"
 *    }
 *    "tsWorkflows": {
 *      "blockNamespace": "src/blocks"
 *      "blockSrcDir": "src/blocks"
 *    }
 * }
 * ```
 */

const { execSync } = require('child_process');
const path = require('path');
const fs = require('fs');

// コマンドライン引数からブロック名を取得
const blockSlug = process.argv[2];

if (!blockSlug) {
	console.error('Error: Block name is required');
	console.log('Usage: npm run create-block <block-name>');
	console.log('Example: npm run create-block my-custom-block');
	process.exit(1);
}

// ブロック名のバリデーション（kebab-case形式であること）
if (!/^[a-z][a-z0-9-]*$/.test(blockSlug)) {
	console.error('Error: Block name must be lowercase letters, numbers, and hyphens only');
	console.log('Example: my-custom-block');
	process.exit(1);
}

// package.jsonから設定を読み込む
const packageJsonPath = path.join(__dirname, '..', 'package.json');
let packageJson = {};
try {
	packageJson = JSON.parse(fs.readFileSync(packageJsonPath, 'utf8'));
} catch (error) {
	console.error('Warning: Could not read package.json');
}

// package.jsonから設定を取得、なければデフォルト値を使用
const tsWorkflows = packageJson.tsWorkflows || {};

// 名前空間の取得: tsWorkflows.blockNamespaceを使用、なければ親ディレクトリ名をフォールバック
const namespace = tsWorkflows.blockNamespace || path.basename(path.resolve(__dirname, '..'));

// ディレクトリ設定をデフォルト値付きで取得
const blockSrcDir = tsWorkflows.blockSrcDir || 'assets/blocks';

// スラッグをタイトルに変換（例: "my-block" → "My Block"）
const blockTitle = blockSlug
	.split('-')
	.map(word => word.charAt(0).toUpperCase() + word.slice(1))
	.join(' ');

// コマンドを構築
const targetDir = path.join(blockSrcDir, blockSlug);
const command = [
	'npx',
	'@wordpress/create-block',
	blockSlug,
	'--no-plugin',
	`--namespace ${namespace}`,
	`--target-dir ${targetDir}`,
	`--title "${blockTitle}"`
].join(' ');

console.log(`Creating block: ${blockSlug}`);
console.log(`Namespace: ${namespace}`);
console.log(`Title: ${blockTitle}`);
console.log(`Location: ${targetDir}`);
console.log('');

try {
	execSync(command, { stdio: 'inherit' });
	console.log('');
	console.log('✅ ブロックの雛形が作成されました！');
	console.log('');
	console.log('設定:');
	console.log(`  ソース: ${blockSrcDir}`);
	console.log('');
	console.log('次のステップ');
	console.log(`  1. ${blockSrcDir}/${blockSlug} にあるファイルを編集してください`);
	console.log(`  2. ビルドします: npx wp-scripts build --source-path=${blockSrcDir} --output-path=path/to/assets/blocks`);
} catch (error) {
	console.error('失敗しました: ', error );
	process.exit(1);
}
