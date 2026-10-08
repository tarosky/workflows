<?php
/**
 * Make/Core の投稿をリリース期間ごとに取得してローカルに保存する。
 *
 * Usage:
 *   php fetch-make-posts.php --version=7.1 --after=2026-05-20 --before=2026-09-02 --out=tmp/wp-compat/sources
 *
 * 期間内の全投稿を保存し、以下に該当するものに relevance を付ける。
 *   - dev-notes-X-Y タグ
 *   - Field Guide / Roadmap / Beta / RC の投稿
 *   - タイトルに X.Y を含む投稿
 */

require __DIR__ . '/lib/http.php';

const MAKE_CORE = 'https://make.wordpress.org/core/wp-json/wp/v2';

$opts = getopt( '', [ 'version:', 'after:', 'before:', 'out:' ] );
foreach ( [ 'version', 'after', 'before', 'out' ] as $key ) {
	if ( empty( $opts[ $key ] ) ) {
		fwrite( STDERR, "--{$key} is required.\n" );
		exit( 1 );
	}
}

$version  = $opts['version'];
$ver_slug = str_replace( '.', '-', $version );
$dir      = rtrim( $opts['out'], '/' ) . '/' . $version . '/make';
if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) ) {
	fwrite( STDERR, "Failed to create {$dir}\n" );
	exit( 1 );
}

// dev-notes タグID（6.3 のように表記揺れがあるため検索で解決する）.
$dev_tag_id = null;
foreach ( http_get_json( MAKE_CORE . '/tags?search=' . rawurlencode( 'dev-notes' ) . '&per_page=100&_fields=id,slug' ) as $tag ) {
	if ( preg_replace( '/[^0-9]/', '', $tag['slug'] ) === str_replace( '.', '', $version ) ) {
		$dev_tag_id = $tag['id'];
	}
}

$index = [];
$page  = 1;
do {
	$url   = sprintf(
		'%s/posts?after=%sT00:00:00&before=%sT00:00:00&per_page=100&page=%d&_fields=id,date,slug,link,title,tags,content',
		MAKE_CORE,
		$opts['after'],
		$opts['before'],
		$page
	);
	$posts = http_get_json( $url, $total_pages );
	foreach ( $posts as $post ) {
		$title     = html_entity_decode( $post['title']['rendered'], ENT_QUOTES | ENT_HTML5 );
		$relevance = classify_post( $post, $title, $version, $ver_slug, $dev_tag_id );
		$record    = [
			'id'        => $post['id'],
			'date'      => $post['date'],
			'slug'      => $post['slug'],
			'link'      => $post['link'],
			'title'     => $title,
			'relevance' => $relevance,
		];
		$index[]   = $record;
		file_put_contents(
			"{$dir}/{$post['slug']}.json",
			json_encode( $record + [ 'text' => html_to_text( $post['content']['rendered'] ) ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);
	}
	++$page;
} while ( $page <= $total_pages );

file_put_contents( "{$dir}/_index.json", json_encode( $index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

$relevant = array_filter( $index, fn( $r ) => $r['relevance'] );
printf( "%s: %d posts, %d relevant (dev-notes tag id: %s)\n", $version, count( $index ), count( $relevant ), $dev_tag_id ?? 'none' );

/**
 * 投稿の関連種別を返す。無関係なら null。
 */
function classify_post( array $post, string $title, string $version, string $ver_slug, ?int $dev_tag_id ): ?string {
	$slug = $post['slug'];
	if ( $dev_tag_id && in_array( $dev_tag_id, $post['tags'], true ) ) {
		return 'dev-note';
	}
	if ( str_contains( $slug, "{$ver_slug}-field-guide" ) ) {
		return 'field-guide';
	}
	if ( preg_match( "/(roadmap-to-{$ver_slug}|{$ver_slug}-(beta|release-candidate|rc)|{$ver_slug}-release-day)/", $slug ) ) {
		return 'release';
	}
	if ( preg_match( '/(^|\s)' . preg_quote( $version, '/' ) . '(\D|$)/', $title ) ) {
		return 'mentions-version';
	}
	return null;
}

/**
 * HTMLをざっくりテキスト化する（見出し・リスト・コードは記号で残す）.
 */
function html_to_text( string $html ): string {
	// make.wordpress.org の用語集ツールチップを除去.
	$html = preg_replace( "/<span class='glossary-item-hidden-content'><span class='glossary-item-header'>.*?<\/span>\s*<span class='glossary-item-description'>.*?<\/span><\/span>/s", '', $html );
	$html = preg_replace( '/<h([1-6])[^>]*>/i', "\n\n" . '$0', $html );
	$html = preg_replace_callback( '/<h([1-6])[^>]*>(.*?)<\/h\1>/is', fn( $m ) => str_repeat( '#', (int) $m[1] ) . ' ' . strip_tags( $m[2] ) . "\n", $html );
	$html = preg_replace( '/<li[^>]*>/i', "\n- ", $html );
	$html = preg_replace( '/<a [^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/is', '$2 <$1>', $html );
	$html = preg_replace( '/<\/(p|pre|div|ul|ol|table|tr)>/i', "\n", $html );
	$html = preg_replace( '/<code[^>]*>(.*?)<\/code>/is', '`$1`', $html );
	$text = html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5 );
	return trim( preg_replace( "/\n{3,}/", "\n\n", $text ) );
}
