<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->string('segment')->nullable()->after('target_customer');
            $table->text('assets')->nullable()->after('compatibility_signals');
            $table->json('rules')->nullable()->after('suggested_cta');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('city')->nullable()->after('country');
            $table->string('mode')->default('manual')->after('channel');
            $table->unsignedInteger('scrape_limit')->default(50)->after('daily_limit');
            $table->unsignedInteger('follow_up_1_delay_days')->default(3)->after('scrape_limit');
            $table->unsignedInteger('follow_up_2_delay_days')->default(7)->after('follow_up_1_delay_days');
            $table->string('contact_window_start')->nullable()->after('follow_up_2_delay_days');
            $table->string('contact_window_end')->nullable()->after('contact_window_start');
        });

        Schema::table('import_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('campaign_query_id')->nullable()->after('campaign_id');
            $table->unsignedBigInteger('scrape_run_id')->nullable()->after('campaign_query_id');
            $table->json('raw_metadata')->nullable()->after('file_path');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->json('social_urls')->nullable()->after('phone');
            $table->json('contact_methods')->nullable()->after('social_urls');
            $table->string('primary_cta')->nullable()->after('contact_methods');
            $table->boolean('booking_visible')->default(false)->after('primary_cta');
            $table->timestamp('enriched_at')->nullable()->after('acquired_at');
            $table->timestamp('excluded_at')->nullable()->after('enriched_at');
            $table->string('exclusion_reason')->nullable()->after('excluded_at');
        });

        Schema::table('signals', function (Blueprint $table) {
            $table->string('source')->default('system')->after('value');
        });

        Schema::table('contact_attempts', function (Blueprint $table) {
            $table->unsignedBigInteger('message_template_id')->nullable()->after('campaign_id');
            $table->unsignedBigInteger('experiment_variant_id')->nullable()->after('message_template_id');
            $table->string('subject')->nullable()->after('channel');
            $table->boolean('dry_run')->default(true)->after('manual_intervention');
            $table->timestamp('due_at')->nullable()->after('performed_at');
        });

        Schema::table('replies', function (Blueprint $table) {
            $table->string('objection_type')->nullable()->after('category');
            $table->text('objection_notes')->nullable()->after('body');
        });

        Schema::create('campaign_queries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('query');
            $table->string('location')->nullable();
            $table->unsignedInteger('result_limit')->default(50);
            $table->string('status')->default('draft');
            $table->timestamps();
        });

        Schema::create('scrape_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_query_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source')->default('google_maps');
            $table->string('query');
            $table->string('location')->nullable();
            $table->unsignedInteger('result_limit')->default(50);
            $table->string('status')->default('pending');
            $table->boolean('dry_run')->default(true);
            $table->unsignedInteger('timeout_seconds')->default(300);
            $table->string('output_file')->nullable();
            $table->text('command')->nullable();
            $table->integer('exit_code')->nullable();
            $table->text('stdout')->nullable();
            $table->text('stderr')->nullable();
            $table->unsignedInteger('raw_count')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('language')->default('es');
            $table->string('channel')->default('email');
            $table->string('segment')->nullable();
            $table->string('stage')->default('initial');
            $table->string('subject')->nullable();
            $table->text('body');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('experiments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('variable');
            $table->string('status')->default('draft');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        Schema::create('experiment_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('experiment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('content')->nullable();
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('reply_count')->default(0);
            $table->unsignedInteger('positive_reply_count')->default(0);
            $table->unsignedInteger('interest_count')->default(0);
            $table->unsignedInteger('proposal_count')->default(0);
            $table->unsignedInteger('won_count')->default(0);
            $table->timestamps();
        });

        Schema::create('lead_experiment_variant', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('experiment_variant_id')->constrained()->cascadeOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamps();
            $table->unique(['lead_id', 'experiment_variant_id']);
        });

        Schema::create('scoring_rules', function (Blueprint $table) {
            $table->id();
            $table->string('component');
            $table->integer('weight')->default(1);
            $table->integer('points')->default(0);
            $table->boolean('active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('llm_bridge_runs', function (Blueprint $table) {
            $table->id();
            $table->string('use_case');
            $table->string('status')->default('draft');
            $table->text('prompt')->nullable();
            $table->json('lead_ids')->nullable();
            $table->text('imported_json')->nullable();
            $table->text('validation_errors')->nullable();
            $table->timestamps();
        });

        Schema::create('llm_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('llm_bridge_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->json('payload');
            $table->string('status')->default('AI_SUGGESTION');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_suggestions');
        Schema::dropIfExists('llm_bridge_runs');
        Schema::dropIfExists('scoring_rules');
        Schema::dropIfExists('lead_experiment_variant');
        Schema::dropIfExists('experiment_variants');
        Schema::dropIfExists('experiments');
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('scrape_runs');
        Schema::dropIfExists('campaign_queries');
    }
};
