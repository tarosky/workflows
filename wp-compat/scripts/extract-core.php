<?php
/**
 * wordpress-develop の2つのツリーを比較し、機械抽出カタログ（extracted.yml）を生成する.
 *
 * Usage:
 *   php extract-core.php --release=7.1 --status=final \
 *     --from-dir=trees/7.0.0 --from-ref=7.0.0 \
 *     --to-dir=trees/7.1.0 --to-ref=7.1.0 [--to-commit=sha]
 *
 * Claude は使わない。ここで出す項目はすべて再現可能。
 */

require __DIR__ . '/lib/symbol-scanner.php';
require __DIR__ . '/lib/catalog.php';

$opts = getopt( '', [ 'release:', 'status:', 'from-dir:', 'from-ref:', 'to-dir:', 'to-ref:', 'to-commit:', 'out:' ] );
foreach ( [ 'release', 'status', 'from-dir', 'from-ref', 'to-dir', 'to-ref' ] as $key ) {
	if ( empty( $opts[ $key ] ) ) {
		fwrite( STDERR, "--{$key} is required.\n" );
		exit( 1 );
	}
}

$from = scan_wordpress_src( $opts['from-dir'] . '/src' );
$to   = scan_wordpress_src( $opts['to-dir'] . '/src' );
$refs = [ 'from' => $opts['from-ref'], 'to' => $opts['to-commit'] ?? $opts['to-ref'] ];

$changes = array_merge(
	diff_removed( $from, $to, $refs ),
	diff_deprecated( $from, $to, $refs ),
	diff_hooks( $from, $to, $refs ),
	diff_handles( $opts['from-dir'], $opts['to-dir'], $refs ),
	diff_requirements( $opts['from-dir'], $opts['to-dir'], $refs )
);

$catalog = [
	'release'      => $opts['release'],
	'status'       => $opts['status'],
	'origin'       => 'extracted',
	'compared'     => array_filter(
		[
			'from'      => $opts['from-ref'],
			'to'        => $opts['to-ref'],
			'to_commit' => $opts['to-commit'] ?? null,
		]
	),
	'generated_at' => gmdate( 'c' ),
	'sources'      => [
		[
			'type' => 'git',
			'url'  => sprintf( 'https://github.com/WordPress/wordpress-develop/compare/%s...%s', $refs['from'], $refs['to'] ),
		],
	],
	'changes'      => $changes,
];

$errors = catalog_validate( $catalog );
if ( $errors ) {
	fwrite( STDERR, implode( "\n", $errors ) . "\n" );
	exit( 1 );
}

$out = $opts['out'] ?? catalog_dir( $opts['release'] ) . '/extracted.yml';
catalog_write( $out, $catalog );

$by_type = array_count_values( array_column( $changes, 'type' ) );
ksort( $by_type );
printf( "%s: %d changes %s -> %s\n", $opts['release'], count( $changes ), json_encode( $by_type ), $out );

/**
 * 削除された関数・クラス・メソッド.
 */
function diff_removed( array $from, array $to, array $refs ): array {
	$changes = [];
	foreach ( [ 'functions' => 'function', 'classes' => 'class' ] as $group => $kind ) {
		foreach ( array_diff_key( $from[ $group ], $to[ $group ] ) as $name => $info ) {
			if ( is_php_native( $kind, $name ) ) {
				continue; // PHP本体に同名があるポリフィルの削除は影響しない.
			}
			$changes[] = change(
				"removed:{$kind}:{$name}",
				'removed',
				sprintf( '%s `%s` が削除された', 'function' === $kind ? '関数' : 'クラス', $name ),
				severity_for_removed( $info, 'high' ),
				$kind,
				$name,
				$info,
				$refs['from']
			);
		}
	}
	foreach ( array_diff_key( $from['methods'], $to['methods'] ) as $name => $info ) {
		[ $class, $method ] = explode( '::', $name );
		// クラスごと消えた場合はクラスの項目で足りる.
		if ( ! isset( $to['classes'][ $class ] ) || 'private' === $info['visibility'] || inherits_method( $to, $class, $method ) ) {
			continue;
		}
		$changes[] = change(
			"removed:method:{$name}",
			'removed',
			sprintf( '%s メソッド `%s` が削除された', $info['visibility'], $name ),
			severity_for_removed( $info, 'public' === $info['visibility'] ? 'high' : 'medium' ),
			'method',
			$name,
			$info,
			$refs['from']
		);
	}
	return $changes;
}

/**
 * 新たに非推奨になったもの.
 */
function diff_deprecated( array $from, array $to, array $refs ): array {
	$changes = [];
	foreach ( [ 'functions' => 'function', 'classes' => 'class', 'methods' => 'method' ] as $group => $kind ) {
		foreach ( $to[ $group ] as $name => $info ) {
			if ( ! isset( $from[ $group ][ $name ] ) ) {
				continue;
			}
			if ( $info['deprecated'] && ! $from[ $group ][ $name ]['deprecated'] ) {
				$changes[] = change(
					"deprecated:{$kind}:{$name}",
					'deprecated',
					sprintf( '`%s` が非推奨になった（%s）', $name, $info['deprecated'] ),
					$info['private'] || $info['library'] || is_block_library( $info ) ? 'low' : 'medium',
					$kind,
					$name,
					$info,
					$refs['to'],
					$info['deprecated']
				);
			}
			if ( ! empty( $info['deprecated_argument'] ) && empty( $from[ $group ][ $name ]['deprecated_argument'] ) ) {
				$changes[] = change(
					"argument_deprecated:{$kind}:{$name}",
					'argument_deprecated',
					sprintf( '`%s` の引数が非推奨になった（%s）', $name, $info['deprecated_argument'] ),
					'low',
					$kind,
					$name,
					$info,
					$refs['to'],
					$info['deprecated_argument'],
					'medium'
				);
			}
		}
	}
	foreach ( array_diff_key( $to['deprecated_files'], $from['deprecated_files'] ) as $path => $info ) {
		$changes[] = change( "file_deprecated:file:{$path}", 'file_deprecated', sprintf( 'ファイル `%s` の読み込みが非推奨になった', $path ), 'medium', 'file', $path, $info, $refs['to'], $info['version'] );
	}
	return $changes;
}

/**
 * フックの非推奨化と、発火しなくなったフック.
 */
function diff_hooks( array $from, array $to, array $refs ): array {
	$changes = [];
	foreach ( array_diff_key( $to['deprecated_hooks'], $from['deprecated_hooks'] ) as $hook => $info ) {
		$change = change( "hook_deprecated:hook:{$hook}", 'hook_deprecated', sprintf( '%s `%s` が非推奨になった', $info['type'], $hook ), 'medium', 'hook', $hook, $info, $refs['to'], $info['version'] );
		if ( $info['replacement'] ) {
			$change['symbols'][0]['replacement'] = $info['replacement'];
		}
		$changes[] = $change;
	}
	foreach ( array_diff_key( $from['hooks'], $to['hooks'], $to['deprecated_hooks'] ) as $hook => $info ) {
		if ( $info['library'] ) {
			continue;
		}
		// 動的なフック名に置き換わった可能性があるため確度は medium.
		$changes[] = change( "hook_removed:hook:{$hook}", 'hook_removed', sprintf( '%s `%s` が発火しなくなった', $info['type'], $hook ), 'medium', 'hook', $hook, $info, $refs['from'], null, 'medium' );
	}
	return $changes;
}

/**
 * script-loader.php で登録されなくなったスクリプト・スタイルのハンドル.
 *
 * 依存に指定していると、依存元のスクリプトごと読み込まれなくなる。
 * パッケージ由来のハンドル（ビルド成果物から登録されるもの）は対象外.
 */
function diff_handles( string $from_dir, string $to_dir, array $refs ): array {
	$file = 'wp-includes/script-loader.php';
	$read = function ( string $dir ) use ( $file ): array {
		$handles = [];
		foreach ( file( "{$dir}/src/{$file}" ) as $i => $line ) {
			if ( preg_match_all( '/\$(scripts|styles)->add\(\s*\'([^\']+)\'/', $line, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $m ) {
					$handles[ $m[1] ][ $m[2] ] ??= $i + 1;
				}
			}
		}
		return $handles;
	};
	$old     = $read( $from_dir );
	$new     = $read( $to_dir );
	$changes = [];
	foreach ( [ 'scripts' => 'js_handle', 'styles' => 'css_handle' ] as $group => $kind ) {
		foreach ( array_diff_key( $old[ $group ] ?? [], $new[ $group ] ?? [] ) as $handle => $line ) {
			$changes[] = change(
				"removed:{$kind}:{$handle}",
				'removed',
				sprintf( '%s ハンドル `%s` が登録されなくなった', 'js_handle' === $kind ? 'スクリプト' : 'スタイル', $handle ),
				'high',
				$kind,
				$handle,
				[ 'file' => $file, 'line' => $line ],
				$refs['from']
			);
		}
	}
	return $changes;
}

/**
 * PHP / MySQL の最低要件.
 */
function diff_requirements( string $from_dir, string $to_dir, array $refs ): array {
	$read = function ( string $dir ): array {
		$code = file_get_contents( $dir . '/src/wp-includes/version.php' );
		preg_match( '/\$required_php_version\s*=\s*\'([^\']+)\'/', $code, $php );
		preg_match( '/\$required_mysql_version\s*=\s*\'([^\']+)\'/', $code, $mysql );
		return [ 'php' => $php[1] ?? null, 'mysql' => $mysql[1] ?? null ];
	};
	$old     = $read( $from_dir );
	$new     = $read( $to_dir );
	$changes = [];
	foreach ( [ 'php' => 'PHP', 'mysql' => 'MySQL' ] as $key => $label ) {
		if ( $old[ $key ] !== $new[ $key ] ) {
			$changes[] = [
				'id'          => "php_requirement:{$key}:{$new[ $key ]}",
				'type'        => 'php_requirement',
				'title'       => sprintf( '%s の最低要件が %s から %s に変わった', $label, $old[ $key ], $new[ $key ] ),
				'status'      => 'shipped',
				'severity'    => 'high',
				'confidence'  => 'high',
				'symbols'     => [ [ 'kind' => 'php', 'name' => $new[ $key ] ] ],
				'evidence'    => [ 'file' => 'src/wp-includes/version.php', 'ref' => $refs['to'] ],
				'source_urls' => [ blob_url( $refs['to'], 'wp-includes/version.php', null ) ],
			];
		}
	}
	return $changes;
}

function change( string $id, string $type, string $title, string $severity, string $kind, string $name, array $info, string $ref, ?string $since = null, string $confidence = 'high' ): array {
	return array_filter(
		[
			'id'          => $id,
			'type'        => $type,
			'title'       => $title,
			'status'      => 'shipped',
			'severity'    => $severity,
			'confidence'  => $confidence,
			'since'       => $since,
			'symbols'     => [ [ 'kind' => $kind, 'name' => $name ] ],
			'evidence'    => [
				'file' => 'src/' . $info['file'],
				'line' => $info['line'],
				'ref'  => $ref,
			],
			'source_urls' => [ blob_url( $ref, $info['file'], $info['line'] ) ],
		],
		fn( $v ) => null !== $v
	);
}

function severity_for_removed( array $info, string $default ): string {
	return ( $info['private'] ?? false ) || ( $info['library'] ?? false ) || is_block_library( $info ) ? 'low' : $default;
}

/**
 * Gutenberg から同期されるブロックの内部ヘルパー（block_core_*）.
 */
function is_block_library( array $info ): bool {
	return str_starts_with( $info['file'], 'wp-includes/blocks/' );
}

function is_php_native( string $kind, string $name ): bool {
	return match ( $kind ) {
		'function' => function_exists( $name ) && ( new ReflectionFunction( $name ) )->isInternal(),
		'class'    => ( class_exists( $name, false ) || interface_exists( $name, false ) ) && ( new ReflectionClass( $name ) )->isInternal(),
		default    => false,
	};
}

/**
 * 親クラスにメソッドが残っていれば継承で解決される.
 */
function inherits_method( array $table, string $class, string $method ): bool {
	$seen = [];
	while ( $class && ! isset( $seen[ $class ] ) ) {
		$seen[ $class ] = true;
		if ( isset( $table['methods'][ "{$class}::{$method}" ] ) ) {
			return true;
		}
		$class = $table['classes'][ $class ]['extends'] ?? null;
	}
	return false;
}

function blob_url( string $ref, string $file, ?int $line ): string {
	return sprintf( 'https://github.com/WordPress/wordpress-develop/blob/%s/src/%s%s', $ref, $file, $line ? "#L{$line}" : '' );
}
