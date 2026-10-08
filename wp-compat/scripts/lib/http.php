<?php
/**
 * 依存なしのHTTPヘルパー.
 */

/**
 * JSONを取得する。WP REST API の X-WP-TotalPages を $total_pages に入れる.
 */
function http_get_json( string $url, ?int &$total_pages = null ): array {
	$context = stream_context_create(
		[
			'http' => [
				'header'        => "User-Agent: tarosky-wp-compat\r\nAccept: application/json\r\n",
				'timeout'       => 60,
				'ignore_errors' => true,
			],
		]
	);
	for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
		$body = @file_get_contents( $url, false, $context );
		if ( false !== $body && str_contains( $http_response_header[0] ?? '', ' 200' ) ) {
			break;
		}
		sleep( 2 * $attempt );
	}
	if ( false === $body || ! str_contains( $http_response_header[0] ?? '', ' 200' ) ) {
		throw new RuntimeException( "Request failed: {$url} " . ( $http_response_header[0] ?? '' ) );
	}
	$total_pages = 1;
	foreach ( $http_response_header as $header ) {
		if ( preg_match( '/^X-WP-TotalPages:\s*(\d+)/i', $header, $m ) ) {
			$total_pages = (int) $m[1];
		}
	}
	return json_decode( $body, true, 512, JSON_THROW_ON_ERROR );
}
