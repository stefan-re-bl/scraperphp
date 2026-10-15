<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lead_merges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('source_lead_id')->unique();
            $table->foreignId('target_lead_id')->constrained('leads')->restrictOnDelete();
            $table->string('plan_sha256', 64);
            $table->json('original_records');
            $table->json('decisions');
            $table->string('backup_path');
            $table->string('backup_sha256', 64);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_merges');
    }
};
