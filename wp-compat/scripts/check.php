<?php
/**
 * プラグイン・テーマのコードをカタログと照合する.
 *
 * Usage:
 *   php check.php --path=../my-plugin [--from=6.6] [--to=7.1|latest] [--format=markdown|json]
 *                 [--catalog=URL|catalog.json] [--repo=owner/name] [--ref=sha] [--json-out=result.json]
 *
 * --from 省略時は readme の Tested up to、それも無ければ --to の直前のリリース.
 * --to 省略時はカタログの最新リリース.
 * --catalog を省略するとローカルの catalog/ を読む.
 * 終了コード: 0 = 該当なし, 2 = 該当あり（重大度 中以上）, 1 = エラー
 */

require __DIR__ . '/lib/catalog.php';
require __DIR__ . '/lib/code-index.php';
require __DIR__ . '/lib/matcher.php';
require __DIR__ . '/lib/report.php';

$opts = getopt( '', [ 'path:', 'from:', 'to:', 'format:', 'catalog:', 'repo:', 'ref:', 'exclude:', 'json-out:' ] );
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

$catalog = load_catalog( $opts['catalog'] ?? null );
try {
	$range = matcher_resolve_range( $catalog, $opts['from'] ?? '', $opts['to'] ?? 'latest', code_tested_up_to( $opts['path'] ) );
} catch ( RuntimeException $e ) {
	fwrite( STDERR, $e->getMessage() . "\n" );
	exit( 1 );
}
$exclude = array_merge( WP_COMPAT_EXCLUDE_DIRS, array_filter( explode( ',', $opts['exclude'] ?? '' ) ) );
$index   = code_index_build( $opts['path'], $exclude );
$result  = matcher_run( $catalog, $index, $range['from'], $range['to'] ) + [ 'from_source' => $range['from_source'] ];
fprintf( STDERR, "Checked %s -> %s (from: %s): %d matched, %d checklist, %d reference\n", $range['from'], $range['to'], $range['from_source'], count( $result['matched'] ), count( $result['checklist'] ), count( $result['weak'] ) );

if ( ! empty( $opts['json-out'] ) ) {
	file_put_contents( $opts['json-out'], json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
}
if ( 'json' === ( $opts['format'] ?? 'markdown' ) ) {
	echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), "\n";
} else {
	echo report_markdown( $result, $opts['repo'] ?? '', $opts['ref'] ?? 'HEAD' );
}

$significant = array_filter( $result['matched'], fn( $item ) => 'low' !== $item['severity'] );
exit( $significant ? 2 : 0 );

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
		$catalogs = catalog_load_release( $release );
		$changes  = array_merge( ...array_map( fn( $c ) => $c['changes'], array_values( $catalogs ) ) );
		$releases[] = [ 'release' => $release, 'status' => catalog_release_status( $catalogs ), 'changes' => $changes ];
	}
	return [ 'releases' => $releases ];
}
