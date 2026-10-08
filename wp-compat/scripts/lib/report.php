<?php
/**
 * 照合結果を Markdown（Issue本文）にする.
 */

const WP_COMPAT_PAGES_URL = 'https://tarosky.github.io/workflows/';

const WP_COMPAT_SEVERITY_LABEL = [ 'high' => '🔴 高', 'medium' => '🟡 中', 'low' => '⚪ 低' ];

const WP_COMPAT_CONFIDENCE_LABEL = [ 'high' => '機械照合', 'medium' => '要確認', 'manual' => '要目視' ];

/**
 * Issue 本文を作る.
 *
 * @param array  $result matcher_run() の結果.
 * @param string $repo   "owner/name"（GitHub のファイルリンク用）. 空ならリンクしない.
 * @param string $ref    ファイルリンクの ref.
 */
function report_markdown( array $result, string $repo = '', string $ref = 'HEAD' ): string {
	$link = fn( string $loc ) => report_loc_link( $loc, $repo, $ref );
	$out  = [];

	$out[] = report_marker( $result['to'] );
	$out[] = report_ids_marker( report_significant_ids( $result ) );
	$sources = [
		'tested_up_to'     => 'readme の Tested up to',
		'previous_release' => '直前のリリース',
	];
	$out[]   = sprintf(
		'WordPress **%s → %s** のアップデートで影響しうる箇所を、[互換性カタログ](%s?from=%s&to=%s)と照合しました（%d ファイル%s）。',
		$result['from'],
		$result['to'],
		WP_COMPAT_PAGES_URL,
		report_next_release( $result['from'] ),
		major( $result['to'] ),
		$result['files'],
		isset( $sources[ $result['from_source'] ?? '' ] ) ? '、起点は' . $sources[ $result['from_source'] ] : ''
	);
	$out[] = '';
	$major = array_values( array_filter( $result['matched'], fn( $item ) => 'low' !== $item['severity'] ) );
	$minor = array_values( array_filter( $result['matched'], fn( $item ) => 'low' === $item['severity'] ) );

	$out[] = '| 該当（高・中） | 該当（低） | 目視チェック | 参考 |';
	$out[] = '|---|---|---|---|';
	$out[] = sprintf( '| %d | %d | %d | %d |', count( $major ), count( $minor ), count( $result['checklist'] ), count( $result['weak'] ) );

	if ( $major ) {
		$out[] = '';
		$out[] = '## 該当箇所';
		foreach ( $major as $item ) {
			array_push( $out, ...report_item( $item, $link ) );
		}
	}

	if ( $result['checklist'] ) {
		$out[] = '';
		$out[] = '## 目視チェック（シンボルで判定できない重大な変更）';
		foreach ( $result['checklist'] as $item ) {
			array_push( $out, ...report_item( $item, $link, 5 ) );
		}
	}

	if ( $minor ) {
		$out[] = '';
		$out[] = '<details><summary>該当箇所（重大度 低）</summary>';
		$out[] = '';
		foreach ( $minor as $item ) {
			$out[] = sprintf( '- %s %s — %d 箇所（例: %s）— [カタログ](%s)', $item['release'], report_inline( $item['title'] ), count( $item['hits'] ), $link( $item['hits'][0]['loc'] ), report_anchor( $item ) );
		}
		$out[] = '';
		$out[] = '</details>';
	}

	if ( $result['weak'] ) {
		$out[] = '';
		$out[] = '<details><summary>参考（関連パッケージの使用・要目視項目のヒント一致）</summary>';
		$out[] = '';
		foreach ( $result['weak'] as $item ) {
			$symbols = implode( ', ', array_map( fn( $s ) => "`{$s}`", array_slice( array_unique( array_column( $item['hits'], 'symbol' ) ), 0, 3 ) ) );
			$out[]   = sprintf( '- %s %s %s（%s）— [カタログ](%s)', WP_COMPAT_SEVERITY_LABEL[ $item['severity'] ], $item['release'], report_inline( $item['title'] ), $symbols, report_anchor( $item ) );
		}
		$out[] = '';
		$out[] = '</details>';
	}

	$out[] = '';
	$out[] = '---';
	$out[] = 'このIssueは [tarosky/workflows の wp-compat](https://github.com/tarosky/workflows/tree/main/wp-compat) が自動生成しました。動的なフック名・ビルド済みJS・CSSの見た目の変化は検出できません。';
	return implode( "\n", $out ) . "\n";
}

/**
 * 1項目分の見出し・要約・該当箇所.
 */
function report_item( array $item, callable $link, int $limit = 10 ): array {
	$out   = [ '' ];
	$out[] = sprintf( '### %s %s', WP_COMPAT_SEVERITY_LABEL[ $item['severity'] ], report_inline( $item['title'] ) );
	$out[] = sprintf(
		'%s ／ %s%s ／ [カタログ](%s)',
		$item['release'] . ( 'final' === $item['release_status'] ? '' : "（{$item['release_status']}）" ),
		WP_COMPAT_CONFIDENCE_LABEL[ $item['confidence'] ],
		'planned' === $item['status'] ? ' ／ **予告のみ（未出荷）**' : '',
		report_anchor( $item )
	);
	if ( ! empty( $item['summary'] ) ) {
		$out[] = '';
		$out[] = '> ' . str_replace( "\n", "\n> ", trim( $item['summary'] ) );
	}
	if ( $item['hits'] ) {
		$out[] = '';
		$out[] = 'manual' === $item['confidence'] ? '確認候補:' : '該当箇所:';
		foreach ( array_slice( $item['hits'], 0, $limit ) as $hit ) {
			$out[] = sprintf( '- [ ] %s `%s`', $link( $hit['loc'] ), $hit['symbol'] );
		}
		if ( count( $item['hits'] ) > $limit ) {
			$out[] = sprintf( '- ほか %d 箇所', count( $item['hits'] ) - $limit );
		}
	}
	foreach ( $item['notes'] as $note ) {
		$out[] = "- ℹ️ {$note}";
	}
	return $out;
}

/**
 * Issue を特定するためのマーカー。1リポジトリ×1リリースにつき1件.
 */
function report_marker( string $to ): string {
	return sprintf( '<!-- wp-compat:%s -->', major( $to ) );
}

/**
 * Issue の再オープン判定に使う変更ID（"ID@リリース"）。コードに該当した重大度 中以上の項目だけ.
 * 目視チェックはコードと無関係に全リポジトリに出るので含めない.
 * HTMLコメントに入れるので "--" を含めない.
 */
function report_significant_ids( array $result ): array {
	$items = array_filter( $result['matched'], fn( $item ) => 'low' !== $item['severity'] );
	$ids   = array_map( fn( $item ) => $item['id'] . '@' . $item['release'], $items );
	sort( $ids );
	return array_values( array_unique( $ids ) );
}

function report_ids_marker( array $ids ): string {
	return '<!-- wp-compat-ids: ' . implode( ' ', $ids ) . ' -->';
}

/**
 * 既存 Issue でチェック済みの項目を、新しい本文に引き継ぐ.
 */
function report_merge_checks( string $old_body, string $new_body ): string {
	// リンク先の ref（コミットSHA）は実行ごとに変わるので、表示テキストで突き合わせる.
	$key = fn( string $line ) => trim( preg_replace( '/\]\([^)]*\)/', ']', $line ) );
	preg_match_all( '/^- \[x\] (.+)$/mi', $old_body, $matches );
	$checked = array_flip( array_map( $key, $matches[1] ) );
	return preg_replace_callback(
		'/^- \[ \] (.+)$/m',
		fn( $m ) => isset( $checked[ $key( $m[1] ) ] ) ? "- [x] {$m[1]}" : $m[0],
		$new_body
	);
}

function report_anchor( array $item ): string {
	return WP_COMPAT_PAGES_URL . '#' . rawurlencode( $item['release'] . '--' . $item['id'] );
}

function report_loc_link( string $loc, string $repo, string $ref ): string {
	[ $path, $line ] = explode( ':', $loc ) + [ '', '' ];
	if ( ! $repo ) {
		return "`{$loc}`";
	}
	return sprintf( '[%s](https://github.com/%s/blob/%s/%s#L%s)', $loc, $repo, $ref, $path, $line );
}

function report_inline( string $text ): string {
	return preg_replace( '/\s+/', ' ', trim( $text ) );
}

/**
 * Pages のフィルタ用: from の次のリリース.
 */
function report_next_release( string $from ): string {
	[ $major, $minor ] = array_map( 'intval', explode( '.', major( $from ) ) + [ 0, 0 ] );
	return 9 === $minor ? ( $major + 1 ) . '.0' : "{$major}." . ( $minor + 1 );
}
