<?php
/**
 * 照合結果から、Issue に対して何をするかを決める（副作用なし）.
 *
 * ルール:
 *   1. 同じリリースの open な Issue があれば本文を更新する（チェック済みの項目は引き継ぐ）
 *   2. 同じリリースの Issue が閉じていれば、前回に無かった変更が該当したときだけ再オープンする
 *      （同じ内容なら何もしない。閉じた = 確認済みとみなす）
 *   3. 同じリリースの Issue が無ければ、重大度 中以上の該当があるときだけ作成する
 *   4. それより古いリリースの open な Issue は、今回の Issue に引き継いでクローズする
 */

/**
 * Issue 本文からリリースと変更IDを読む.
 *
 * ID マーカーが無い Issue（マーカー導入前に作られたもの）は has_ids = false.
 *
 * @return array{release: ?string, ids: list<string>, has_ids: bool}
 */
function issue_parse( string $body ): array {
	$release = preg_match( '/<!-- wp-compat:([0-9]+\.[0-9]+) -->/', $body, $m ) ? $m[1] : null;
	$has_ids = (bool) preg_match( '/<!-- wp-compat-ids: ([^>]*?) ?-->/', $body, $m );
	$ids     = $has_ids ? array_values( array_filter( explode( ' ', $m[1] ) ) ) : [];
	return [ 'release' => $release, 'ids' => $ids, 'has_ids' => $has_ids ];
}

/**
 * @param list<array{number: int, state: string, body: string}> $issues ラベルで絞った既存 Issue（open/closed 両方）.
 * @param string $release   今回のリリース（例 7.2）.
 * @param string $body      今回の Issue 本文.
 * @param list<string> $ids 今回の変更ID（report_significant_ids()）.
 * @param bool   $significant 重大度 中以上の該当があるか.
 * @return array{
 *   action: 'update'|'reopen'|'create'|'none',
 *   number: ?int,
 *   body: ?string,
 *   new_ids: list<string>,
 *   supersede: list<int>,
 *   reason: string
 * }
 */
function issue_plan( array $issues, string $release, string $body, array $ids, bool $significant ): array {
	$same  = [];
	$older = [];
	foreach ( $issues as $issue ) {
		$parsed = issue_parse( $issue['body'] ?? '' );
		if ( ! $parsed['release'] ) {
			continue;
		}
		$issue += $parsed;
		if ( $parsed['release'] === $release ) {
			$same[] = $issue;
		} elseif ( version_compare( $parsed['release'], $release, '<' ) && 'OPEN' === strtoupper( $issue['state'] ) ) {
			$older[] = $issue['number'];
		}
	}
	// 新しい Issue から見る.
	usort( $same, fn( $a, $b ) => $b['number'] <=> $a['number'] );
	$open   = array_values( array_filter( $same, fn( $i ) => 'OPEN' === strtoupper( $i['state'] ) ) );
	$closed = array_values( array_filter( $same, fn( $i ) => 'OPEN' !== strtoupper( $i['state'] ) ) );

	$plan = [
		'action'    => 'none',
		'number'    => null,
		'body'      => null,
		'new_ids'   => [],
		'supersede' => [],
		'reason'    => '',
	];

	if ( $open ) {
		$plan['action'] = 'update';
		$plan['number'] = $open[0]['number'];
		$plan['body']   = report_merge_checks( $open[0]['body'], $body );
		$plan['reason'] = "open な #{$open[0]['number']} を更新";
	} elseif ( $closed ) {
		// 閉じた Issue 以降に確認済みのものも含め、過去の同リリース Issue で見たIDはすべて除く.
		$seen = array_merge( ...array_map( fn( $i ) => $i['ids'], $closed ) );
		$new  = array_values( array_diff( $ids, $seen ) );
		// マーカー導入前の Issue は、何を確認済みか分からないので再オープンしない.
		if ( $new && $closed[0]['has_ids'] ) {
			$plan['action']  = 'reopen';
			$plan['number']  = $closed[0]['number'];
			$plan['body']    = report_merge_checks( $closed[0]['body'], $body );
			$plan['new_ids'] = $new;
			$plan['reason']  = sprintf( '閉じた #%d 以降に新しい該当 %d 件', $closed[0]['number'], count( $new ) );
		} else {
			$plan['number'] = $closed[0]['number'];
			$plan['reason'] = "閉じた #{$closed[0]['number']} と同じ内容のため何もしない";
		}
	} elseif ( $significant ) {
		$plan['action'] = 'create';
		$plan['body']   = $body;
		$plan['reason'] = '新規作成';
	} else {
		$plan['reason'] = '重大度 中以上の該当なし';
	}

	// 今回のリリースの Issue が存在する（作る）なら、古いリリースの open な Issue は役目を終える.
	if ( 'none' !== $plan['action'] || $plan['number'] ) {
		$plan['supersede'] = $older;
	}
	return $plan;
}
