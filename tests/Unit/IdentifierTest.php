<?php

namespace Tests\Unit;

use App\Support\Identifier;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IdentifierTest extends TestCase
{
    #[DataProvider('invalidNames')]
    public function test_it_rejects_unsafe_identifiers(string $name): void
    {
        $this->assertFalse(Identifier::isValid($name));
    }

    public static function invalidNames(): array
    {
        return [
            'injection' => ['sales; DROP TABLE users'],
            'backtick' => ['sales`'],
            'space' => ['product name'],
            'uppercase' => ['Sales'],
            'leading digit' => ['1sales'],
            'empty' => [''],
            'too long' => [str_repeat('a', 65)],
            'unicode' => ['shitjet_ë'],
        ];
    }

    public function test_it_accepts_safe_identifiers(): void
    {
        $this->assertTrue(Identifier::isValid('sales'));
        $this->assertTrue(Identifier::isValid('product_price_2'));
        $this->assertTrue(Identifier::isValid(str_repeat('a', 64)));
    }

    public function test_application_tables_cannot_be_targets(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Identifier::assertTable('users');
    }

    public function test_system_columns_cannot_be_mapped(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Identifier::assertColumn('row_key');
    }

    public function test_it_suggests_identifiers_from_headers(): void
    {
        $this->assertSame('product_name', Identifier::suggest('Product Name'));
        $this->assertSame('price_eur', Identifier::suggest(' Price (EUR) '));
        $this->assertSame('col_2026', Identifier::suggest('2026'));
        $this->assertSame('sheet_id', Identifier::suggest('ID'));
    }
}
