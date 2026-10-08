<?php
/**
 * カタログの読み書きと検証.
 */

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

use JsonSchema\Validator;
use Symfony\Component\Yaml\Yaml;

const WP_COMPAT_ROOT = __DIR__ . '/../..';

function catalog_dir( string $release ): string {
	return WP_COMPAT_ROOT . '/catalog/' . $release;
}

function catalog_read( string $path ): array {
	return Yaml::parseFile( $path );
}

function catalog_write( string $path, array $catalog ): void {
	$dir = dirname( $path );
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0755, true );
	}
	file_put_contents( $path, Yaml::dump( $catalog, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE ) );
}

/**
 * スキーマ検証。エラーメッセージの配列を返す（空なら妥当）.
 */
function catalog_validate( array $catalog ): array {
	$data      = json_decode( json_encode( $catalog ) );
	$validator = new Validator();
	$validator->validate( $data, (object) [ '$ref' => 'file://' . realpath( WP_COMPAT_ROOT . '/schema/catalog.schema.json' ) ] );
	$errors = array_map( fn( $e ) => sprintf( '[%s] %s', $e['property'] ?: '(root)', $e['message'] ), $validator->getErrors() );

	$ids = array_column( $catalog['changes'] ?? [], 'id' );
	foreach ( array_unique( array_diff_assoc( $ids, array_unique( $ids ) ) ) as $dup ) {
		$errors[] = "Duplicate change id: {$dup}";
	}
	return $errors;
}

/**
 * リリース内の全カタログ（extracted + curated）を読む.
 */
function catalog_load_release( string $release ): array {
	$catalogs = [];
	foreach ( [ 'extracted', 'curated' ] as $origin ) {
		$path = catalog_dir( $release ) . "/{$origin}.yml";
		if ( is_file( $path ) ) {
			$catalogs[ $origin ] = catalog_read( $path );
		}
	}
	return $catalogs;
}

/**
 * curated.yml が extracted.yml と同じシンボルの削除・非推奨を重複して書いていないか.
 */
function catalog_duplicates( string $release ): array {
	$catalogs  = catalog_load_release( $release );
	$extracted = [];
	foreach ( $catalogs['extracted']['changes'] ?? [] as $change ) {
		foreach ( $change['symbols'] ?? [] as $symbol ) {
			$extracted[ "{$symbol['kind']}:{$symbol['name']}" ] = $change['id'];
		}
	}
	$errors = [];
	foreach ( $catalogs['curated']['changes'] ?? [] as $change ) {
		if ( ! in_array( $change['type'], [ 'removed', 'deprecated', 'hook_removed', 'hook_deprecated', 'file_deprecated', 'argument_deprecated' ], true ) ) {
			continue;
		}
		foreach ( $change['symbols'] ?? [] as $symbol ) {
			$key = "{$symbol['kind']}:{$symbol['name']}";
			if ( isset( $extracted[ $key ] ) ) {
				$errors[] = "{$change['id']} duplicates extracted {$extracted[ $key ]}";
			}
		}
	}
	return $errors;
}

/**
 * 生成日時・比較コミット以外に違いがあるか.
 */
function catalog_materially_changed( array $old, array $new ): bool {
	$strip = function ( array $catalog ): array {
		unset( $catalog['generated_at'], $catalog['compared']['to_commit'] );
		foreach ( $catalog['changes'] as &$change ) {
			// trunk の evidence は行番号・コミットが日々ずれるので比較しない.
			unset( $change['evidence'], $change['source_urls'] );
		}
		return $catalog;
	};
	return $strip( $old ) != $strip( $new );
}

/**
 * リリースの段階（trunk/beta/rc/final）。機械抽出の値を正とする.
 */
function catalog_release_status( array $catalogs ): string {
	return $catalogs['extracted']['status'] ?? $catalogs['curated']['status'] ?? 'final';
}

/**
 * catalog/ 配下のリリース一覧（バージョン順）.
 */
function catalog_releases(): array {
	$releases = array_map( 'basename', glob( WP_COMPAT_ROOT . '/catalog/*', GLOB_ONLYDIR ) );
	usort( $releases, 'version_compare' );
	return $releases;
}
