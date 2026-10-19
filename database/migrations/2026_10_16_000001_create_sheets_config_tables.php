<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spreadsheets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('google_spreadsheet_id')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sheet_tabs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spreadsheet_id')->constrained()->cascadeOnDelete();
            $table->string('tab_name');
            $table->unsignedInteger('header_row')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['spreadsheet_id', 'tab_name']);
        });

        Schema::create('table_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sheet_tab_id')->constrained()->cascadeOnDelete();
            $table->string('target_table', 64);
            $table->string('sync_mode', 16)->default('upsert');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('column_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('table_mapping_id')->constrained()->cascadeOnDelete();
            $table->string('source_column');
            $table->string('target_column', 64);
            $table->string('data_type', 16)->default('string');
            $table->boolean('is_unique_key')->default(false);
            $table->string('date_format', 32)->nullable();
            $table->timestamps();

            $table->unique(['table_mapping_id', 'target_column']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('column_mappings');
        Schema::dropIfExists('table_mappings');
        Schema::dropIfExists('sheet_tabs');
        Schema::dropIfExists('spreadsheets');
    }
};
