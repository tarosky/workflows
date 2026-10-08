<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/lib/matcher.php';
require_once __DIR__ . '/../scripts/lib/report.php';
require_once __DIR__ . '/../scripts/lib/issues.php';

class IssuesTest extends TestCase {

	private function body( string $release, array $ids, string $extra = '' ): string {
		return report_marker( $release ) . "\n" . report_ids_marker( $ids ) . "\n" . $extra;
	}

	private function issue( int $number, string $state, string $release, array $ids, string $extra = '' ): array {
		return [ 'number' => $number, 'state' => $state, 'body' => $this->body( $release, $ids, $extra ) ];
	}

	public function test_parse(): void {
		$parsed = issue_parse( $this->body( '7.2', [ 'a@7.1', 'b@7.2' ] ) );
		$this->assertSame( '7.2', $parsed['release'] );
		$this->assertSame( [ 'a@7.1', 'b@7.2' ], $parsed['ids'] );
		$this->assertSame( [], issue_parse( $this->body( '7.2', [] ) )['ids'] );
	}

	public function test_create_when_nothing_exists(): void {
		$plan = issue_plan( [], '7.2', $this->body( '7.2', [ 'a@7.2' ] ), [ 'a@7.2' ], true );
		$this->assertSame( 'create', $plan['action'] );
	}

	public function test_no_create_without_significant_match(): void {
		$plan = issue_plan( [], '7.2', $this->body( '7.2', [] ), [], false );
		$this->assertSame( 'none', $plan['action'] );
	}

	public function test_update_open_issue_and_keep_checks(): void {
		$old  = $this->issue( 5, 'OPEN', '7.2', [ 'a@7.2' ], "- [x] [a.php:1](https://x/blob/old/a.php#L1) `foo`" );
		$new  = $this->body( '7.2', [ 'a@7.2' ], "- [ ] [a.php:1](https://x/blob/new/a.php#L1) `foo`" );
		$plan = issue_plan( [ $old ], '7.2', $new, [ 'a@7.2' ], true );
		$this->assertSame( [ 'update', 5 ], [ $plan['action'], $plan['number'] ] );
		$this->assertStringContainsString( '- [x] [a.php:1](https://x/blob/new/a.php#L1)', $plan['body'] );
	}

	public function test_closed_issue_with_same_items_is_left_alone(): void {
		$closed = $this->issue( 5, 'CLOSED', '7.2', [ 'a@7.2', 'b@7.1' ] );
		$plan   = issue_plan( [ $closed ], '7.2', $this->body( '7.2', [ 'a@7.2' ] ), [ 'a@7.2' ], true );
		$this->assertSame( 'none', $plan['action'] );
		$this->assertSame( 5, $plan['number'] );
	}

	public function test_closed_issue_is_reopened_for_new_items(): void {
		$closed = $this->issue( 5, 'CLOSED', '7.2', [ 'a@7.2' ] );
		$plan   = issue_plan( [ $closed ], '7.2', $this->body( '7.2', [ 'a@7.2', 'c@7.2' ] ), [ 'a@7.2', 'c@7.2' ], true );
		$this->assertSame( [ 'reopen', 5, [ 'c@7.2' ] ], [ $plan['action'], $plan['number'], $plan['new_ids'] ] );
	}

	public function test_items_seen_in_any_closed_issue_count_as_confirmed(): void {
		$issues = [
			$this->issue( 5, 'CLOSED', '7.2', [ 'a@7.2' ] ),
			$this->issue( 9, 'CLOSED', '7.2', [ 'c@7.2' ] ),
		];
		$plan   = issue_plan( $issues, '7.2', $this->body( '7.2', [ 'a@7.2', 'c@7.2' ] ), [ 'a@7.2', 'c@7.2' ], true );
		$this->assertSame( 'none', $plan['action'] );
		$this->assertSame( 9, $plan['number'] );
	}

	public function test_open_issue_wins_over_closed(): void {
		$issues = [
			$this->issue( 5, 'CLOSED', '7.2', [] ),
			$this->issue( 9, 'OPEN', '7.2', [] ),
		];
		$plan   = issue_plan( $issues, '7.2', $this->body( '7.2', [] ), [], false );
		$this->assertSame( [ 'update', 9 ], [ $plan['action'], $plan['number'] ] );
	}

	public function test_older_open_releases_are_superseded(): void {
		$issues = [
			$this->issue( 3, 'OPEN', '7.1', [ 'a@7.1' ] ),
			$this->issue( 4, 'CLOSED', '7.0', [] ),
			$this->issue( 6, 'OPEN', '7.3', [] ),
			[ 'number' => 7, 'state' => 'OPEN', 'body' => 'no marker' ],
		];
		$plan   = issue_plan( $issues, '7.2', $this->body( '7.2', [ 'a@7.1' ] ), [ 'a@7.1' ], true );
		$this->assertSame( 'create', $plan['action'] );
		$this->assertSame( [ 3 ], $plan['supersede'] );
	}

	public function test_older_issue_kept_when_no_new_issue(): void {
		$issues = [ $this->issue( 3, 'OPEN', '7.1', [ 'a@7.1' ] ) ];
		$plan   = issue_plan( $issues, '7.2', $this->body( '7.2', [] ), [], false );
		$this->assertSame( 'none', $plan['action'] );
		$this->assertSame( [], $plan['supersede'] );
	}

	public function test_older_issue_superseded_by_closed_current_release(): void {
		$issues = [
			$this->issue( 3, 'OPEN', '7.1', [ 'a@7.1' ] ),
			$this->issue( 5, 'CLOSED', '7.2', [ 'a@7.1' ] ),
		];
		$plan   = issue_plan( $issues, '7.2', $this->body( '7.2', [ 'a@7.1' ] ), [ 'a@7.1' ], true );
		$this->assertSame( 'none', $plan['action'] );
		$this->assertSame( [ 3 ], $plan['supersede'] );
	}

	public function test_significant_ids_exclude_low_and_checklist(): void {
		$item   = fn( $id, $severity ) => [ 'id' => $id, 'release' => '7.2', 'severity' => $severity ];
		$result = [
			'matched'   => [ $item( 'b', 'medium' ), $item( 'a', 'high' ), $item( 'c', 'low' ) ],
			'checklist' => [ $item( 'd', 'high' ) ],
		];
		$this->assertSame( [ 'a@7.2', 'b@7.2' ], report_significant_ids( $result ) );
	}

	public function test_legacy_closed_issue_without_ids_is_not_reopened(): void {
		$legacy = [ 'number' => 5, 'state' => 'CLOSED', 'body' => report_marker( '7.2' ) . "\nold body" ];
		$plan   = issue_plan( [ $legacy ], '7.2', $this->body( '7.2', [ 'a@7.2' ] ), [ 'a@7.2' ], true );
		$this->assertSame( 'none', $plan['action'] );
	}
}
