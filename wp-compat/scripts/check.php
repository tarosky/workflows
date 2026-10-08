<?php
/**
 * プラグイン・テーマのコードをカタログと照合する.
 *
 * Usage:
 *   php check.php --path=../my-plugin --from=6.6 --to=7.1 [--format=markdown|json]
 *                 [--catalog=URL|catalog.json] [--repo=owner/name] [--ref=sha]
 *
 * --catalog を省略するとローカルの catalog/ を読む.
 * 終了コード: 0 = 該当なし, 2 = 該当あり（重大度 中以上）, 1 = エラー
 */

require __DIR__ . '/lib/catalog.php';
require __DIR__ . '/lib/code-index.php';
require __DIR__ . '/lib/matcher.php';
require __DIR__ . '/lib/report.php';

$opts = getopt( '', [ 'path:', 'from:', 'to:', 'format:', 'catalog:', 'repo:', 'ref:', 'exclude:' ] );
foreach ( [ 'path' ] as $key ) {
	if ( empty( $opts[ $key ] ) ) {
		fwrite( STDERR, "--{$key} is required.\n" );
		exit( 1 );
	}
}
if ( ! is_dir( $opts['path'] ) ) {
	fwrite( STDERR, "Not a directory: {$opts['path']}\n" );
	exit( 1 );
}

$catalog    = load_catalog( $opts['catalog'] ?? null );
[ $from, $to ] = resolve_range( $catalog, $opts['from'] ?? '', $opts['to'] ?? 'latest' );
$exclude   = array_merge( WP_COMPAT_EXCLUDE_DIRS, array_filter( explode( ',', $opts['exclude'] ?? '' ) ) );
$index     = code_index_build( $opts['path'], $exclude );
$result    = matcher_run( $catalog, $index, $from, $to );
fprintf( STDERR, "Checked %s -> %s: %d matched, %d checklist, %d reference\n", $from, $to, count( $result['matched'] ), count( $result['checklist'] ), count( $result['weak'] ) );

if ( 'json' === ( $opts['format'] ?? 'markdown' ) ) {
	echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), "\n";
} else {
	echo report_markdown( $result, $opts['repo'] ?? '', $opts['ref'] ?? 'HEAD' );
}

$significant = array_filter( $result['matched'], fn( $item ) => 'low' !== $item['severity'] );
exit( $significant ? 2 : 0 );

/**
 * to=latest はカタログの最新リリース、from 省略時は to の直前のリリース.
 */
function resolve_range( array $catalog, string $from, string $to ): array {
	$releases = array_column( $catalog['releases'], 'release' );
	usort( $releases, 'version_compare' );
	if ( 'latest' === $to || '' === $to ) {
		$to = end( $releases );
	}
	if ( '' === $from ) {
		$previous = array_values( array_filter( $releases, fn( $r ) => version_compare( $r, major( $to ), '<' ) ) );
		if ( ! $previous ) {
			fwrite( STDERR, "Cannot determine --from for {$to}.\n" );
			exit( 1 );
		}
		$from = end( $previous );
	}
	return [ $from, $to ];
}

/**
 * catalog.json（URL かファイル）を読む。無ければローカルの YAML から組み立てる.
 */
function load_catalog( ?string $source ): array {
	if ( $source ) {
		$json = file_get_contents( $source );
		if ( false === $json ) {
			fwrite( STDERR, "Failed to load catalog: {$source}\n" );
			exit( 1 );
		}
		return json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
	}
	$releases = [];
	foreach ( catalog_releases() as $release ) {
		$changes = [];
		$status  = 'final';
		foreach ( catalog_load_release( $release ) as $catalog ) {
			$status  = $catalog['status'];
			$changes = array_merge( $changes, $catalog['changes'] );
		}
		$releases[] = [ 'release' => $release, 'status' => $status, 'changes' => $changes ];
	}
	return [ 'releases' => $releases ];
}
