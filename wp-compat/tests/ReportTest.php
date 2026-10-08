<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/lib/matcher.php';
require_once __DIR__ . '/../scripts/lib/report.php';

class ReportTest extends TestCase {

	public function test_checked_items_survive_new_sha(): void {
		$old = "- [x] [a.php:3](https://github.com/o/r/blob/aaa/a.php#L3) `foo`\n- [ ] [b.php:9](https://github.com/o/r/blob/aaa/b.php#L9) `bar`";
		$new = "- [ ] [a.php:3](https://github.com/o/r/blob/bbb/a.php#L3) `foo`\n- [ ] [b.php:9](https://github.com/o/r/blob/bbb/b.php#L9) `bar`\n- [ ] [c.php:1](https://github.com/o/r/blob/bbb/c.php#L1) `baz`";
		$merged = report_merge_checks( $old, $new );
		$this->assertStringContainsString( '- [x] [a.php:3](https://github.com/o/r/blob/bbb/a.php#L3)', $merged );
		$this->assertStringContainsString( '- [ ] [b.php:9]', $merged );
		$this->assertStringContainsString( '- [ ] [c.php:1]', $merged );
	}

	public function test_marker_is_per_major_release(): void {
		$this->assertSame( report_marker( '7.1' ), report_marker( '7.1.3' ) );
		$this->assertNotSame( report_marker( '7.1' ), report_marker( '7.2' ) );
	}

	public function test_markdown_sections(): void {
		$item   = [
			'id'             => 'behavior:x',
			'release'        => '7.1',
			'release_status' => 'final',
			'type'           => 'behavior',
			'title'          => 'Something changed',
			'status'         => 'shipped',
			'severity'       => 'medium',
			'confidence'     => 'high',
			'hits'           => [ [ 'symbol' => 'foo', 'loc' => 'a.php:3' ] ],
			'notes'          => [],
		];
		$body   = report_markdown(
			[
				'from'      => '7.0',
				'to'        => '7.1',
				'files'     => 1,
				'matched'   => [ $item, [ 'severity' => 'low' ] + $item ],
				'weak'      => [],
				'checklist' => [],
			],
			'o/r',
			'sha'
		);
		$this->assertStringStartsWith( '<!-- wp-compat:7.1 -->', $body );
		$this->assertStringContainsString( '| 1 | 1 | 0 | 0 |', $body );
		$this->assertStringContainsString( '- [ ] [a.php:3](https://github.com/o/r/blob/sha/a.php#L3) `foo`', $body );
		$this->assertStringContainsString( '<details><summary>該当箇所（重大度 低）</summary>', $body );
	}
}
