<?php

namespace Tests\Unit;

use App\Enums\DataType;
use App\Services\Sync\ValueCaster;
use Tests\TestCase;

class ValueCasterTest extends TestCase
{
    private ValueCaster $caster;

    protected function setUp(): void
    {
        parent::setUp();
        $this->caster = new ValueCaster;
    }

    public function test_empty_cells_become_null(): void
    {
        $this->assertSame([null, true], $this->caster->cast('  ', DataType::Integer));
        $this->assertSame([null, true], $this->caster->cast(null, DataType::String));
    }

    public function test_numbers(): void
    {
        $this->assertSame([1200, true], $this->caster->cast('1,200', DataType::Integer));
        $this->assertSame([12, true], $this->caster->cast(12.0, DataType::Integer));
        $this->assertSame([3.5, true], $this->caster->cast('€ 3.50', DataType::Decimal));
        $this->assertSame([0.05, true], $this->caster->cast('5%', DataType::Decimal));
        $this->assertSame([null, false], $this->caster->cast('n/a', DataType::Integer));
    }

    public function test_thousands_and_decimal_separators(): void
    {
        $this->assertSame([1234.5, true], $this->caster->cast('1.234,50', DataType::Decimal));
        $this->assertSame([1234.5, true], $this->caster->cast('1,234.50', DataType::Decimal));
        $this->assertSame([3.5, true], $this->caster->cast('3,5', DataType::Decimal));
        $this->assertSame([1234567, true], $this->caster->cast('1.234.567', DataType::Integer));
        $this->assertSame([1234567, true], $this->caster->cast('1,234,567', DataType::Integer));
    }

    public function test_fractions_in_integer_columns_are_rounded_and_reported(): void
    {
        $this->assertSame([13, false], $this->caster->cast(12.7, DataType::Integer));
        $this->assertSame([4, false], $this->caster->cast('3,5', DataType::Integer));
    }

    public function test_long_text_is_truncated_and_reported(): void
    {
        [$value, $ok] = $this->caster->cast(str_repeat('a', 300), DataType::String);

        $this->assertSame(255, mb_strlen($value));
        $this->assertFalse($ok);
    }

    public function test_dates_from_serial_numbers_and_strings(): void
    {
        // 46309 is the Google Sheets serial for 2026-10-14.
        $this->assertSame(['2026-10-14', true], $this->caster->cast(46309, DataType::Date));
        $this->assertSame(['2026-10-14 12:00:00', true], $this->caster->cast(46309.5, DataType::DateTime));
        $this->assertSame(['2026-10-14', true], $this->caster->cast('14/10/2026', DataType::Date, 'd/m/Y'));
        $this->assertSame(['2026-10-14 00:00:00', true], $this->caster->cast('14/10/2026', DataType::DateTime, 'd/m/Y'));
        $this->assertSame(['2026-10-14', true], $this->caster->cast('2026-10-14', DataType::Date));
        $this->assertSame([null, false], $this->caster->cast('soon', DataType::Date));
    }

    public function test_strings_and_booleans(): void
    {
        $this->assertSame(['12', true], $this->caster->cast(12.0, DataType::String));
        $this->assertSame(['TRUE', true], $this->caster->cast(true, DataType::String));
        $this->assertSame([true, true], $this->caster->cast('Yes', DataType::Boolean));
        $this->assertSame([false, true], $this->caster->cast('0', DataType::Boolean));
        $this->assertSame([null, false], $this->caster->cast('maybe', DataType::Boolean));
    }
}
