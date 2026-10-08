<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/lib/symbol-scanner.php';
require_once __DIR__ . '/../scripts/lib/verify.php';

class VerifyTest extends TestCase {

	private static array $tables;

	public static function setUpBeforeClass(): void {
		$table = [
			'functions'        => [],
			'classes'          => [],
			'methods'          => [],
			'hooks'            => [],
			'deprecated_hooks' => [],
			'deprecated_files' => [],
			'dynamic_hooks'    => [],
		];
		$code  = <<<'PHP'
<?php
namespace WordPress\AiClient;
class AiClient {
	public static function prompt() {}
}
function wp_real_function() {
	do_action( 'real_action' );
	apply_filters( "get_{$adjacent}_post_where", 1 );
	apply_filters( "{$a}_{$b}", 1 );
}
PHP;
		scan_php_file( $code, 'x.php', $table );
		self::$tables = [ $table ];
	}

	private function verify( array $symbols, string $status = 'shipped' ): array {
		return verify_symbols( [ 'changes' => [ [ 'id' => 'c', 'status' => $status, 'symbols' => $symbols ] ] ], self::$tables );
	}

	public function test_existing_symbols_pass(): void {
		$this->assertSame(
			[],
			$this->verify(
				[
					[ 'kind' => 'function', 'name' => 'wp_real_function' ],
					[ 'kind' => 'hook', 'name' => 'real_action' ],
					[ 'kind' => 'class', 'name' => 'WordPress\AiClient\AiClient' ],
					[ 'kind' => 'method', 'name' => 'AiClient::prompt' ],
				]
			)
		);
	}

	public function test_invented_symbols_fail(): void {
		$missing = $this->verify(
			[
				[ 'kind' => 'method', 'name' => 'AI_Client::prompt' ],
				[ 'kind' => 'function', 'name' => 'wp_invented' ],
			]
		);
		$this->assertSame( [ 'c method:AI_Client::prompt', 'c function:wp_invented' ], $missing );
	}

	public function test_dynamic_hook_names_resolve(): void {
		$this->assertSame( [], $this->verify( [ [ 'kind' => 'hook', 'name' => 'get_next_post_where' ] ] ) );
	}

	public function test_short_dynamic_pattern_does_not_vouch_for_anything(): void {
		$this->assertSame( [ 'c hook:made_up' ], $this->verify( [ [ 'kind' => 'hook', 'name' => 'made_up' ] ] ) );
	}

	public function test_planned_and_js_symbols_are_skipped(): void {
		$this->assertSame( [], $this->verify( [ [ 'kind' => 'function', 'name' => 'wp_future' ] ], 'planned' ) );
		$this->assertSame(
			[],
			$this->verify(
				[
					[ 'kind' => 'hook', 'name' => 'blocks.registerBlockType' ],
					[ 'kind' => 'js_package', 'name' => '@wordpress/whatever' ],
				]
			)
		);
	}
}
