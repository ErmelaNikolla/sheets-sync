<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sheets driver
    |--------------------------------------------------------------------------
    |
    | "google" reads real spreadsheets through the Google Sheets API using a
    | service account. "fake" reads JSON fixtures from database/fixtures/sheets
    | so the project can be demoed and tested without Google credentials.
    |
    */

    'driver' => env('SHEETS_DRIVER', 'google'),

    // Absolute path, or relative to the project root, of the service account JSON key.
    'credentials_path' => env('GOOGLE_APPLICATION_CREDENTIALS', storage_path('app/google/service-account.json')),

    'fake_path' => env('SHEETS_FAKE_PATH', database_path('fixtures/sheets')),

    // Daily run time for the scheduler (24h, server timezone).
    'schedule_at' => env('SHEETS_SYNC_AT', '02:00'),

    // Rows per INSERT / UPSERT statement.
    'chunk_size' => (int) env('SHEETS_CHUNK_SIZE', 500),

    /*
    | Tables the sync is never allowed to create or write into, even if a
    | mapping names them. Application tables are listed here on top of the
    | "managed table" check done by the SchemaManager.
    */
    'reserved_tables' => [
        'users', 'password_reset_tokens', 'sessions',
        'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'migrations',
        'spreadsheets', 'sheet_tabs', 'table_mappings', 'column_mappings',
        'sync_runs', 'sync_run_items',
    ],

];
