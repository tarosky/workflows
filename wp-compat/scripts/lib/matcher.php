<?php
/**
 * カタログの変更とコード索引を照合する.
 */

/**
 * 範囲内（from < release <= to）の照合対象の変更を返す.
 *
 * @param array  $catalog catalog.json 形式（releases[]）.
 * @param string $from    現在のバージョン（例 6.6）.
 * @param string $to      更新先（例 7.1）.
 */
function matcher_changes_in_range( array $catalog, string $from, string $to ): array {
	$changes = [];
	foreach ( $catalog['releases'] as $release ) {
		$version = $release['release'];
		if ( version_compare( $version, major( $from ), '<=' ) || version_compare( $version, major( $to ), '>' ) ) {
			continue;
		}
		foreach ( $release['changes'] as $change ) {
			if ( ! empty( $change['fixed_in'] ) && is_fixed( $change['fixed_in'], $to ) ) {
				continue;
			}
			if ( in_array( $change['status'], [ 'shipped', 'planned' ], true ) ) {
				$changes[] = $change + [ 'release' => $version, 'release_status' => $release['status'] ];
			}
		}
	}
	return $changes;
}

/**
 * 更新先でリグレッションが修正済みか。"7.1" のようにマイナーを省略した場合は最新マイナーとみなす.
 */
function is_fixed( string $fixed_in, string $to ): bool {
	if ( major( $to ) === $to ) {
		return version_compare( major( $fixed_in ), $to, '<=' );
	}
	return version_compare( $to, $fixed_in, '>=' );
}

function major( string $version ): string {
	return implode( '.', array_slice( explode( '.', $version ), 0, 2 ) );
}

/**
 * 1つの変更を照合する.
 *
 * @return array{strength: 'strong'|'weak'|'none', hits: list<array{symbol: string, loc: string}>, notes: list<string>}
 */
function matcher_match( array $change, array $index ): array {
	$hits  = [];
	$weak  = [];
	$notes = [];
	foreach ( $change['symbols'] ?? [] as $symbol ) {
		$locs = match_symbol( $symbol, $index );
		if ( ! $locs ) {
			continue;
		}
		$entry = array_map( fn( $loc ) => [ 'symbol' => $symbol['name'], 'loc' => $loc ], $locs );
		if ( 'js_package' === $symbol['kind'] ) {
			$weak = array_merge( $weak, $entry );
		} else {
			$hits = array_merge( $hits, $entry );
		}
		// 削除された関数をプラグイン自身が定義していれば Fatal にはならない.
		if ( 'function' === $symbol['kind'] && 'removed' === $change['type'] && isset( $index['defined_functions'][ strtolower( $symbol['name'] ) ] ) ) {
			$notes[] = sprintf( '`%s` は自前で定義されています（%s）', $symbol['name'], $index['defined_functions'][ strtolower( $symbol['name'] ) ] );
		}
	}
	if ( ! empty( $change['match_hint'] ) ) {
		foreach ( code_index_grep( $index, '/' . str_replace( '/', '\/', $change['match_hint'] ) . '/' ) as $loc ) {
			$hits[] = [ 'symbol' => '/' . $change['match_hint'] . '/', 'loc' => $loc ];
		}
	}
	$hits = unique_hits( $hits );
	if ( $hits ) {
		return [ 'strength' => 'strong', 'hits' => $hits, 'notes' => $notes ];
	}
	return [ 'strength' => $weak ? 'weak' : 'none', 'hits' => unique_hits( $weak ), 'notes' => $notes ];
}

/**
 * シンボルの種類ごとの照合.
 */
function match_symbol( array $symbol, array $index ): array {
	$name  = $symbol['name'];
	$lower = strtolower( $name );
	switch ( $symbol['kind'] ) {
		case 'function':
			// 呼び出し、または文字列コールバック.
			return array_merge( $index['calls'][ $lower ] ?? [], $index['strings'][ $name ] ?? [] );

		case 'class':
			return array_merge( $index['identifiers'][ $lower ] ?? [], $index['strings'][ $name ] ?? [] );

		case 'method':
			// クラスを参照しているファイル内のメソッド呼び出しだけを数える.
			[ $class, $method ] = array_pad( explode( '::', $name ), 2, '' );
			$class_files        = array_flip( array_map( 'loc_path', $index['identifiers'][ strtolower( $class ) ] ?? [] ) );
			if ( ! $class_files ) {
				return [];
			}
			$calls = '__construct' === strtolower( $method )
				? code_index_grep( $index, '/parent::__construct\s*\(/', 100 )
				: ( $index['calls'][ '->' . strtolower( $method ) ] ?? [] );
			return array_values( array_filter( $calls, fn( $loc ) => isset( $class_files[ loc_path( $loc ) ] ) ) );

		case 'hook':
			if ( str_contains( $name, '{' ) ) {
				// 動的フック名はワイルドカードとして扱う.
				$pattern = '/^' . preg_replace( '/\\\\\{.*?\\\\\}/', '.+', preg_quote( $name, '/' ) ) . '$/';
				$locs    = [];
				foreach ( $index['strings'] as $string => $string_locs ) {
					if ( preg_match( $pattern, $string ) ) {
						$locs = array_merge( $locs, $string_locs );
					}
				}
				return $locs;
			}
			return $index['strings'][ $name ] ?? [];

		case 'option':
		case 'js_handle':
		case 'css_handle':
			return array_merge( $index['strings'][ $name ] ?? [], code_index_grep( $index, '/[\'"]' . preg_quote( $name, '/' ) . '[\'"]/' ) );

		case 'constant':
			return array_merge( $index['identifiers'][ $lower ] ?? [], $index['strings'][ $name ] ?? [] );

		case 'block':
			$short = preg_replace( '#^core/#', '', $name );
			return code_index_grep( $index, '#[\'"]' . preg_quote( $name, '#' ) . '[\'"]|<!--\s*wp:' . preg_quote( $short, '#' ) . '[\s/]#' );

		case 'css_selector':
			return code_index_grep( $index, '/' . preg_quote( $name, '/' ) . '(?![\w-])/' );

		case 'js_package':
			$global = 'wp\.' . lcfirst( str_replace( ' ', '', ucwords( str_replace( '-', ' ', preg_replace( '#^@wordpress/#', '', $name ) ) ) ) );
			return code_index_grep( $index, '#[\'"]' . preg_quote( $name, '#' ) . '[\'"/]|\b' . $global . '\b#' );

		case 'file':
			return code_index_grep( $index, '#' . preg_quote( $name, '#' ) . '#' );

		default:
			return []; // php などは照合しない.
	}
}

function loc_path( string $loc ): string {
	return substr( $loc, 0, strrpos( $loc, ':' ) );
}

function unique_hits( array $hits ): array {
	$seen = [];
	return array_values(
		array_filter(
			$hits,
			function ( $hit ) use ( &$seen ) {
				if ( isset( $seen[ $hit['loc'] ] ) ) {
					return false;
				}
				$seen[ $hit['loc'] ] = true;
				return true;
			}
		)
	);
}

/**
 * 範囲内の全変更を照合し、結果を分類して返す.
 */
function matcher_run( array $catalog, array $index, string $from, string $to ): array {
	$result = [
		'from'      => $from,
		'to'        => $to,
		'files'     => $index['files'],
		'matched'   => [],
		'weak'      => [],
		'checklist' => [],
	];
	foreach ( matcher_changes_in_range( $catalog, $from, $to ) as $change ) {
		$match  = matcher_match( $change, $index );
		$item   = $change + $match;
		$manual = 'manual' === $change['confidence'];
		if ( 'strong' === $match['strength'] && ! $manual ) {
			$result['matched'][] = $item;
		} elseif ( 'high' === $change['severity'] && $manual ) {
			// シンボルで判定できない重大な変更は、当たった箇所を候補として目視チェックリストに残す.
			$result['checklist'][] = $item;
		} elseif ( 'none' !== $match['strength'] && 'low' !== $change['severity'] ) {
			// 要目視の項目のヒント一致と、関連パッケージの使用は参考扱い.
			$result['weak'][] = $item;
		}
	}
	$order = [ 'high' => 0, 'medium' => 1, 'low' => 2 ];
	foreach ( [ 'matched', 'weak', 'checklist' ] as $key ) {
		usort( $result[ $key ], fn( $a, $b ) => [ $order[ $a['severity'] ], $a['release'] ] <=> [ $order[ $b['severity'] ], $b['release'] ] );
	}
	return $result;
}
