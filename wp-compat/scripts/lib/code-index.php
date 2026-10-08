<?php
/**
 * 照合対象（プラグイン・テーマ）のコードを索引化する.
 *
 * PHP はトークン解析して「呼び出し」「識別子」「文字列リテラル」「定義」に分ける。
 * JS / CSS / JSON / HTML は行単位のテキストとして保持し、正規表現で照合する。
 */

const WP_COMPAT_EXCLUDE_DIRS = [ 'node_modules', 'vendor', 'wp', 'wordpress', 'tests', 'test', 'wp-compat' ];

const WP_COMPAT_TEXT_EXTENSIONS = [ 'js', 'jsx', 'mjs', 'ts', 'tsx', 'css', 'scss', 'sass', 'json', 'html', 'twig' ];

/**
 * ディレクトリを走査して索引を作る.
 *
 * @return array{
 *   calls: array<string, list<string>>,
 *   identifiers: array<string, list<string>>,
 *   strings: array<string, list<string>>,
 *   defined_functions: array<string, string>,
 *   lines: array<string, list<string>>,
 *   files: int
 * }
 */
function code_index_build( string $root, array $exclude_dirs = WP_COMPAT_EXCLUDE_DIRS ): array {
	$index = [
		'calls'             => [],
		'identifiers'       => [],
		'strings'           => [],
		'defined_functions' => [],
		'lines'             => [],
		'files'             => 0,
	];
	$root     = rtrim( realpath( $root ), '/' );
	$filter   = new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		// ドットで始まるディレクトリ（.git, .github, .claude など）は常に除外.
		fn( $file ) => ! ( $file->isDir() && ( str_starts_with( $file->getFilename(), '.' ) || in_array( $file->getFilename(), $exclude_dirs, true ) ) )
	);
	foreach ( new RecursiveIteratorIterator( $filter ) as $file ) {
		$ext = strtolower( $file->getExtension() );
		if ( 'php' !== $ext && ! in_array( $ext, WP_COMPAT_TEXT_EXTENSIONS, true ) ) {
			continue;
		}
		// ビルド成果物（*.min.js、build/、dist/）とロックファイルはソースではないので除く.
		$path = substr( $file->getPathname(), strlen( $root ) + 1 );
		if ( preg_match( '#(^|/)(build|dist)/|\.min\.(js|css)$|\.asset\.php$|(^|/)(package-lock|composer|npm-shrinkwrap)\.(json|lock)$|(^|/)composer\.json$#', $path ) ) {
			continue;
		}
		$code = file_get_contents( $file->getPathname() );
		if ( false === $code || strlen( $code ) > 2 * 1024 * 1024 ) {
			continue;
		}
		++$index['files'];
		$index['lines'][ $path ] = explode( "\n", $code );
		if ( 'php' === $ext ) {
			code_index_php( $code, $path, $index );
		}
	}
	return $index;
}

/**
 * PHPファイルをトークン解析して索引に追加する.
 */
function code_index_php( string $code, string $path, array &$index ): void {
	try {
		$tokens = token_get_all( $code, TOKEN_PARSE );
	} catch ( ParseError $e ) {
		$tokens = token_get_all( $code );
	}
	$tokens = array_values(
		array_filter( $tokens, fn( $t ) => ! is_array( $t ) || ! in_array( $t[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) )
	);
	$count  = count( $tokens );
	for ( $i = 0; $i < $count; $i++ ) {
		$t = $tokens[ $i ];
		if ( ! is_array( $t ) ) {
			continue;
		}
		$loc = "{$path}:{$t[2]}";
		switch ( $t[0] ) {
			case T_CONSTANT_ENCAPSED_STRING:
				$value                          = stripcslashes( substr( $t[1], 1, -1 ) );
				$index['strings'][ $value ][] = $loc;
				break;

			case T_STRING:
			case T_NAME_QUALIFIED:
			case T_NAME_FULLY_QUALIFIED:
				$name = ltrim( $t[1], '\\' );
				$prev = $tokens[ $i - 1 ] ?? null;
				$next = $tokens[ $i + 1 ] ?? null;
				if ( is_array( $prev ) && T_FUNCTION === $prev[0] ) {
					$index['defined_functions'][ strtolower( $name ) ] ??= $loc;
					break;
				}
				$lower = strtolower( $name );
				$index['identifiers'][ $lower ][] = $loc;
				$is_member = is_array( $prev ) && in_array( $prev[0], [ T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON ], true );
				if ( '(' === $next ) {
					// メソッド呼び出しは "->name" / "::name" として別キーで持つ.
					$key                       = $is_member ? '->' . $lower : $lower;
					$index['calls'][ $key ][] = $loc;
				}
				break;
		}
	}
}

/**
 * 全テキスト行に正規表現を当てる。最大 $limit 件の "path:line" を返す.
 */
function code_index_grep( array $index, string $pattern, int $limit = 20 ): array {
	$hits = [];
	foreach ( $index['lines'] as $path => $lines ) {
		foreach ( $lines as $n => $line ) {
			if ( @preg_match( $pattern, $line ) ) {
				$hits[] = $path . ':' . ( $n + 1 );
				if ( count( $hits ) >= $limit ) {
					return $hits;
				}
			}
		}
	}
	return $hits;
}

/**
 * "path:line" の行を取り出す.
 */
function code_index_line( array $index, string $loc ): string {
	[ $path, $line ] = explode( ':', $loc ) + [ '', 0 ];
	return trim( $index['lines'][ $path ][ (int) $line - 1 ] ?? '' );
}
