<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/lib/code-index.php';
require_once __DIR__ . '/../scripts/lib/matcher.php';

class MatcherTest extends TestCase {

	private static array $index;

	public static function setUpBeforeClass(): void {
		self::$index = code_index_build( __DIR__ . '/fixtures/plugin' );
	}

	private function change( array $override ): array {
		return $override + [
			'id'         => 'test',
			'type'       => 'behavior',
			'title'      => 'test',
			'status'     => 'shipped',
			'severity'   => 'medium',
			'confidence' => 'high',
		];
	}

	private function match( array $symbols, array $override = [] ): array {
		return matcher_match( $this->change( [ 'symbols' => $symbols ] + $override ), self::$index );
	}

	public function test_hook_literal_matches(): void {
		$result = $this->match( [ [ 'kind' => 'hook', 'name' => 'plugin_locale' ] ] );
		$this->assertSame( 'strong', $result['strength'] );
		$this->assertSame( 'plugin.php:6', $result['hits'][0]['loc'] );
	}

	public function test_dynamic_hook_name_is_wildcard(): void {
		$result = $this->match( [ [ 'kind' => 'hook', 'name' => 'get_{$adjacent}_post_where' ] ] );
		$this->assertSame( 'strong', $result['strength'] );
	}

	public function test_vendor_is_excluded(): void {
		$result = $this->match( [ [ 'kind' => 'hook', 'name' => 'theme_locale' ] ] );
		$this->assertSame( 'none', $result['strength'] );
	}

	public function test_dot_dirs_and_lockfiles_are_excluded(): void {
		$paths = array_keys( self::$index['lines'] );
		$this->assertNotContains( '.claude/banner.js', $paths );
		$this->assertNotContains( 'package-lock.json', $paths );
		$this->assertSame( 'none', $this->match( [ [ 'kind' => 'js_handle', 'name' => 'esprima' ] ] )['strength'] );
	}

	public function test_removed_function_defined_by_plugin_adds_note(): void {
		$result = $this->match( [ [ 'kind' => 'function', 'name' => 'removed_core_function' ] ], [ 'type' => 'removed' ] );
		$this->assertSame( 'strong', $result['strength'] );
		$this->assertCount( 1, $result['notes'] );
	}

	public function test_method_requires_class_in_same_file(): void {
		$result = $this->match( [ [ 'kind' => 'method', 'name' => 'WP_Query::get' ] ] );
		$this->assertSame( [ 'src/query-user.php:3' ], array_column( $result['hits'], 'loc' ) );
	}

	public function test_parent_constructor_call(): void {
		$result = $this->match( [ [ 'kind' => 'method', 'name' => 'WP_Importer::__construct' ] ] );
		$this->assertSame( [ 'src/class-importer.php:4' ], array_column( $result['hits'], 'loc' ) );
	}

	public function test_js_package_only_is_weak(): void {
		$result = $this->match( [ [ 'kind' => 'js_package', 'name' => '@wordpress/components' ] ] );
		$this->assertSame( 'weak', $result['strength'] );
	}

	public function test_match_hint(): void {
		$result = matcher_match( $this->change( [ 'match_hint' => '__experimentalNavigation\b' ] ), self::$index );
		$this->assertSame( 'strong', $result['strength'] );
		$this->assertSame( 'assets/js/editor.js:1', $result['hits'][0]['loc'] );
	}

	public function test_manual_hint_goes_to_checklist_or_reference(): void {
		$catalog = [
			'releases' => [
				[
					'release' => '7.1',
					'status'  => 'final',
					'changes' => [
						$this->change( [ 'id' => 'high-manual', 'severity' => 'high', 'confidence' => 'manual', 'match_hint' => 'document\.querySelector' ] ),
						$this->change( [ 'id' => 'medium-manual', 'confidence' => 'manual', 'match_hint' => 'document\.querySelector' ] ),
						$this->change( [ 'id' => 'medium-high', 'symbols' => [ [ 'kind' => 'function', 'name' => 'load_plugin_textdomain' ] ] ] ),
					],
				],
			],
		];
		$result = matcher_run( $catalog, self::$index, '7.0', '7.1' );
		$this->assertSame( [ 'medium-high' ], array_column( $result['matched'], 'id' ) );
		$this->assertSame( [ 'high-manual' ], array_column( $result['checklist'], 'id' ) );
		$this->assertSame( [ 'medium-manual' ], array_column( $result['weak'], 'id' ) );
	}

	public function test_range_excludes_from_and_dropped(): void {
		$catalog = [
			'releases' => [
				[ 'release' => '6.6', 'status' => 'final', 'changes' => [ $this->change( [ 'id' => 'old' ] ) ] ],
				[ 'release' => '6.7', 'status' => 'final', 'changes' => [ $this->change( [ 'id' => 'in' ] ), $this->change( [ 'id' => 'dropped', 'status' => 'dropped' ] ) ] ],
				[ 'release' => '7.2', 'status' => 'trunk', 'changes' => [ $this->change( [ 'id' => 'future' ] ) ] ],
			],
		];
		$this->assertSame( [ 'in' ], array_column( matcher_changes_in_range( $catalog, '6.6', '7.1' ), 'id' ) );
		$this->assertSame( [ 'in' ], array_column( matcher_changes_in_range( $catalog, '6.6.2', '7.1.3' ), 'id' ) );
	}

	#[DataProvider( 'fixed_cases' )]
	public function test_is_fixed( string $fixed_in, string $to, bool $expected ): void {
		$this->assertSame( $expected, is_fixed( $fixed_in, $to ) );
	}

	public static function fixed_cases(): array {
		return [
			'latest minor'     => [ '7.1.1', '7.1', true ],
			'exact x.y.0'      => [ '7.1.1', '7.1.0', false ],
			'exact fixed'      => [ '7.1.1', '7.1.1', true ],
			'later major'      => [ '7.1.1', '7.2', true ],
			'earlier major'    => [ '7.1.1', '7.0', false ],
		];
	}
}
