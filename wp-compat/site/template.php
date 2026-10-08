<?php
/**
 * サイトのテンプレート. build-site.php から読み込まれる.
 *
 * @var array $releases
 */

$labels = [
	'type'       => [
		'removed'             => '削除',
		'deprecated'          => '非推奨',
		'hook_removed'        => 'フック削除',
		'hook_deprecated'     => 'フック非推奨',
		'file_deprecated'     => 'ファイル非推奨',
		'argument_deprecated' => '引数非推奨',
		'behavior'            => '挙動変更',
		'php_requirement'     => '動作要件',
		'editor_js'           => 'エディタ/JS',
		'css'                 => 'CSS/DOM',
		'db'                  => 'DB',
		'feature'             => '機能',
	],
	'severity'   => [ 'high' => '高', 'medium' => '中', 'low' => '低' ],
	'confidence' => [ 'high' => '機械照合', 'medium' => '照合（要確認）', 'manual' => '要目視' ],
	'status'     => [ 'planned' => '予告', 'shipped' => '出荷', 'dropped' => 'ドロップ', 'reverted' => '差し戻し' ],
	'origin'     => [ 'extracted' => '機械抽出', 'curated' => '手動' ],
];
$versions = array_column( $releases, 'release' );
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WP互換性カタログ</title>
<meta name="description" content="WordPress本体のリリースごとの互換性に影響する変更カタログ（Tarosky）">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="site-header">
	<h1>WP互換性カタログ</h1>
	<p>WordPress本体のアップデートで、プラグイン・テーマに影響しうる変更の一覧です。「機械抽出」はソースコードの差分から、「手動」は Dev Notes・Field Guide から作成しています。</p>
</header>

<form class="filters" id="filters" hidden>
	<fieldset class="range">
		<legend>アップデート範囲</legend>
		<label>現在
			<select name="from">
				<?php foreach ( array_reverse( $versions ) as $i => $v ) : ?>
					<option value="<?php echo h( $v ); ?>"<?php selected_attr( 0 === $i ); ?>><?php echo h( prev_version_label( $releases, $v ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<span aria-hidden="true">→</span>
		<label>更新先
			<select name="to">
				<?php foreach ( $versions as $i => $v ) : ?>
					<option value="<?php echo h( $v ); ?>"<?php selected_attr( 0 === $i ); ?>><?php echo h( $v ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
	</fieldset>
	<?php foreach ( [ 'severity', 'status', 'confidence', 'type' ] as $key ) : ?>
		<fieldset>
			<legend><?php echo h( [ 'severity' => '重大度', 'status' => '状態', 'confidence' => '確度', 'type' => '種別' ][ $key ] ); ?></legend>
			<?php foreach ( $labels[ $key ] as $value => $label ) : ?>
				<label class="chip"><input type="checkbox" name="<?php echo h( $key ); ?>" value="<?php echo h( $value ); ?>"<?php echo ( 'severity' === $key && 'low' === $value ) ? '' : ' checked'; ?>> <?php echo h( $label ); ?></label>
			<?php endforeach; ?>
		</fieldset>
	<?php endforeach; ?>
	<label class="search">検索 <input type="search" name="q" placeholder="関数名・フック名など"></label>
	<p class="summary" id="summary" aria-live="polite"></p>
</form>

<main>
<?php foreach ( $releases as $release ) : ?>
	<section class="release" id="release-<?php echo h( $release['release'] ); ?>" data-release="<?php echo h( $release['release'] ); ?>">
		<h2>
			WordPress <?php echo h( $release['release'] ); ?>
			<span class="badge status-<?php echo h( $release['status'] ); ?>"><?php echo h( $release['status'] ); ?></span>
		</h2>
		<?php if ( $release['compared'] ) : ?>
			<p class="meta">比較: <?php echo h( $release['compared']['from'] ); ?> → <?php echo h( $release['compared']['to'] ); ?><?php echo isset( $release['compared']['to_commit'] ) && $release['compared']['to'] !== $release['compared']['to_commit'] ? ' (' . h( substr( $release['compared']['to_commit'], 0, 10 ) ) . ')' : ''; ?>　変更 <?php echo count( $release['changes'] ); ?> 件</p>
		<?php endif; ?>
		<?php if ( $release['sources'] ) : ?>
			<details class="sources">
				<summary>情報源（<?php echo count( $release['sources'] ); ?>）</summary>
				<ul>
					<?php foreach ( $release['sources'] as $source ) : ?>
						<li><a href="<?php echo h( $source['url'] ); ?>"><?php echo h( $source['title'] ?? $source['url'] ); ?></a> <small><?php echo h( $source['type'] ); ?></small></li>
					<?php endforeach; ?>
				</ul>
			</details>
		<?php endif; ?>
		<ul class="changes">
			<?php foreach ( $release['changes'] as $change ) : ?>
				<li class="change sev-<?php echo h( $change['severity'] ); ?>" id="<?php echo h( $release['release'] . '--' . $change['id'] ); ?>"
					data-severity="<?php echo h( $change['severity'] ); ?>" data-status="<?php echo h( $change['status'] ); ?>"
					data-confidence="<?php echo h( $change['confidence'] ); ?>" data-type="<?php echo h( $change['type'] ); ?>">
					<div class="change-head">
						<span class="sev" title="重大度"><?php echo h( $labels['severity'][ $change['severity'] ] ); ?></span>
						<h3><?php echo inline_code( $change['title'] ); ?></h3>
					</div>
					<p class="tags">
						<span><?php echo h( $labels['type'][ $change['type'] ] ); ?></span>
						<span class="st-<?php echo h( $change['status'] ); ?>"><?php echo h( $labels['status'][ $change['status'] ] ); ?></span>
						<span><?php echo h( $labels['confidence'][ $change['confidence'] ] ); ?></span>
						<span class="origin"><?php echo h( $labels['origin'][ $change['origin'] ] ); ?></span>
						<?php if ( ! empty( $change['since'] ) ) : ?>
							<span>since <?php echo h( $change['since'] ); ?></span>
						<?php endif; ?>
					</p>
					<?php if ( ! empty( $change['summary'] ) ) : ?>
						<p class="summary-text"><?php echo nl2br( inline_code( trim( $change['summary'] ) ) ); ?></p>
					<?php endif; ?>
					<?php if ( ( 'curated' === $change['origin'] && ! empty( $change['symbols'] ) ) || ! empty( $change['match_hint'] ) || ! empty( $change['symbols'][0]['replacement'] ) ) : ?>
						<p class="symbols">
							<?php foreach ( $change['symbols'] ?? [] as $symbol ) : ?>
								<code title="<?php echo h( $symbol['kind'] ); ?>"><?php echo h( $symbol['name'] ); ?></code><?php echo isset( $symbol['replacement'] ) ? ' → <code>' . h( $symbol['replacement'] ) . '</code>' : ''; ?>
							<?php endforeach; ?>
							<?php if ( ! empty( $change['match_hint'] ) ) : ?>
								<code class="hint" title="照合用の正規表現">/<?php echo h( $change['match_hint'] ); ?>/</code>
							<?php endif; ?>
						</p>
					<?php endif; ?>
					<p class="links">
						<?php foreach ( $change['source_urls'] ?? [] as $url ) : ?>
							<a href="<?php echo h( $url ); ?>"><?php echo h( link_label( $url ) ); ?></a>
						<?php endforeach; ?>
						<a class="permalink" href="#<?php echo h( $release['release'] . '--' . $change['id'] ); ?>" aria-label="この項目へのリンク">#</a>
					</p>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>
<?php endforeach; ?>
</main>
<footer class="site-footer">
	<p>生成: <?php echo h( gmdate( 'Y-m-d H:i' ) ); ?> UTC ／ <a href="catalog.json">catalog.json</a> ／ <a href="https://github.com/tarosky/workflows/tree/main/wp-compat">tarosky/workflows</a></p>
</footer>
<script src="assets/app.js"></script>
</body>
</html>
<?php

function selected_attr( bool $selected ): void {
	echo $selected ? ' selected' : '';
}

/**
 * 「from」セレクトの表示用: そのリリースの直前のバージョン.
 */
function prev_version_label( array $releases, string $release ): string {
	foreach ( $releases as $r ) {
		if ( $r['release'] === $release && ! empty( $r['compared']['from'] ) ) {
			return preg_replace( '/\.0$/', '', $r['compared']['from'] );
		}
	}
	return "{$release} より前";
}

function link_label( string $url ): string {
	if ( str_contains( $url, 'github.com/WordPress/wordpress-develop' ) ) {
		return 'ソース';
	}
	if ( preg_match( '#make\.wordpress\.org/core/\d{4}/\d{2}/\d{2}/([^/]+)#', $url, $m ) ) {
		return $m[1];
	}
	return parse_url( $url, PHP_URL_HOST ) ?: $url;
}
