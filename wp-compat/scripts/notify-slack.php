<?php
/**
 * 公開中と新しいカタログの差分を Slack に通知する。差分が無ければ何もしない.
 *
 * Usage:
 *   SLACK_WEBHOOK_URL=... php notify-slack.php --old=old-catalog.json --new=_site/catalog.json [--dry-run]
 *   SLACK_WEBHOOK_URL=... php notify-slack.php --test   # 設定確認用の固定メッセージ
 *
 * --old のファイルが無い・空なら初回公開として全件を差分とみなす.
 */

require __DIR__ . '/lib/catalog-diff.php';

const WP_COMPAT_PAGES = 'https://tarosky.github.io/workflows/';

$opts = getopt( '', [ 'old:', 'new:', 'dry-run', 'test' ] );
if ( isset( $opts['test'] ) ) {
	// Webhook の設定確認用。固定のメッセージを送る.
	$source = getenv( 'GITHUB_REPOSITORY' ) ? sprintf( '%s の Actions（run %s）', getenv( 'GITHUB_REPOSITORY' ), getenv( 'GITHUB_RUN_ID' ) ) : 'ローカル';
	slack_post( [ 'text' => "🔧 WP互換性カタログ: Slack 通知の設定確認です。{$source} から送信しました。カタログが更新されると、このチャンネルに差分が届きます。" ], isset( $opts['dry-run'] ) );
	exit( 0 );
}
if ( empty( $opts['new'] ) ) {
	fwrite( STDERR, "--new is required.\n" );
	exit( 1 );
}
$old_json = ! empty( $opts['old'] ) && is_file( $opts['old'] ) ? file_get_contents( $opts['old'] ) : '';
$old      = json_decode( $old_json ?: '{}', true ) ?: [];
$new      = json_decode( file_get_contents( $opts['new'] ), true, 512, JSON_THROW_ON_ERROR );

$diff = catalog_diff( $old, $new );
if ( ! $diff ) {
	echo "No catalog changes; nothing to notify.\n";
	exit( 0 );
}
$payload = catalog_diff_slack( $diff, WP_COMPAT_PAGES );
echo $payload['blocks'][0]['text']['text'], "\n";

slack_post( $payload, isset( $opts['dry-run'] ) );

/**
 * Incoming Webhook に送る。dry-run・URL 未設定なら送らない.
 */
function slack_post( array $payload, bool $dry_run ): void {
	$webhook = getenv( 'SLACK_WEBHOOK_URL' );
	if ( $dry_run || ! $webhook ) {
		echo $webhook ? "[dry-run] not posted.\n" : "SLACK_WEBHOOK_URL is not set; not posted.\n";
		return;
	}
	$context  = stream_context_create(
		[
			'http' => [
				'method'        => 'POST',
				'header'        => "Content-Type: application/json\r\n",
				'content'       => json_encode( $payload, JSON_UNESCAPED_UNICODE ),
				'timeout'       => 30,
				'ignore_errors' => true,
			],
		]
	);
	$response = file_get_contents( $webhook, false, $context );
	$status   = $http_response_header[0] ?? '';
	if ( ! str_contains( $status, ' 200' ) ) {
		fwrite( STDERR, "Slack returned {$status}: {$response}\n" );
		exit( 1 );
	}
	echo "Posted to Slack.\n";
}
