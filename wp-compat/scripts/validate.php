<?php
/**
 * カタログをスキーマ検証する.
 *
 * Usage:
 *   php validate.php            # catalog/ 配下すべて
 *   php validate.php path.yml   # 指定ファイル
 */

require __DIR__ . '/lib/catalog.php';

$files = array_slice( $argv, 1 ) ?: glob( WP_COMPAT_ROOT . '/catalog/*/*.yml' );
$fail  = 0;
foreach ( $files as $file ) {
	try {
		$errors = catalog_validate( catalog_read( $file ) );
	} catch ( Throwable $e ) {
		$errors = [ $e->getMessage() ];
	}
	if ( $errors ) {
		++$fail;
		printf( "NG %s\n  %s\n", $file, implode( "\n  ", $errors ) );
	} else {
		printf( "OK %s\n", $file );
	}
}
foreach ( array_unique( array_map( fn( $f ) => basename( dirname( $f ) ), $files ) ) as $release ) {
	$errors = catalog_duplicates( $release );
	if ( $errors ) {
		++$fail;
		printf( "NG %s (duplicates)\n  %s\n", $release, implode( "\n  ", $errors ) );
	}
}
exit( $fail ? 1 : 0 );
