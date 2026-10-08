<?php
/**
 * curated.yml の PHP シンボルが、比較元か比較先のソースに実在するか確かめる（自動マージの関門）.
 *
 * Usage:
 *   php verify-symbols.php --release=7.2 --from-dir=trees/7.1.0 --to-dir=trees/trunk
 *
 * 対象: status が shipped / reverted の変更の function / class / method / hook.
 * planned（まだコードに無い）・dropped は対象外。動的フック名と JS のフック名（"." や "/" を含む）も対象外.
 * 終了コード: 0 = すべて実在, 1 = 実在しないシンボルあり.
 */

require __DIR__ . '/lib/symbol-scanner.php';
require __DIR__ . '/lib/catalog.php';
require __DIR__ . '/lib/verify.php';

$opts = getopt( '', [ 'release:', 'from-dir:', 'to-dir:' ] );
foreach ( [ 'release', 'from-dir', 'to-dir' ] as $key ) {
	if ( empty( $opts[ $key ] ) ) {
		fwrite( STDERR, "--{$key} is required.\n" );
		exit( 1 );
	}
}
$path = catalog_dir( $opts['release'] ) . '/curated.yml';
if ( ! is_file( $path ) ) {
	echo "No curated.yml for {$opts['release']}\n";
	exit( 0 );
}

$tables  = [ scan_wordpress_src( $opts['from-dir'] . '/src' ), scan_wordpress_src( $opts['to-dir'] . '/src' ) ];
$missing = verify_symbols( catalog_read( $path ), $tables );
foreach ( $missing as $line ) {
	echo "MISSING {$line}\n";
}
printf( "%s: %d missing symbol(s)\n", $opts['release'], count( $missing ) );
exit( $missing ? 1 : 0 );
