# workflows

Taroskyのオーガニゼーションで共有している[ワークフロー](https://docs.github.com/ja/actions/using-workflows/reusing-workflows)＆[複合アクション](https://docs.github.com/ja/actions/creating-actions/creating-a-composite-action)です。

## ワークフロー

[.github/workflows](./.github/workflows)に格納されています。それ自体で完結します。各オプションはymlファイルの先頭に記載されている`inputs`項目で確認できます。オプションはjob内の`with`で[指定](https://docs.github.com/ja/actions/using-workflows/workflow-syntax-for-github-actions#jobsjob_idwith)します。

### PHPUnit

PHP+MySQL+WordPressをインストールし、ユニットテストを行います。リポジトリにcomposerが入っており、PHPUnitをcomposer scriptから実行できる必要があります。

```
jobs:
  test:
    strategy:
      matrix:
        php: [ '7.3', '7.4', '8.0' ] # PHP versions to check.
        wp: [ 'latest', '5.9' ]      # WordPress version to check.
    uses: tarosky/workflows/.github/workflows/wp-unit-test.yml@main
    with:
        php_version: ${{ matrix.php }}
        wp_version: ${{ matrix.wp }}
```

### PHP CodeSniffer

PHPでcomposerをインストールし、スクリプトを実行します。

```
jobs:
  phpcs:
    uses: tarosky/workflows/.github/workflows/phpcs.yml@main
    with:
      version: 8.0
```

### npmテスト

Nodeをインストールし、npmでテストを行います。npm スクリプトで `test` が動作することが前提です。また、`package`にコマンド名を渡すことで、ビルドが成功するかを検証することも可能です。これはdependabotなどを有効にしている場合に活用できます。

```
jobs:
  assets:
    uses: tarosky/workflows/.github/workflows/npm.yml@main
    with:
      
      package: package
```

## 複合アクション

複合アクションは `job.steps` で指定します。workflowと同様、オプション項目を`with`で指定します。

### distignore

`.distignore` ファイルがリポジトリにある場合、記載されているファイルをすべて削除します。リリース前の処理などに便利です。

```
jobs:
  release:
    steps:
      - name: Clean Package
        uses: tarosky/workflows/actions/distignore@main
```

### backlog

Backlogの課題番号がコミットメッセージに含まれている場合、通知を行います。tarosky-bot というユーザーがBacklogの当該プロジェクトのメンバーになっている必要があります。

```
jobs:
  test:
    steps:
      # stepのどこかに以下を記載。
      - name: Notify Backlog
        uses: tarosky/workflows/actions/backlog@main
        with:
          project: PJ_STANDARD
```

-----

W.I.P