<?php

namespace Database\Seeders;

use App\Models\Spreadsheet;
use App\Models\TableMapping;
use Illuminate\Database\Seeder;

/**
 * Demo configuration matching database/fixtures/sheets (SHEETS_DRIVER=fake).
 *
 * Shows: different column order and names between files, one tab feeding
 * two tables, two files feeding the same table, and a column (discount)
 * that only one file has, which is added to `sales` with ALTER TABLE.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $storeA = Spreadsheet::updateOrCreate(['google_spreadsheet_id' => 'demo-store-a'], ['name' => 'Store A - Sales 2026']);
        $storeB = Spreadsheet::updateOrCreate(['google_spreadsheet_id' => 'demo-store-b'], ['name' => 'Store B - Sales export']);

        $aSales = $storeA->tabs()->updateOrCreate(['tab_name' => 'Sales'], ['header_row' => 1]);
        $aStock = $storeA->tabs()->updateOrCreate(['tab_name' => 'Stock'], ['header_row' => 1]);
        $bOrders = $storeB->tabs()->updateOrCreate(['tab_name' => 'Orders'], ['header_row' => 2]);

        $this->mapping($aSales, 'sales', 'upsert', [
            ['order_id', 'order_id', 'string', true],
            ['date', 'date', 'date'],
            ['product_name', 'name', 'string'],
            ['price', 'product_price', 'decimal'],
            ['amount', 'amount', 'integer'],
        ]);

        $this->mapping($aSales, 'daily_sales_snapshot', 'snapshot', [
            ['product_name', 'product', 'string'],
            ['amount', 'qty', 'integer'],
        ]);

        $this->mapping($aStock, 'inventory', 'replace', [
            ['SKU', 'sku', 'string'],
            ['Product', 'product', 'string'],
            ['Warehouse', 'warehouse', 'string'],
            ['Qty', 'quantity', 'integer'],
        ]);

        $this->mapping($bOrders, 'sales', 'upsert', [
            ['Order ID', 'order_id', 'string', true],
            ['Date', 'date', 'date', false, 'd/m/Y'],
            ['Product Name', 'name', 'string'],
            ['Price', 'product_price', 'decimal'],
            ['Amount', 'amount', 'integer'],
            ['Discount', 'discount', 'decimal'],
            ['Region', 'region', 'string'],
        ]);
    }

    private function mapping($tab, string $table, string $mode, array $columns): void
    {
        /** @var TableMapping $mapping */
        $mapping = $tab->tableMappings()->updateOrCreate(['target_table' => $table], ['sync_mode' => $mode]);

        foreach ($columns as $column) {
            [$source, $target, $type, $key, $format] = array_pad($column, 5, null);

            $mapping->columnMappings()->updateOrCreate(['target_column' => $target], [
                'source_column' => $source,
                'data_type' => $type,
                'is_unique_key' => $key ?? false,
                'date_format' => $format ?? null,
            ]);
        }
    }
}
