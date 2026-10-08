<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/lib/catalog-diff.php';

class CatalogDiffTest extends TestCase {

	private function change( string $id, string $status = 'shipped', string $severity = 'medium' ): array {
		return [ 'id' => $id, 'title' => "Title {$id}", 'status' => $status, 'severity' => $severity ];
	}

	private function catalog( array $releases ): array {
		return [ 'releases' => $releases ];
	}

	public function test_no_diff_when_identical(): void {
		$catalog = $this->catalog( [ [ 'release' => '7.2', 'status' => 'trunk', 'changes' => [ $this->change( 'a' ) ] ] ] );
		$this->assertSame( [], catalog_diff( $catalog, $catalog ) );
	}

	public function test_added_status_changed_removed_and_release_status(): void {
		$old  = $this->catalog( [ [ 'release' => '7.2', 'status' => 'trunk', 'changes' => [ $this->change( 'a', 'planned' ), $this->change( 'gone' ) ] ] ] );
		$new  = $this->catalog( [ [ 'release' => '7.2', 'status' => 'beta', 'changes' => [ $this->change( 'a', 'shipped' ), $this->change( 'b', 'shipped', 'high' ) ] ] ] );
		$diff = catalog_diff( $old, $new );
		$this->assertCount( 1, $diff );
		$this->assertSame( [ 'trunk', 'beta' ], [ $diff[0]['status_from'], $diff[0]['status_to'] ] );
		$this->assertSame( [ 'b' ], array_column( $diff[0]['added'], 'id' ) );
		$this->assertSame( 'planned', $diff[0]['status_changed'][0]['from'] );
		$this->assertSame( [ 'gone' ], array_column( $diff[0]['removed'], 'id' ) );
	}

	public function test_new_release_on_first_publish(): void {
		$diff = catalog_diff( [], $this->catalog( [ [ 'release' => '7.3', 'status' => 'trunk', 'changes' => [ $this->change( 'x' ) ] ] ] ) );
		$this->assertNull( $diff[0]['status_from'] );
		$this->assertCount( 1, $diff[0]['added'] );
	}

	public function test_slack_message_lists_only_high_and_medium(): void {
		$diff = catalog_diff(
			[],
			$this->catalog( [ [ 'release' => '7.3', 'status' => 'trunk', 'changes' => [ $this->change( 'x', 'shipped', 'high' ), $this->change( 'y', 'shipped', 'low' ) ] ] ] )
		);
		$text = catalog_diff_slack( $diff, 'https://example.com/' )['blocks'][0]['text']['text'];
		$this->assertStringContainsString( '*WordPress 7.3*（新規・trunk） 追加 2 件（高 1・中 0・低 1）', $text );
		$this->assertStringContainsString( '|Title x>', $text );
		$this->assertStringNotContainsString( 'Title y', $text );
	}

	public function test_slack_escapes_titles(): void {
		$diff = catalog_diff( [], $this->catalog( [ [ 'release' => '7.3', 'status' => 'trunk', 'changes' => [ [ 'title' => '<script> & co' ] + $this->change( 'x' ) ] ] ] ) );
		$text = catalog_diff_slack( $diff, 'https://example.com/' )['blocks'][0]['text']['text'];
		$this->assertStringContainsString( '&lt;script&gt; &amp; co', $text );
	}
}
