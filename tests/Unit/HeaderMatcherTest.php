<?php

namespace Tests\Unit;

use App\Services\Sync\HeaderMatcher;
use PHPUnit\Framework\TestCase;

class HeaderMatcherTest extends TestCase
{
    public function test_it_matches_by_name_regardless_of_position_case_and_spacing(): void
    {
        $result = (new HeaderMatcher)->match(
            ['Region', " Product  Name\u{00A0}", 'DATE', 'price'],
            ['date', 'product name', 'price'],
        );

        $this->assertSame(['date' => 2, 'product name' => 1, 'price' => 3], $result['indexes']);
        $this->assertSame([], $result['missing']);
    }

    public function test_it_reports_missing_and_duplicate_headers(): void
    {
        $result = (new HeaderMatcher)->match(['amount', 'amount', ''], ['amount', 'discount']);

        $this->assertSame(['amount' => 0], $result['indexes']);
        $this->assertSame(['discount'], $result['missing']);
        $this->assertSame(['amount'], $result['duplicates']);
    }
}
