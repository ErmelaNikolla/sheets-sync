<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('trigger', 16);
            $table->string('status', 16)->default('running');
            $table->unsignedInteger('rows_read')->default(0);
            $table->unsignedInteger('rows_written')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        // One row per table mapping processed in a run, so a failing tab is
        // recorded without hiding the results of the others.
        Schema::create('sync_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('table_mapping_id')->nullable()->constrained()->nullOnDelete();
            // Denormalised labels, so history stays readable after config changes.
            $table->string('spreadsheet_name')->nullable();
            $table->string('tab_name')->nullable();
            $table->string('target_table')->nullable();
            $table->string('status', 16);
            $table->unsignedInteger('rows_read')->default(0);
            $table->unsignedInteger('rows_written')->default(0);
            $table->json('warnings')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_run_items');
        Schema::dropIfExists('sync_runs');
    }
};
