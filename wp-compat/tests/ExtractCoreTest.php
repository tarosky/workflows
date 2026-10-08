<?php

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * extract-core.php を CLI として実行し、出力カタログを検証する.
 */
class ExtractCoreTest extends TestCase {

	private static array $changes;

	public static function setUpBeforeClass(): void {
		$out     = tempnam( sys_get_temp_dir(), 'wp-compat' ) . '.yml';
		$fixture = __DIR__ . '/fixtures';
		$command = sprintf(
			'%s %s --release=9.9 --status=final --from-dir=%s --from-ref=9.8.0 --to-dir=%s --to-ref=9.9.0 --out=%s 2>&1',
			escapeshellarg( PHP_BINARY ),
			escapeshellarg( __DIR__ . '/../scripts/extract-core.php' ),
			escapeshellarg( "{$fixture}/core-from" ),
			escapeshellarg( "{$fixture}/core-to" ),
			escapeshellarg( $out )
		);
		exec( $command, $output, $code );
		if ( 0 !== $code ) {
			self::fail( implode( "\n", $output ) );
		}
		self::$changes = array_column( Yaml::parseFile( $out )['changes'], null, 'id' );
		unlink( $out );
	}

	public function test_removed_function(): void {
		$this->assertSame( 'high', self::$changes['removed:function:wp_will_be_removed']['severity'] );
	}

	public function test_private_removal_is_low(): void {
		$this->assertSame( 'low', self::$changes['removed:function:wp_private_removed']['severity'] );
	}

	public function test_removed_public_method(): void {
		$this->assertArrayHasKey( 'removed:method:WP_Thing::goes', self::$changes );
	}

	public function test_private_and_inherited_methods_are_ignored(): void {
		$this->assertArrayNotHasKey( 'removed:method:WP_Thing::hidden', self::$changes );
		$this->assertArrayNotHasKey( 'removed:method:WP_Child::moved_to_parent', self::$changes );
	}

	public function test_newly_deprecated_function(): void {
		$change = self::$changes['deprecated:function:wp_will_be_deprecated'];
		$this->assertSame( '9.9.0', $change['since'] );
	}

	public function test_new_method_that_is_deprecated_is_ignored(): void {
		$this->assertArrayNotHasKey( 'deprecated:method:WP_Child::legacy', self::$changes );
	}

	public function test_hooks(): void {
		$this->assertArrayHasKey( 'hook_removed:hook:old_action', self::$changes );
		$this->assertArrayNotHasKey( 'hook_removed:hook:kept_filter', self::$changes );
		$this->assertSame( 'kept_filter', self::$changes['hook_deprecated:hook:old_filter']['symbols'][0]['replacement'] );
	}

	public function test_removed_script_handle(): void {
		$this->assertArrayHasKey( 'removed:js_handle:swfupload', self::$changes );
		$this->assertArrayNotHasKey( 'removed:js_handle:kept', self::$changes );
	}

	public function test_php_requirement(): void {
		$this->assertArrayHasKey( 'php_requirement:php:7.4', self::$changes );
		$this->assertArrayNotHasKey( 'php_requirement:mysql:5.5.5', self::$changes );
	}
}
