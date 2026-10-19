<?php

namespace Tests\Feature;

use App\Enums\SyncStatus;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\TableMapping;
use App\Services\GoogleSheets\FakeSheetsClient;
use App\Services\GoogleSheets\SheetsClient;
use App\Services\Sync\SheetSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SheetSyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeSheetsClient $sheets;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sheets = new FakeSheetsClient;
        $this->app->instance(SheetsClient::class, $this->sheets);
    }

    public function test_it_creates_the_table_and_maps_renamed_columns(): void
    {
        // The example from the task: [date, product_name, price, amount] → sales.
        $this->sheets->put('file-1', ['Sales' => [
            ['date', 'product_name', 'price', 'amount'],
            [46309, 'Pen', 1.2, 25],
            [46310, 'Notebook', 3.5, 10],
        ]]);

        $this->mapping('file-1', 'Sales', 'sales', 'upsert', [
            ['date', 'date', 'date', true],
            ['product_name', 'name', 'string', true],
            ['price', 'product_price', 'decimal'],
            ['amount', 'amount', 'integer'],
        ]);

        $run = $this->sync();

        $this->assertSame(SyncStatus::Success, $run->status);
        $this->assertTrue(Schema::hasColumns('sales', ['date', 'name', 'product_price', 'amount']));
        $this->assertDatabaseHas('sales', ['date' => '2026-10-14', 'name' => 'Pen', 'product_price' => 1.2, 'amount' => 25]);
        $this->assertSame(2, DB::table('sales')->count());
    }

    public function test_columns_are_matched_by_header_not_by_position(): void
    {
        $this->sheets->put('file-1', ['Sales' => [['id', 'product', 'amount'], ['1', 'Pen', 5]]]);
        $this->sheets->put('file-2', ['Data' => [['Notes', 'Amount', 'Product', 'ID'], ['x', 7, 'Glue', '2']]]);

        $columns = [['id', 'external_id', 'string', true], ['product', 'name', 'string'], ['amount', 'amount', 'integer']];
        $this->mapping('file-1', 'Sales', 'sales', 'upsert', $columns);
        $this->mapping('file-2', 'Data', 'sales', 'upsert', $columns);

        $this->sync();

        $this->assertDatabaseHas('sales', ['external_id' => '2', 'name' => 'Glue', 'amount' => 7]);
        $this->assertDatabaseHas('sales', ['external_id' => '1', 'name' => 'Pen', 'amount' => 5]);
    }

    public function test_one_tab_can_feed_several_tables(): void
    {
        $this->sheets->put('file-1', ['Sales' => [['sku', 'product', 'qty'], ['P1', 'Pen', 3]]]);

        $this->mapping('file-1', 'Sales', 'products', 'upsert', [['sku', 'sku', 'string', true], ['product', 'title', 'string']]);
        $tab = SheetTab::first();
        $this->mapping('file-1', 'Sales', 'stock_levels', 'replace', [['sku', 'sku', 'string'], ['qty', 'quantity', 'integer']], $tab);

        $this->sync();

        $this->assertDatabaseHas('products', ['sku' => 'P1', 'title' => 'Pen']);
        $this->assertDatabaseHas('stock_levels', ['sku' => 'P1', 'quantity' => 3]);
    }

    public function test_a_column_added_to_the_config_is_added_to_the_table_and_filled(): void
    {
        $this->sheets->put('file-1', ['Sales' => [['id', 'amount', 'discount'], ['1', 5, '10%']]]);
        $mapping = $this->mapping('file-1', 'Sales', 'sales', 'upsert', [['id', 'id_ext', 'string', true], ['amount', 'amount', 'integer']]);

        $this->sync();
        $this->assertFalse(Schema::hasColumn('sales', 'discount'));

        $mapping->columnMappings()->create(['source_column' => 'discount', 'target_column' => 'discount', 'data_type' => 'decimal']);
        $run = $this->sync();

        $this->assertTrue(Schema::hasColumn('sales', 'discount'));
        $this->assertDatabaseHas('sales', ['id_ext' => '1', 'discount' => 0.1]);
        $this->assertStringContainsString('Added column `discount`', implode(' ', $run->items->first()->warnings));
    }

    public function test_upsert_is_idempotent_and_updates_changed_rows(): void
    {
        $this->sheets->put('file-1', ['Sales' => [['id', 'amount'], ['1', 5], ['2', 6]]]);
        $this->mapping('file-1', 'Sales', 'sales', 'upsert', [['id', 'order_id', 'string', true], ['amount', 'amount', 'integer']]);

        $this->sync();
        $this->sync();
        $this->assertSame(2, DB::table('sales')->count());

        $this->sheets->put('file-1', ['Sales' => [['id', 'amount'], ['1', 50], ['3', 7]]]);
        $this->sync();

        $this->assertSame(3, DB::table('sales')->count());
        $this->assertSame(50, (int) DB::table('sales')->where('order_id', '1')->value('amount'));
    }

    public function test_switching_a_mapping_to_upsert_does_not_leave_duplicates(): void
    {
        $this->sheets->put('file-1', ['Sales' => [['id', 'amount'], ['1', 5], ['2', 6]]]);
        $mapping = $this->mapping('file-1', 'Sales', 'sales', 'replace', [['id', 'order_id', 'string', true], ['amount', 'amount', 'integer']]);
        $this->sync();

        $mapping->update(['sync_mode' => 'upsert']);
        $this->sync();
        $this->sync();

        $this->assertSame(2, DB::table('sales')->count());
        $this->assertSame(0, DB::table('sales')->whereNull('row_key')->count());
    }

    public function test_adjusted_values_are_reported_separately_from_invalid_ones(): void
    {
        $this->sheets->put('file-1', ['Sales' => [['id', 'amount'], ['1', 12.7], ['2', 'n/a']]]);
        $this->mapping('file-1', 'Sales', 'sales', 'upsert', [['id', 'order_id', 'string', true], ['amount', 'amount', 'integer']]);

        $run = $this->sync();
        $warnings = implode(' ', $run->items->first()->warnings);

        $this->assertDatabaseHas('sales', ['order_id' => '1', 'amount' => 13]);
        $this->assertDatabaseHas('sales', ['order_id' => '2', 'amount' => null]);
        $this->assertStringContainsString('1 value(s) were truncated or rounded', $warnings);
        $this->assertStringContainsString('1 value(s) could not be converted', $warnings);
    }

    public function test_a_column_missing_from_the_sheet_keeps_stored_values_on_upsert(): void
    {
        $this->sheets->put('file-1', ['Sales' => [['id', 'amount', 'note'], ['1', 5, 'first']]]);
        $this->mapping('file-1', 'Sales', 'sales', 'upsert', [['id', 'order_id', 'string', true], ['amount', 'amount', 'integer'], ['note', 'note', 'string']]);
        $this->sync();

        $this->sheets->put('file-1', ['Sales' => [['id', 'amount'], ['1', 9]]]);
        $run = $this->sync();

        $this->assertDatabaseHas('sales', ['order_id' => '1', 'amount' => 9, 'note' => 'first']);
        $this->assertStringContainsString('"note" was not found', implode(' ', $run->items->first()->warnings));
    }

    public function test_replace_only_removes_rows_written_by_the_same_mapping(): void
    {
        $this->sheets->put('file-1', ['Sales' => [['product'], ['Pen'], ['Glue']]]);
        $this->sheets->put('file-2', ['Sales' => [['product'], ['Stapler']]]);
        $this->mapping('file-1', 'Sales', 'sales', 'replace', [['product', 'name', 'string']]);
        $this->mapping('file-2', 'Sales', 'sales', 'replace', [['product', 'name', 'string']]);
        $this->sync();

        $this->sheets->put('file-1', ['Sales' => [['product'], ['Marker']]]);
        $this->sync();

        $this->assertEqualsCanonicalizing(['Marker', 'Stapler'], DB::table('sales')->pluck('name')->all());
    }

    public function test_snapshot_keeps_one_copy_per_day(): void
    {
        $this->sheets->put('file-1', ['Stock' => [['sku', 'qty'], ['P1', 3]]]);
        $this->mapping('file-1', 'Stock', 'stock_history', 'snapshot', [['sku', 'sku', 'string'], ['qty', 'qty', 'integer']]);

        $this->travelTo('2026-10-14 02:00');
        $this->sync();
        $this->sync(); // same day: replaced, not duplicated

        $this->travelTo('2026-10-15 02:00');
        $this->sync();

        $this->assertSame(['2026-10-14', '2026-10-15'], DB::table('stock_history')->orderBy('sync_date')->pluck('sync_date')->map(fn ($d) => substr($d, 0, 10))->all());
    }

    public function test_a_failing_mapping_does_not_stop_the_others(): void
    {
        $this->sheets->put('file-1', ['Sales' => [['id'], ['1']]]);
        $this->mapping('file-1', 'Sales', 'users', 'replace', [['id', 'external_id', 'string']]);
        $tab = SheetTab::first();
        $this->mapping('file-1', 'Sales', 'sales', 'replace', [['id', 'external_id', 'string']], $tab);

        $run = $this->sync();

        $this->assertSame(SyncStatus::Partial, $run->status);
        $this->assertStringContainsString('reserved', $run->items->firstWhere('target_table', 'users')->error);
        $this->assertDatabaseHas('sales', ['external_id' => '1']);
        $this->assertFalse(Schema::hasColumn('users', 'external_id'));
    }

    public function test_it_refuses_to_write_into_a_table_it_did_not_create(): void
    {
        Schema::create('legacy_orders', fn ($t) => $t->id());
        $this->sheets->put('file-1', ['Sales' => [['id'], ['1']]]);
        $this->mapping('file-1', 'Sales', 'legacy_orders', 'replace', [['id', 'external_id', 'string']]);

        $run = $this->sync();

        $this->assertSame(SyncStatus::Failed, $run->status);
        $this->assertStringContainsString('not managed by the sync', $run->items->first()->error);
    }

    public function test_an_unreadable_spreadsheet_is_recorded_as_failed(): void
    {
        $this->mapping('missing-file', 'Sales', 'sales', 'replace', [['id', 'external_id', 'string']]);

        $run = $this->sync();

        $this->assertSame(SyncStatus::Failed, $run->status);
        $this->assertStringContainsString('not found', $run->items->first()->error);
    }

    public function test_the_header_row_is_configurable(): void
    {
        $this->sheets->put('file-1', ['Sales' => [['Report title'], [], ['product', 'qty'], ['Pen', 2]]]);
        $mapping = $this->mapping('file-1', 'Sales', 'sales', 'replace', [['product', 'name', 'string'], ['qty', 'qty', 'integer']]);
        $mapping->sheetTab->update(['header_row' => 3]);

        $this->sync();

        $this->assertDatabaseHas('sales', ['name' => 'Pen', 'qty' => 2]);
        $this->assertSame(1, DB::table('sales')->count());
    }

    public function test_the_artisan_command_runs_the_sync(): void
    {
        $this->sheets->put('file-1', ['Sales' => [['product'], ['Pen']]]);
        $this->mapping('file-1', 'Sales', 'sales', 'replace', [['product', 'name', 'string']]);

        $this->artisan('sheets:sync')->assertSuccessful();

        $this->assertDatabaseHas('sales', ['name' => 'Pen']);
    }

    private function sync()
    {
        return app(SheetSyncService::class)->run('test')->load('items');
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3?: bool}>  $columns
     */
    private function mapping(string $spreadsheetId, string $tabName, string $table, string $mode, array $columns, ?SheetTab $tab = null): TableMapping
    {
        $tab ??= Spreadsheet::firstOrCreate(['google_spreadsheet_id' => $spreadsheetId], ['name' => $spreadsheetId])
            ->tabs()->firstOrCreate(['tab_name' => $tabName]);

        $mapping = $tab->tableMappings()->create(['target_table' => $table, 'sync_mode' => $mode]);

        foreach ($columns as $c) {
            $mapping->columnMappings()->create([
                'source_column' => $c[0],
                'target_column' => $c[1],
                'data_type' => $c[2],
                'is_unique_key' => $c[3] ?? false,
            ]);
        }

        return $mapping;
    }
}
