<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('target_customer')->nullable();
            $table->text('pains')->nullable();
            $table->text('benefits')->nullable();
            $table->text('compatibility_signals')->nullable();
            $table->string('demo_url')->nullable();
            $table->string('portfolio_url')->nullable();
            $table->string('suggested_cta')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('segment')->nullable();
            $table->string('country')->nullable();
            $table->string('language')->default('es');
            $table->string('source')->default('google_maps');
            $table->string('channel')->default('email');
            $table->string('cta')->nullable();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->unsignedInteger('daily_limit')->default(20);
            $table->boolean('dry_run')->default(true);
            $table->boolean('kill_switch')->default(false);
            $table->string('status')->default('draft');
            $table->timestamps();
        });

        Schema::create('import_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source')->default('google_maps');
            $table->string('query')->nullable();
            $table->string('segment')->nullable();
            $table->string('location')->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedInteger('raw_count')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('suggested_offer_id')->nullable()->constrained('offers')->nullOnDelete();
            $table->string('name');
            $table->string('category')->nullable();
            $table->string('location')->nullable();
            $table->string('country')->nullable();
            $table->string('language')->default('es');
            $table->string('website_url')->nullable();
            $table->string('domain')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('source')->default('google_maps');
            $table->string('source_identifier')->nullable();
            $table->string('segment')->nullable();
            $table->timestamp('acquired_at')->nullable();
            $table->string('status')->default('NEW');
            $table->integer('score_total')->default(0);
            $table->json('signals')->nullable();
            $table->text('notes')->nullable();
            $table->string('data_quality')->default('partial');
            $table->timestamps();
            $table->index(['status', 'score_total']);
            $table->index('domain');
            $table->index('email');
            $table->index('phone');
            $table->index('source_identifier');
        });

        Schema::create('lead_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('import_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source')->default('google_maps');
            $table->string('source_identifier')->nullable();
            $table->string('source_url')->nullable();
            $table->string('query')->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('lead_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('reason')->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();
        });

        Schema::create('signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('value')->nullable();
            $table->text('evidence')->nullable();
            $table->timestamp('detected_at');
            $table->timestamps();
        });

        Schema::create('score_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('component');
            $table->integer('weight')->default(1);
            $table->string('value')->nullable();
            $table->integer('points');
            $table->text('evidence')->nullable();
            $table->timestamps();
        });

        Schema::create('contact_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('variant')->nullable();
            $table->string('channel');
            $table->string('step')->default('initial');
            $table->timestamp('planned_at')->nullable();
            $table->timestamp('performed_at')->nullable();
            $table->string('status')->default('planned');
            $table->boolean('manual_intervention')->default(true);
            $table->text('message_snapshot')->nullable();
            $table->string('result')->nullable();
            $table->timestamps();
            $table->index(['lead_id', 'status']);
        });

        Schema::create('replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category');
            $table->text('body')->nullable();
            $table->timestamp('received_at');
            $table->boolean('requires_human')->default(true);
            $table->timestamps();
        });

        Schema::create('proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('service');
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('sent_at')->nullable();
            $table->text('response')->nullable();
            $table->text('objections')->nullable();
            $table->text('modifications')->nullable();
            $table->string('result')->nullable();
            $table->timestamps();
        });

        Schema::create('exclusions', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('value');
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->unique(['type', 'value']);
        });

        Schema::create('system_logs', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_logs');
        Schema::dropIfExists('exclusions');
        Schema::dropIfExists('proposals');
        Schema::dropIfExists('replies');
        Schema::dropIfExists('contact_attempts');
        Schema::dropIfExists('score_components');
        Schema::dropIfExists('signals');
        Schema::dropIfExists('lead_status_histories');
        Schema::dropIfExists('lead_sources');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('import_runs');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('offers');
    }
};
