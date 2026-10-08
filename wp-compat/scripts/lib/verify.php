<?php
/**
 * curated のシンボル実在チェック（verify-symbols.php の本体）.
 */

/**
 * @param array $catalog curated カタログ.
 * @param array $tables  scan_wordpress_src() の結果（比較元・比較先）.
 * @return list<string> "変更ID kind:name".
 */
function verify_symbols( array $catalog, array $tables ): array {
	$missing = [];
	foreach ( $catalog['changes'] as $change ) {
		if ( ! in_array( $change['status'], [ 'shipped', 'reverted' ], true ) ) {
			continue;
		}
		foreach ( $change['symbols'] ?? [] as $symbol ) {
			$exists = symbol_exists( $symbol, $tables );
			if ( false === $exists ) {
				$missing[] = "{$change['id']} {$symbol['kind']}:{$symbol['name']}";
			}
		}
	}
	return $missing;
}

/**
 * true = 実在, false = 不在, null = 検証対象外.
 */
function symbol_exists( array $symbol, array $tables ): ?bool {
	// スキャナはクラスを名前空間なしの名前で持つ.
	$name = preg_replace( '/^.*\\\\/', '', $symbol['name'] );
	$in   = function ( string $group, string $key ) use ( $tables ): bool {
		foreach ( $tables as $table ) {
			foreach ( array_keys( $table[ $group ] ) as $candidate ) {
				if ( 0 === strcasecmp( $candidate, $key ) ) {
					return true;
				}
			}
		}
		return false;
	};
	switch ( $symbol['kind'] ) {
		case 'function':
			return $in( 'functions', $name );
		case 'class':
			return $in( 'classes', $name );
		case 'method':
			return $in( 'methods', $name );
		case 'hook':
			if ( preg_match( '/[{$.\/]/', $name ) ) {
				return null;
			}
			if ( $in( 'hooks', $name ) || $in( 'deprecated_hooks', $name ) ) {
				return true;
			}
			foreach ( $tables as $table ) {
				foreach ( array_keys( $table['dynamic_hooks'] ) as $pattern ) {
					if ( preg_match( $pattern, $name ) ) {
						return true;
					}
				}
			}
			return false;
		default:
			return null;
	}
}
