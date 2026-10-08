<?php
/**
 * WordPress コアのPHPソースからシンボル表を作る.
 *
 * 収集するもの:
 *   - functions: グローバル関数（条件付き定義を含む）
 *   - classes:   class / interface / trait / enum
 *   - methods:   Class::method（可視性つき）
 *   - hooks:     リテラル名で発火しているフック
 *   - deprecated_hooks: apply_filters_deprecated / do_action_deprecated
 *   - deprecated_files: _deprecated_file
 *
 * 動的なフック名（"save_post_{$post_type}" など）は収集できない。
 */

const WP_COMPAT_HOOK_FUNCTIONS = [
	'do_action'              => 'action',
	'do_action_ref_array'    => 'action',
	'apply_filters'          => 'filter',
	'apply_filters_ref_array' => 'filter',
];

const WP_COMPAT_DEPRECATED_HOOK_FUNCTIONS = [
	'do_action_deprecated'    => 'action',
	'apply_filters_deprecated' => 'filter',
];

const WP_COMPAT_DEPRECATION_FUNCTIONS = [ '_deprecated_function', '_deprecated_constructor', '_deprecated_class', '_deprecated_argument' ];

/**
 * バンドルされたサードパーティライブラリ（パス前方一致）.
 */
const WP_COMPAT_LIBRARY_PATHS = [
	'wp-includes/ID3/',
	'wp-includes/SimplePie/',
	'wp-includes/Requests/',
	'wp-includes/PHPMailer/',
	'wp-includes/sodium_compat/',
	'wp-includes/Text/',
	'wp-includes/IXR/',
	'wp-includes/php-compat/',
	'wp-includes/class-phpass.php',
	'wp-includes/class-snoopy.php',
	'wp-includes/atomlib.php',
	'wp-includes/class-avif-info.php',
	'wp-admin/includes/class-pclzip.php',
];

/**
 * src ディレクトリ全体を走査する.
 */
function scan_wordpress_src( string $src ): array {
	$table = [
		'functions'        => [],
		'classes'          => [],
		'methods'          => [],
		'hooks'            => [],
		'deprecated_hooks' => [],
		'deprecated_files' => [],
		'dynamic_hooks'    => [],
	];
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		$path = substr( $file->getPathname(), strlen( rtrim( $src, '/' ) ) + 1 );
		if ( 'php' !== $file->getExtension() || str_starts_with( $path, 'wp-content/' ) || str_starts_with( $path, 'wp-includes/js/' ) ) {
			continue;
		}
		scan_php_file( file_get_contents( $file->getPathname() ), $path, $table );
	}
	foreach ( $table as &$entries ) {
		ksort( $entries );
	}
	return $table;
}

/**
 * パスがバンドルライブラリか.
 */
function is_library_path( string $path ): bool {
	foreach ( WP_COMPAT_LIBRARY_PATHS as $prefix ) {
		if ( str_starts_with( $path, $prefix ) ) {
			return true;
		}
	}
	return false;
}

/**
 * 1ファイルを走査して $table に追記する.
 */
function scan_php_file( string $code, string $path, array &$table ): void {
	$tokens  = array_values(
		array_filter(
			token_get_all( $code ),
			fn( $t ) => ! is_array( $t ) || ! in_array( $t[0], [ T_WHITESPACE, T_COMMENT ], true )
		)
	);
	$library = is_library_path( $path );
	$count   = count( $tokens );
	$depth   = 0;
	$stack   = []; // [ 'type' => class|function, 'name' => string, 'depth' => int ].
	$pending = null;
	$doc     = null;
	$mods    = [];

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];
		$id    = is_array( $token ) ? $token[0] : $token;
		$text  = is_array( $token ) ? $token[1] : $token;
		$line  = is_array( $token ) ? $token[2] : null;

		switch ( $id ) {
			case T_DOC_COMMENT:
				$doc = $text;
				break;

			case T_PUBLIC:
			case T_PROTECTED:
			case T_PRIVATE:
			case T_STATIC:
			case T_ABSTRACT:
			case T_FINAL:
				$mods[] = strtolower( $text );
				break;

			case T_CLASS:
			case T_INTERFACE:
			case T_TRAIT:
			case T_ENUM:
				$prev = $tokens[ $i - 1 ] ?? null;
				// `Foo::class` と `new class` は除外.
				if ( is_array( $prev ) && in_array( $prev[0], [ T_DOUBLE_COLON, T_NEW ], true ) ) {
					break;
				}
				$next = $tokens[ $i + 1 ] ?? null;
				if ( ! is_array( $next ) || T_STRING !== $next[0] ) {
					break;
				}
				$name    = $next[1];
				$extends = null;
				if ( is_array( $tokens[ $i + 2 ] ?? null ) && T_EXTENDS === $tokens[ $i + 2 ][0] && is_array( $tokens[ $i + 3 ] ?? null ) ) {
					$extends = ltrim( $tokens[ $i + 3 ][1], '\\' );
				}
				$table['classes'][ $name ] = [
					'file'       => $path,
					'line'       => $line,
					'kind'       => strtolower( $text ),
					'extends'    => $extends,
					'deprecated' => doc_deprecated_version( $doc ),
					'private'    => doc_is_private( $doc ),
					'library'    => $library,
				];
				$pending = [ 'type' => 'class', 'name' => $name ];
				$doc     = null;
				break;

			case T_FUNCTION:
				$j = $i + 1;
				if ( '&' === ( is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : $tokens[ $j ] ) ) {
					++$j;
				}
				$next = $tokens[ $j ] ?? null;
				// クロージャ・アロー関数は名前を持たないので無視.
				if ( ! is_array( $next ) || T_STRING !== $next[0] ) {
					$doc  = null;
					$mods = [];
					break;
				}
				$name  = $next[1];
				$class = current_scope( $stack, 'class', $depth );
				if ( $class ) {
					$key                       = $class . '::' . $name;
					$visibility                = array_values( array_intersect( $mods, [ 'public', 'protected', 'private' ] ) )[0] ?? 'public';
					$table['methods'][ $key ] = [
						'file'       => $path,
						'line'       => $line,
						'visibility' => $visibility,
						'static'     => in_array( 'static', $mods, true ),
						'deprecated' => doc_deprecated_version( $doc ),
						'private'    => 'private' === $visibility || doc_is_private( $doc ),
						'library'    => $library,
					];
				} elseif ( ! in_function( $stack ) ) {
					$key = $name;
					// 条件付き定義が複数ある場合は最初のものを採用.
					$table['functions'][ $key ] ??= [
						'file'       => $path,
						'line'       => $line,
						'deprecated' => doc_deprecated_version( $doc ),
						'private'    => str_starts_with( $name, '_' ) || doc_is_private( $doc ),
						'library'    => $library,
					];
				} else {
					$key = null; // 関数内関数.
				}
				$pending = [ 'type' => 'function', 'name' => $key, 'is_method' => (bool) $class ];
				$doc     = null;
				$mods    = [];
				break;

			case '{':
			case T_CURLY_OPEN:
			case T_DOLLAR_OPEN_CURLY_BRACES:
				++$depth;
				if ( $pending ) {
					$stack[] = $pending + [ 'depth' => $depth ];
					$pending = null;
				}
				$doc  = null;
				$mods = [];
				break;

			case '}':
				if ( $stack && end( $stack )['depth'] === $depth ) {
					array_pop( $stack );
				}
				--$depth;
				$doc  = null;
				$mods = [];
				break;

			case ';':
				// 抽象メソッド・インターフェースのメソッドは本体を持たない.
				if ( $pending && 'function' === $pending['type'] ) {
					$pending = null;
				}
				$doc  = null;
				$mods = [];
				break;

			case T_STRING:
				$after = $tokens[ $i + 1 ] ?? null;
				$prev  = $tokens[ $i - 1 ] ?? null;
				if ( '(' !== $after || ( is_array( $prev ) && in_array( $prev[0], [ T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW ], true ) ) ) {
					break;
				}
				$fn = strtolower( $text );
				if ( isset( WP_COMPAT_HOOK_FUNCTIONS[ $fn ] ) ) {
					$hook = literal_argument( $tokens, $i + 1, 0 );
					if ( null === $hook ) {
						$pattern = dynamic_hook_pattern( $tokens, $i + 2 );
						if ( $pattern ) {
							$table['dynamic_hooks'][ $pattern ] ??= [ 'file' => $path, 'line' => $line ];
						}
					} else {
						$table['hooks'][ $hook ] ??= [
							'file'    => $path,
							'line'    => $line,
							'type'    => WP_COMPAT_HOOK_FUNCTIONS[ $fn ],
							'library' => $library,
						];
					}
				} elseif ( isset( WP_COMPAT_DEPRECATED_HOOK_FUNCTIONS[ $fn ] ) ) {
					$hook = literal_argument( $tokens, $i + 1, 0 );
					if ( null !== $hook ) {
						$table['deprecated_hooks'][ $hook ] ??= [
							'file'        => $path,
							'line'        => $line,
							'type'        => WP_COMPAT_DEPRECATED_HOOK_FUNCTIONS[ $fn ],
							'version'     => literal_argument( $tokens, $i + 1, 2 ),
							'replacement' => literal_argument( $tokens, $i + 1, 3 ),
						];
					}
				} elseif ( '_deprecated_file' === $fn ) {
					$table['deprecated_files'][ $path ] = [
						'file'    => $path,
						'line'    => $line,
						'version' => literal_argument( $tokens, $i + 1, 1 ),
					];
				} elseif ( in_array( $fn, WP_COMPAT_DEPRECATION_FUNCTIONS, true ) ) {
					mark_deprecated( $table, $stack, $fn, literal_argument( $tokens, $i + 1, 1 ) );
				}
				break;
		}
	}
}

/**
 * 関数本体内の _deprecated_* 呼び出しを、それを含む関数・メソッド・クラスに反映する.
 */
function mark_deprecated( array &$table, array $stack, string $fn, ?string $version ): void {
	$scope = null;
	for ( $k = count( $stack ) - 1; $k >= 0; $k-- ) {
		if ( 'function' === $stack[ $k ]['type'] && $stack[ $k ]['name'] ) {
			$scope = $stack[ $k ];
			break;
		}
	}
	if ( ! $scope ) {
		return;
	}
	$version = $version ?? 'unknown';
	if ( '_deprecated_argument' === $fn ) {
		$group = $scope['is_method'] ? 'methods' : 'functions';
		$table[ $group ][ $scope['name'] ]['deprecated_argument'] ??= $version;
		return;
	}
	if ( '_deprecated_class' === $fn ) {
		$class = explode( '::', $scope['name'] )[0];
		if ( isset( $table['classes'][ $class ] ) ) {
			$table['classes'][ $class ]['deprecated'] ??= $version;
		}
		return;
	}
	$group = $scope['is_method'] ? 'methods' : 'functions';
	if ( isset( $table[ $group ][ $scope['name'] ] ) ) {
		$table[ $group ][ $scope['name'] ]['deprecated'] ??= $version;
	}
}

/**
 * 指定位置（`(` の位置）から n 番目の引数が単一の文字列リテラルならその値を返す.
 */
function literal_argument( array $tokens, int $open, int $n ): ?string {
	$depth = 0;
	$arg   = 0;
	$parts = [];
	for ( $i = $open; $i < count( $tokens ); $i++ ) {
		$t = $tokens[ $i ];
		$v = is_array( $t ) ? $t[1] : $t;
		if ( in_array( $v, [ '(', '[', '{' ], true ) || ( is_array( $t ) && in_array( $t[0], [ T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ], true ) ) ) {
			++$depth;
			if ( 1 === $depth ) {
				continue;
			}
		} elseif ( in_array( $v, [ ')', ']', '}' ], true ) ) {
			--$depth;
			if ( 0 === $depth ) {
				break;
			}
		} elseif ( ',' === $v && 1 === $depth ) {
			++$arg;
			continue;
		}
		if ( $arg === $n ) {
			$parts[] = $t;
		} elseif ( $arg > $n ) {
			break;
		}
	}
	if ( 1 !== count( $parts ) || ! is_array( $parts[0] ) || T_CONSTANT_ENCAPSED_STRING !== $parts[0][0] ) {
		return null;
	}
	return stripcslashes( substr( $parts[0][1], 1, -1 ) );
}

/**
 * "get_{$adjacent}_post_where" のような補間文字列のフック名を正規表現にする。補間でなければ null.
 */
function dynamic_hook_pattern( array $tokens, int $start ): ?string {
	if ( '"' !== ( $tokens[ $start ] ?? null ) ) {
		return null;
	}
	$regex = '';
	for ( $i = $start + 1; $i < count( $tokens ) && '"' !== $tokens[ $i ]; $i++ ) {
		$t = $tokens[ $i ];
		if ( is_array( $t ) && T_ENCAPSED_AND_WHITESPACE === $t[0] ) {
			$regex .= preg_quote( $t[1], '/' );
		} elseif ( ! str_ends_with( $regex, '.+' ) ) {
			// 変数・{$...} の部分はワイルドカード.
			$regex .= '.+';
		}
	}
	// 固定部分が短いパターン（"{$a}_{$b}" など）は何にでも一致するので使わない.
	$literal = preg_replace( '/\\\\(.)|\.\+/', '$1', $regex );
	return strlen( $literal ) < 6 ? null : '/^' . $regex . '$/';
}

/**
 * 直近の指定スコープ名を返す（クラス直下にいる場合のみ）.
 */
function current_scope( array $stack, string $type, int $depth ): ?string {
	$top = end( $stack );
	return ( $top && $top['type'] === $type && $top['depth'] === $depth ) ? $top['name'] : null;
}

function in_function( array $stack ): bool {
	foreach ( $stack as $scope ) {
		if ( 'function' === $scope['type'] ) {
			return true;
		}
	}
	return false;
}

function doc_deprecated_version( ?string $doc ): ?string {
	if ( $doc && preg_match( '/@deprecated\s+([0-9][0-9.]*)?/', $doc, $m ) ) {
		return ! empty( $m[1] ) ? $m[1] : 'unknown';
	}
	return null;
}

function doc_is_private( ?string $doc ): bool {
	return (bool) ( $doc && preg_match( '/@access\s+private/', $doc ) );
}
