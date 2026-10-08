<?php
/**
 * catalog/ から GitHub Pages 用の静的サイトを生成する.
 *
 * Usage:
 *   php build-site.php [--out=_site]
 *
 * 出力:
 *   index.html   全リリースの一覧（JSなしでも読める）
 *   catalog.json 全カタログ（照合スクリプト・外部からの参照用）
 *   assets/      CSS / JS
 */

require __DIR__ . '/lib/catalog.php';

$opts = getopt( '', [ 'out:' ] );
$out  = rtrim( $opts['out'] ?? WP_COMPAT_ROOT . '/_site', '/' );
if ( ! is_dir( "{$out}/assets" ) ) {
	mkdir( "{$out}/assets", 0755, true );
}

$releases = [];
foreach ( array_reverse( catalog_releases() ) as $release ) {
	$catalogs = catalog_load_release( $release );
	$changes  = [];
	$sources  = [];
	foreach ( $catalogs as $origin => $catalog ) {
		$errors = catalog_validate( $catalog );
		if ( $errors ) {
			fwrite( STDERR, "Invalid catalog {$release}/{$origin}.yml:\n  " . implode( "\n  ", $errors ) . "\n" );
			exit( 1 );
		}
		foreach ( $catalog['changes'] as $change ) {
			$changes[] = $change + [ 'origin' => $origin ];
		}
		$sources = array_merge( $sources, $catalog['sources'] ?? [] );
	}
	usort( $changes, 'compare_changes' );
	$releases[] = [
		'release'  => $release,
		'status'   => catalog_release_status( $catalogs ),
		'compared' => $catalogs['extracted']['compared'] ?? null,
		'sources'  => $sources,
		'changes'  => $changes,
	];
}

file_put_contents( "{$out}/catalog.json", json_encode( [ 'generated_at' => gmdate( 'c' ), 'releases' => $releases ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
foreach ( glob( WP_COMPAT_ROOT . '/site/assets/*' ) as $asset ) {
	copy( $asset, "{$out}/assets/" . basename( $asset ) );
}

ob_start();
require WP_COMPAT_ROOT . '/site/template.php';
file_put_contents( "{$out}/index.html", ob_get_clean() );

printf( "Built %d releases, %d changes -> %s\n", count( $releases ), array_sum( array_map( fn( $r ) => count( $r['changes'] ), $releases ) ), $out );

/**
 * 重大度 → ステータス → 確度の順に並べる.
 */
function compare_changes( array $a, array $b ): int {
	$order = [
		'severity'   => [ 'high' => 0, 'medium' => 1, 'low' => 2 ],
		'status'     => [ 'shipped' => 0, 'reverted' => 1, 'planned' => 2, 'dropped' => 3 ],
		'confidence' => [ 'high' => 0, 'medium' => 1, 'manual' => 2 ],
	];
	foreach ( [ 'status', 'severity', 'confidence' ] as $key ) {
		$diff = $order[ $key ][ $a[ $key ] ] <=> $order[ $key ][ $b[ $key ] ];
		if ( $diff ) {
			return $diff;
		}
	}
	return strcmp( $a['id'], $b['id'] );
}

function h( ?string $text ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_HTML5 );
}

/**
 * タイトル中の `code` を <code> にする.
 */
function inline_code( string $text ): string {
	return preg_replace( '/`([^`]+)`/', '<code>$1</code>', h( $text ) );
}
