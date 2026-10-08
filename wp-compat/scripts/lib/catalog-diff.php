<?php
/**
 * 公開中のカタログ（catalog.json）と新しいカタログの差分.
 */

/**
 * @param array $old catalog.json（公開中。初回は空配列）.
 * @param array $new catalog.json（今回のビルド）.
 * @return list<array{
 *   release: string,
 *   status_from: ?string,
 *   status_to: string,
 *   added: list<array>,
 *   status_changed: list<array{change: array, from: string}>,
 *   removed: list<array>
 * }> 変化のあったリリースだけ（新しい順）.
 */
function catalog_diff( array $old, array $new ): array {
	$old_releases = array_column( $old['releases'] ?? [], null, 'release' );
	$diff         = [];
	foreach ( $new['releases'] as $release ) {
		$before      = $old_releases[ $release['release'] ] ?? [ 'status' => null, 'changes' => [] ];
		$old_changes = array_column( $before['changes'], null, 'id' );
		$new_changes = array_column( $release['changes'], null, 'id' );
		$entry       = [
			'release'        => $release['release'],
			'status_from'    => $before['status'],
			'status_to'      => $release['status'],
			'added'          => array_values( array_diff_key( $new_changes, $old_changes ) ),
			'status_changed' => [],
			'removed'        => array_values( array_diff_key( $old_changes, $new_changes ) ),
		];
		foreach ( array_intersect_key( $new_changes, $old_changes ) as $id => $change ) {
			if ( $change['status'] !== $old_changes[ $id ]['status'] ) {
				$entry['status_changed'][] = [ 'change' => $change, 'from' => $old_changes[ $id ]['status'] ];
			}
		}
		if ( $entry['added'] || $entry['status_changed'] || $entry['removed'] || $entry['status_from'] !== $entry['status_to'] ) {
			$diff[] = $entry;
		}
	}
	return $diff;
}

/**
 * Slack（Incoming Webhook）用のメッセージ.
 */
function catalog_diff_slack( array $diff, string $pages_url ): array {
	$severity = [ 'high' => '🔴', 'medium' => '🟡', 'low' => '⚪' ];
	$status   = [ 'planned' => '予告', 'shipped' => '出荷', 'dropped' => 'ドロップ', 'reverted' => '差し戻し' ];
	$link     = fn( string $release, array $change ) => sprintf( '<%s#%s|%s>', $pages_url, rawurlencode( $release . '--' . $change['id'] ), slack_escape( preg_replace( '/\s+/', ' ', $change['title'] ) ) );
	$lines    = [];
	foreach ( $diff as $entry ) {
		$counts = array_count_values( array_column( $entry['added'], 'severity' ) );
		$head   = sprintf( '*WordPress %s*', $entry['release'] );
		if ( null === $entry['status_from'] ) {
			$head .= "（新規・{$entry['status_to']}）";
		} elseif ( $entry['status_from'] !== $entry['status_to'] ) {
			$head .= "（{$entry['status_from']} → {$entry['status_to']}）";
		}
		$parts = [];
		if ( $entry['added'] ) {
			$parts[] = sprintf( '追加 %d 件（高 %d・中 %d・低 %d）', count( $entry['added'] ), $counts['high'] ?? 0, $counts['medium'] ?? 0, $counts['low'] ?? 0 );
		}
		if ( $entry['status_changed'] ) {
			$parts[] = sprintf( '状態変更 %d 件', count( $entry['status_changed'] ) );
		}
		if ( $entry['removed'] ) {
			$parts[] = sprintf( '削除 %d 件', count( $entry['removed'] ) );
		}
		$lines[] = $head . ( $parts ? ' ' . implode( '／', $parts ) : '' );

		$important = array_filter( $entry['added'], fn( $c ) => 'low' !== $c['severity'] );
		foreach ( array_slice( $important, 0, 10 ) as $change ) {
			$lines[] = sprintf( '• %s %s', $severity[ $change['severity'] ], $link( $entry['release'], $change ) );
		}
		if ( count( $important ) > 10 ) {
			$lines[] = sprintf( '• ほか %d 件', count( $important ) - 10 );
		}
		foreach ( array_slice( $entry['status_changed'], 0, 10 ) as $item ) {
			$lines[] = sprintf( '• %s → %s: %s', $status[ $item['from'] ], $status[ $item['change']['status'] ], $link( $entry['release'], $item['change'] ) );
		}
		$lines[] = '';
	}
	$text = "📚 WP互換性カタログを更新しました\n\n" . trim( implode( "\n", $lines ) )
		. "\n\n<{$pages_url}|カタログを見る>　各リポジトリの互換性 Issue は次回の定期実行（毎週月曜）で更新されます。";
	return [
		'text'   => '📚 WP互換性カタログを更新しました',
		'blocks' => [
			[
				'type' => 'section',
				'text' => [ 'type' => 'mrkdwn', 'text' => mb_strimwidth( $text, 0, 2900, '…' ) ],
			],
		],
	];
}

function slack_escape( string $text ): string {
	return str_replace( [ '&', '<', '>' ], [ '&amp;', '&lt;', '&gt;' ], $text );
}
