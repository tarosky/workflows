<?php
/**
 * 照合結果を Issue に反映する（作成・更新・再オープン・古いリリースのクローズ）.
 *
 * Usage:
 *   php upsert-issue.php --repo=owner/name --result=result.json --body=report.md [--label=wp-compat] [--dry-run]
 *
 * result.json と report.md は check.php の出力（--format=json / markdown）。
 * 判断のルールは lib/issues.php を参照.
 */

require __DIR__ . '/lib/matcher.php';
require __DIR__ . '/lib/report.php';
require __DIR__ . '/lib/issues.php';

$opts = getopt( '', [ 'repo:', 'result:', 'body:', 'label:', 'dry-run' ] );
foreach ( [ 'repo', 'result', 'body' ] as $key ) {
	if ( empty( $opts[ $key ] ) ) {
		fwrite( STDERR, "--{$key} is required.\n" );
		exit( 1 );
	}
}
$repo    = $opts['repo'];
$label   = $opts['label'] ?? 'wp-compat';
$dry_run = isset( $opts['dry-run'] );
$result  = json_decode( file_get_contents( $opts['result'] ), true, 512, JSON_THROW_ON_ERROR );
$body    = file_get_contents( $opts['body'] );
$release = major( $result['to'] );
$ids     = report_significant_ids( $result );

$issues = gh_json( [ 'issue', 'list', '-R', $repo, '--label', $label, '--state', 'all', '--limit', '200', '--json', 'number,state,body' ] );
$plan   = issue_plan( $issues, $release, $body, $ids, (bool) $ids );
printf( "%s: %s (%s)\n", $release, $plan['action'], $plan['reason'] );

$number = $plan['number'];
switch ( $plan['action'] ) {
	case 'update':
		gh( [ 'issue', 'edit', $number, '-R', $repo, '--body-file', temp_file( $plan['body'] ) ], $dry_run );
		break;

	case 'reopen':
		gh( [ 'issue', 'edit', $number, '-R', $repo, '--body-file', temp_file( $plan['body'] ) ], $dry_run );
		gh( [ 'issue', 'reopen', $number, '-R', $repo, '--comment', reopen_comment( $plan['new_ids'], $result ) ], $dry_run );
		break;

	case 'create':
		ensure_label( $repo, $label, $dry_run );
		$url    = gh( [ 'issue', 'create', '-R', $repo, '--title', "WordPress {$release} 互換性チェック", '--label', $label, '--body-file', temp_file( $plan['body'] ) ], $dry_run );
		$number = $dry_run ? 'NEW' : (int) basename( trim( $url ) );
		break;
}

foreach ( $plan['supersede'] as $old ) {
	gh( [ 'issue', 'close', $old, '-R', $repo, '--comment', "WordPress {$release} の互換性チェック #{$number} に引き継ぎました。新しい Issue は更新先までの全リリースを照合しています。" ], $dry_run );
}

/**
 * 再オープン時のコメント。新しく該当した変更を列挙する.
 */
function reopen_comment( array $new_ids, array $result ): string {
	$titles = [];
	foreach ( $result['matched'] as $item ) {
		if ( in_array( $item['id'] . '@' . $item['release'], $new_ids, true ) ) {
			$titles[] = sprintf( '- %s %s %s', WP_COMPAT_SEVERITY_LABEL[ $item['severity'] ], $item['release'], report_inline( $item['title'] ) );
		}
	}
	return "前回の確認以降に、新しい該当が見つかったため再オープンしました。\n\n" . implode( "\n", $titles );
}

function ensure_label( string $repo, string $label, bool $dry_run ): void {
	$labels = gh_json( [ 'label', 'list', '-R', $repo, '--search', $label, '--json', 'name' ] );
	if ( ! in_array( $label, array_column( $labels, 'name' ), true ) ) {
		gh( [ 'label', 'create', $label, '-R', $repo, '--color', '0E8A16', '--description', 'WordPress本体アップデートの互換性チェック' ], $dry_run );
	}
}

/**
 * gh を実行して標準出力を返す。dry-run では表示のみ.
 */
function gh( array $args, bool $dry_run = false ): string {
	$command = 'gh ' . implode( ' ', array_map( 'escapeshellarg', array_map( 'strval', $args ) ) );
	if ( $dry_run ) {
		echo '[dry-run] ', preg_replace( "/--comment '[^']*'/", "--comment '…'", $command ), "\n";
		return '';
	}
	exec( $command . ' 2>&1', $output, $code );
	if ( 0 !== $code ) {
		fwrite( STDERR, implode( "\n", $output ) . "\n" );
		exit( 1 );
	}
	return implode( "\n", $output );
}

function gh_json( array $args ): array {
	return json_decode( gh( $args ), true, 512, JSON_THROW_ON_ERROR ) ?? [];
}

function temp_file( string $content ): string {
	$file = tempnam( sys_get_temp_dir(), 'wp-compat' );
	file_put_contents( $file, $content );
	return $file;
}
