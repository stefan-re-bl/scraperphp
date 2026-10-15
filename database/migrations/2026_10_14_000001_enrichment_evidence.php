<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            $table->string('source_reference', 2048)->nullable();
            $table->string('extraction_method', 80)->nullable();
            $table->double('confidence')->default(0.5);
            $table->timestamp('expires_at')->nullable()->index();
            $table->string('rule_version', 80)->nullable();
            $table->json('metadata')->nullable();
            $table->string('evidence_key', 64)->nullable();
            $table->boolean('is_current')->default(true);
            $table->index(['lead_id', 'evidence_key']);
            $table->index(['lead_id', 'is_current']);
        });
        DB::table('signals')->where('source', 'manual')->update(['extraction_method' => 'human_review', 'confidence' => 1]);
        DB::table('signals')->where('source', '!=', 'manual')->update(['rule_version' => 'legacy', 'confidence' => 0.5, 'is_current' => false]);
        Schema::create('enrichment_source_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('source');
            $table->string('input_sha256', 64);
            $table->timestamp('last_checked_at');
            $table->timestamp('next_check_at');
            $table->json('result')->nullable();
            $table->unique(['lead_id', 'source']);
        });
        Schema::create('enrichment_request_states', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->string('lease_token', 36)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('payload')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrichment_request_states');
        Schema::dropIfExists('enrichment_source_states');
        Schema::table('signals', function (Blueprint $table) {
            $table->dropIndex(['lead_id', 'evidence_key']);
            $table->dropIndex(['lead_id', 'is_current']);
            $table->dropIndex(['expires_at']);
            $table->dropColumn(['source_reference', 'extraction_method', 'confidence', 'expires_at', 'rule_version', 'metadata', 'evidence_key', 'is_current']);
        });
    }
};
