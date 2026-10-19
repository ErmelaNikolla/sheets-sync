<?php

namespace App\Providers;

use App\Services\GoogleSheets\FakeSheetsClient;
use App\Services\GoogleSheets\GoogleSheetsClient;
use App\Services\GoogleSheets\SheetsClient;
use App\Services\Sync\RowWriter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SheetsClient::class, function () {
            return match (config('sheets.driver')) {
                'fake' => new FakeSheetsClient(config('sheets.fake_path')),
                default => new GoogleSheetsClient(self::absolutePath(config('sheets.credentials_path'))),
            };
        });

        $this->app->bind(RowWriter::class, fn () => new RowWriter(config('sheets.chunk_size')));
    }

    public function boot(): void
    {
        //
    }

    private static function absolutePath(string $path): string
    {
        return preg_match('~^([a-zA-Z]:)?[/\\\\]~', $path) ? $path : base_path($path);
    }
}
