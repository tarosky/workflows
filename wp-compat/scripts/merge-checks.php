<?php
/**
 * 既存 Issue 本文のチェック済み項目を、新しいレポートに引き継いで出力する.
 *
 * Usage: php merge-checks.php old-body.md new-report.md
 */

require __DIR__ . '/lib/matcher.php';
require __DIR__ . '/lib/report.php';

if ( $argc < 3 ) {
	fwrite( STDERR, "Usage: php merge-checks.php old-body.md new-report.md\n" );
	exit( 1 );
}
echo report_merge_checks( file_get_contents( $argv[1] ), file_get_contents( $argv[2] ) );
