<?php
/**
 * 公開中と新しいカタログの差分を Slack に通知する。差分が無ければ何もしない.
 *
 * Usage:
 *   SLACK_WEBHOOK_URL=... php notify-slack.php --old=old-catalog.json --new=_site/catalog.json [--dry-run]
 *
 * --old のファイルが無い・空なら初回公開として全件を差分とみなす.
 */

require __DIR__ . '/lib/catalog-diff.php';

const WP_COMPAT_PAGES = 'https://tarosky.github.io/workflows/';

$opts = getopt( '', [ 'old:', 'new:', 'dry-run' ] );
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

$webhook = getenv( 'SLACK_WEBHOOK_URL' );
if ( isset( $opts['dry-run'] ) || ! $webhook ) {
	echo $webhook ? "[dry-run] not posted.\n" : "SLACK_WEBHOOK_URL is not set; not posted.\n";
	exit( 0 );
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
