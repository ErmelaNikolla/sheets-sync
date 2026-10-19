<?php

namespace Tests\Feature;

use App\Filament\Resources\TableMappings\Pages\CreateTableMapping;
use App\Models\SheetTab;
use App\Models\Spreadsheet;
use App\Models\SyncRun;
use App\Models\TableMapping;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);
        $this->artisan('sheets:sync');
        $this->actingAs(User::factory()->create());
    }

    public function test_every_page_renders(): void
    {
        $spreadsheet = Spreadsheet::first();
        $mapping = TableMapping::first();
        $run = SyncRun::first();

        foreach ([
            '/admin',
            '/admin/spreadsheets',
            '/admin/spreadsheets/create',
            "/admin/spreadsheets/{$spreadsheet->id}/edit",
            '/admin/table-mappings',
            '/admin/table-mappings/create',
            "/admin/table-mappings/{$mapping->id}/edit",
            '/admin/sync-runs',
            "/admin/sync-runs/{$run->id}",
            '/admin/data-browser',
            '/admin/data-browser?source=sales',
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_guests_are_redirected_to_login(): void
    {
        auth()->logout();

        $this->get('/admin/spreadsheets')->assertRedirect('/admin/login');
    }

    public function test_a_mapping_with_an_unsafe_table_name_is_rejected(): void
    {
        Livewire::test(CreateTableMapping::class)
            ->fillForm([
                'sheet_tab_id' => SheetTab::first()->id,
                'target_table' => 'users',
                'sync_mode' => 'replace',
            ])
            ->call('create')
            ->assertHasFormErrors(['target_table']);
    }

    public function test_upsert_without_unique_key_is_rejected(): void
    {
        Livewire::test(CreateTableMapping::class)
            ->fillForm([
                'sheet_tab_id' => SheetTab::first()->id,
                'target_table' => 'orders',
                'sync_mode' => 'upsert',
                'columnMappings' => [
                    ['source_column' => 'price', 'target_column' => 'price', 'data_type' => 'decimal', 'is_unique_key' => false],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['columnMappings']);
    }
}
