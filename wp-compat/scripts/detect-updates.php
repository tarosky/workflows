<?php
/**
 * カタログの更新が必要かを判定し、作業内容を JSON で出力する.
 *
 * Usage:
 *   php detect-updates.php --repo=../tmp/wp-compat/wp-develop.git --sources=../tmp/wp-compat/sources
 *
 * 判定:
 *   - カタログの最新リリース L が正式リリース済み（タグ L.0 がある）なのに final でない → L を final で再抽出
 *   - L が正式リリース済みで、trunk が次のリリース N に進んでいる → N のカタログを trunk で新規作成
 *   - L が未リリース → リリースブランチ（無ければ trunk）の段階（alpha/beta/RC）とコミットを確認して再抽出
 *   - 対象リリースの Dev Notes・Field Guide・リリース告知のうち、curated.yml の sources に無いもの → 取り込み対象
 *
 * 出力: {"targets": [{release, status, from_ref, to_ref, extract, new_posts: [...]}]}
 */

require __DIR__ . '/lib/catalog.php';
require __DIR__ . '/lib/matcher.php';

const DAY_IN_SECONDS = 86400;

$opts = getopt( '', [ 'repo:', 'sources:' ] );
foreach ( [ 'repo', 'sources' ] as $key ) {
	if ( empty( $opts[ $key ] ) ) {
		fwrite( STDERR, "--{$key} is required.\n" );
		exit( 1 );
	}
}
$repo    = $opts['repo'];
$targets = [];

$releases = catalog_releases();
$latest   = end( $releases );
$catalogs = catalog_load_release( $latest );
$compared = $catalogs['extracted']['compared'];
$status   = catalog_release_status( $catalogs );

if ( git_ref_exists( $repo, "refs/tags/{$latest}.0" ) ) {
	if ( 'final' !== $status ) {
		$targets[] = target( $latest, 'final', $compared['from'], "{$latest}.0", true );
	}
	$next          = next_release( $latest );
	$trunk_version = wp_version( $repo, 'trunk' );
	if ( version_major( $trunk_version ) === $next ) {
		$targets[] = target( $next, version_status( $trunk_version ), "{$latest}.0", 'trunk', true );
	}
} else {
	$ref     = git_ref_exists( $repo, "refs/heads/{$latest}" ) ? $latest : 'trunk';
	$version = wp_version( $repo, $ref );
	if ( version_major( $version ) === $latest ) {
		$new_status = version_status( $version );
		$commit     = git( $repo, [ 'rev-parse', $ref ] );
		$changed    = $new_status !== $status || $commit !== ( $compared['to_commit'] ?? '' );
		$targets[]  = target( $latest, $new_status, $compared['from'], $ref, $changed );
	}
}

// 対象リリースの未取り込みの投稿.
foreach ( $targets as &$target ) {
	$target['new_posts'] = new_posts( $target['release'], $repo, $opts['sources'] );
}
unset( $target );

$targets = array_values( array_filter( $targets, fn( $t ) => $t['extract'] || $t['new_posts'] ) );
echo json_encode( [ 'targets' => $targets ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), "\n";

function target( string $release, string $status, string $from, string $to, bool $extract ): array {
	return [
		'release'  => $release,
		'status'   => $status,
		'from_ref' => $from,
		'to_ref'   => $to,
		'extract'  => $extract,
	];
}

/**
 * Make/Core から対象リリースの投稿を取得し、curated.yml の sources に無いものを返す.
 */
function new_posts( string $release, string $repo, string $sources_dir ): array {
	$previous = previous_final_tag( $repo, $release );
	$after    = $previous
		? gmdate( 'Y-m-d', strtotime( git( $repo, [ 'log', '-1', '--format=%cI', $previous ] ) ) - 30 * DAY_IN_SECONDS )
		: gmdate( 'Y-m-d', time() - 180 * DAY_IN_SECONDS );
	$before   = gmdate( 'Y-m-d', time() + DAY_IN_SECONDS );
	$command  = sprintf(
		'%s %s --version=%s --after=%s --before=%s --out=%s',
		escapeshellarg( PHP_BINARY ),
		escapeshellarg( __DIR__ . '/fetch-make-posts.php' ),
		escapeshellarg( $release ),
		$after,
		$before,
		escapeshellarg( $sources_dir )
	);
	exec( $command . ' 2>&1', $output, $code );
	fwrite( STDERR, implode( "\n", $output ) . "\n" );
	if ( 0 !== $code ) {
		exit( 1 );
	}

	$known = [];
	$path  = catalog_dir( $release ) . '/curated.yml';
	if ( is_file( $path ) ) {
		$known = array_flip( array_map( 'normalize_url', array_column( catalog_read( $path )['sources'] ?? [], 'url' ) ) );
	}
	$index = json_decode( file_get_contents( "{$sources_dir}/{$release}/make/_index.json" ), true );
	$posts = array_filter(
		$index,
		fn( $post ) => in_array( $post['relevance'], [ 'dev-note', 'field-guide', 'release' ], true ) && ! isset( $known[ normalize_url( $post['link'] ) ] )
	);
	return array_values(
		array_map(
			fn( $post ) => [
				'title'     => $post['title'],
				'link'      => $post['link'],
				'date'      => substr( $post['date'], 0, 10 ),
				'relevance' => $post['relevance'],
				'file'      => "{$sources_dir}/{$release}/make/{$post['slug']}.json",
			],
			$posts
		)
	);
}


function normalize_url( string $url ): string {
	return rtrim( preg_replace( '#^https?://#', '', $url ), '/' );
}

function previous_final_tag( string $repo, string $release ): ?string {
	[ $major, $minor ] = array_map( 'intval', explode( '.', $release ) );
	$previous          = 0 === $minor ? ( $major - 1 ) . '.9' : "{$major}." . ( $minor - 1 );
	return git_ref_exists( $repo, "refs/tags/{$previous}.0" ) ? "{$previous}.0" : null;
}

function next_release( string $release ): string {
	[ $major, $minor ] = array_map( 'intval', explode( '.', $release ) );
	return 9 === $minor ? ( $major + 1 ) . '.0' : "{$major}." . ( $minor + 1 );
}

/**
 * "7.2-beta1-src" → beta、"7.2-RC1-src" → rc、"7.2-alpha-..." → trunk、"7.2" → final.
 */
function version_status( string $version ): string {
	if ( preg_match( '/-rc\d*/i', $version ) ) {
		return 'rc';
	}
	if ( preg_match( '/-beta\d*/i', $version ) ) {
		return 'beta';
	}
	if ( preg_match( '/-alpha/i', $version ) ) {
		return 'trunk';
	}
	return 'final';
}

/**
 * "7.2-alpha-63166-src" → "7.2".
 */
function version_major( string $version ): string {
	return preg_match( '/^(\d+\.\d+)/', $version, $m ) ? $m[1] : '';
}

function wp_version( string $repo, string $ref ): string {
	$code = git( $repo, [ 'show', "{$ref}:src/wp-includes/version.php" ] );
	if ( ! preg_match( '/\$wp_version\s*=\s*\'([^\']+)\'/', $code, $m ) ) {
		throw new RuntimeException( "wp_version not found at {$ref}" );
	}
	return $m[1];
}

function git_ref_exists( string $repo, string $ref ): bool {
	exec( sprintf( 'git -C %s show-ref --verify --quiet %s', escapeshellarg( $repo ), escapeshellarg( $ref ) ), $output, $code );
	return 0 === $code;
}

function git( string $repo, array $args ): string {
	exec( 'git -C ' . escapeshellarg( $repo ) . ' ' . implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1', $output, $code );
	if ( 0 !== $code ) {
		throw new RuntimeException( implode( "\n", $output ) );
	}
	return trim( implode( "\n", $output ) );
}
