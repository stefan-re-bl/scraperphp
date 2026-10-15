<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scrape_runs', function (Blueprint $table): void {
            $table->uuid('claim_token')->nullable();
        });
        Schema::create('import_row_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('errors');
            $table->timestamps();
            $table->unique(['import_run_id', 'row_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_row_issues');
        Schema::table('scrape_runs', fn (Blueprint $table) => $table->dropColumn('claim_token'));
    }
};
