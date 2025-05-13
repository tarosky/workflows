# workflows

WordPressテーマ＆プラグイン開発の共通ワークフローです。Taroskyのオーガニゼーションで共有しています。

## GitHub Actions関連

- 共有ワークフロー
- 複合アクション

ドキュメントは[Wiki](https://github.com/tarosky/workflows/wiki)をご覧ください。

## CLI ツール

このリポジトリには、GitHub管理を効率化するためのCLIツール `ts-workflow` が含まれています。

### インストール方法

このリポジトリをクローンします:

```bash
git clone https://github.com/tarosky/workflows.git
```

`bin` ディレクトリにパスを通します:

```bash
# ~/.bashrc または ~/.zshrc に追加
export PATH="/path/to/workflows/bin:$PATH"
```

設定を反映させます:

```bash
source ~/.bashrc  # または source ~/.zshrc
```

### 使用方法

```bash
ts-workflow <サブコマンド> [オプション]
```

**注意**: アーカイブされたリポジトリや無効化されたリポジトリは自動的にスキップされます。

#### サブコマンド

##### create-issues

特定のラベル（トピック）がついたリポジトリすべてに同じ内容のイシューを登録します。

```bash
ts-workflow create-issues --label <ラベル> --title <タイトル> [オプション]
```

オプション:

- `--label <ラベル>`: リポジトリをフィルタリングするラベル/トピック（必須）
- `--title <タイトル>`: イシューのタイトル（必須）
- `--body <本文>`: イシューの本文
- `--body-file <ファイル>`: イシューの本文をファイルから読み込む
- `--issue-labels <ラベル>`: イシューに付けるラベル（カンマ区切り）
- `--assignee <アサイン先>`: イシューのアサイン先（ユーザー名またはチーム名、例: tarosky/maintainers）。チーム名を指定した場合、そのチームのメンバー全員にアサインされます。
- `--org <組織名>`: 特定の組織のリポジトリのみを対象にする
- `--dry-run`: 実際に変更を加えずに実行内容を表示
- `--help`: ヘルプを表示

※ラベルを指定した場合、そのラベルは存在している必要があります。後述する `sync-labels` で同じラベルを先に作っておくとよいでしょう。

##### sync-labels

特定のラベル（トピック）がついたリポジトリのラベルを共通化します。

```bash
ts-workflow sync-labels --label <ラベル> --source <ソースリポジトリ> --org <組織名> [オプション]
```

オプション:

- `--label <ラベル>`: リポジトリをフィルタリングするラベル/トピック（必須）
- `--source <ソースリポジトリ>`: ラベルのコピー元リポジトリ（必須、形式: owner/repo）
- `--org <組織名>`: 特定の組織のリポジトリのみを対象にする（必須）
- `--label-filter <フィルタ>`: 特定のラベルのみを同期（glob パターン）
- `--dry-run`: 実際に変更を加えずに実行内容を表示
- `--help`: ヘルプを表示

### 必要条件

- GitHub CLI (`gh`) がインストールされていること
- `gh` コマンドで GitHub に認証済みであること
- `jq` コマンドがインストールされていること

## ボイラープレート

プロジェクトまたはテーマを開始するときに、[boilerplate](./boilerplate)内にあるファイルを作成します。

```bash
curl -L https://raw.githubusercontent.com/tarosky/workflows/main/setup.sh | bash
```

詳細は[Wiki](https://github.com/tarosky/workflows/wiki/boiler%E2%80%90plate)をご覧ください。
