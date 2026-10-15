<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('campaign_lead', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('NEW');
            $table->timestamp('next_action_at')->nullable()->index();
            $table->timestamp('interested_at')->nullable();
            $table->timestamp('won_at')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'lead_id']);
        });
        Schema::table('leads', function (Blueprint $table) {
            $table->string('city')->nullable()->index();
            $table->string('contact_person')->nullable();
            $table->string('contact_role')->nullable();
            $table->string('relationship_type')->default('prospect');
            $table->json('tags')->nullable();
            $table->timestamp('next_action_at')->nullable()->index();
            $table->integer('data_quality_score')->default(0);
            $table->integer('contactability_score')->default(0);
            $table->integer('offer_fit_score')->default(0);
            $table->text('offer_match_reason')->nullable();
        });
        foreach (['replies', 'proposals', 'lead_status_histories'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            });
        }
        Schema::table('contact_attempts', function (Blueprint $table) {
            $table->foreignId('offer_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::table('proposals', function (Blueprint $table) {
            $table->foreignId('offer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('group_key')->nullable()->index();
        });
        Schema::table('signals', function (Blueprint $table) {
            $table->string('evidence_kind')->default('observed');
            $table->string('review_status')->default('confirmed');
            $table->string('evidence_url', 2048)->nullable();
        });
        Schema::table('scoring_rules', function (Blueprint $table) {
            $table->foreignId('offer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('dimension')->default('fit');
        });
        Schema::table('scrape_runs', function (Blueprint $table) {
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('phase')->default('pending');
            $table->json('arguments')->nullable();
            $table->timestamp('claimed_at')->nullable();
        });
        Schema::table('import_runs', function (Blueprint $table) {
            $table->string('status')->default('completed');
            $table->text('error_message')->nullable();
            $table->json('mapping')->nullable();
            $table->unsignedInteger('excluded_count')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->string('file_hash', 64)->nullable();
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claimed_at')->nullable();
        });
        Schema::create('saved_lead_views', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('scope')->default('global');
            $table->json('filters');
            $table->timestamps();
            $table->unique(['name', 'scope']);
        });
        Schema::create('application_preferences', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value');
        });

        DB::table('contact_attempts')->where('manual_intervention', true)->where('status', 'sent')->whereNotNull('performed_at')->update(['dry_run' => false]);
        DB::table('signals')->where('source', '!=', 'manual')->whereIn('type', ['manual_booking_dependency', 'manual_contact_dependency', 'no_visible_booking', 'weak_website_content', 'website_unreachable'])
            ->update(['evidence_kind' => 'inferred', 'review_status' => 'pending']);

        $memberships = DB::table('lead_sources')->join('import_runs', 'lead_sources.import_run_id', '=', 'import_runs.id')
            ->whereNotNull('import_runs.campaign_id')->select('lead_sources.lead_id', 'import_runs.campaign_id')->get()
            ->merge(DB::table('contact_attempts')->select('lead_id', 'campaign_id')->get());
        foreach ($memberships as $membership) {
            $lead = DB::table('leads')->find($membership->lead_id);
            DB::table('campaign_lead')->insertOrIgnore([
                'campaign_id' => $membership->campaign_id, 'lead_id' => $membership->lead_id,
                'offer_id' => DB::table('campaigns')->where('id', $membership->campaign_id)->value('offer_id'),
                'status' => 'ENRICHED', 'created_at' => now(), 'updated_at' => now(),

            ]);
        }
        foreach (DB::table('replies')->whereNotNull('contact_attempt_id')->get() as $reply) {
            DB::table('replies')->where('id', $reply->id)->update(['campaign_id' => DB::table('contact_attempts')->where('id', $reply->contact_attempt_id)->value('campaign_id')]);
        }
        foreach (DB::table('leads')->get() as $lead) {
            $campaignIds = DB::table('campaign_lead')->where('lead_id', $lead->id)->pluck('campaign_id');
            if ($campaignIds->count() === 1) {
                DB::table('proposals')->where('lead_id', $lead->id)->update(['campaign_id' => $campaignIds->first()]);
                DB::table('lead_status_histories')->where('lead_id', $lead->id)->update(['campaign_id' => $campaignIds->first()]);
                DB::table('replies')->where('lead_id', $lead->id)->whereNull('campaign_id')->update(['campaign_id' => $campaignIds->first()]);
                DB::table('leads')->where('id', $lead->id)->update(['city' => DB::table('campaigns')->where('id', $campaignIds->first())->value('city')]);
            }
        }
        foreach (DB::table('campaign_lead')->get() as $membership) {
            $lead = DB::table('leads')->find($membership->lead_id);
            $single = DB::table('campaign_lead')->where('lead_id', $lead->id)->count() === 1;
            $attempts = DB::table('contact_attempts')->where('lead_id', $lead->id)->where('campaign_id', $membership->campaign_id);
            $reply = DB::table('replies')->where('lead_id', $lead->id)->where('campaign_id', $membership->campaign_id)->orderByDesc('received_at')->first();
            $status = $single ? $lead->status : ($reply ? match ($reply->category) {
                'won' => 'WON', 'lost', 'negative' => 'LOST', 'proposal' => 'PROPOSAL',
                'interested', 'meeting', 'information_request', 'price_question' => 'INTERESTED', default => 'REPLIED',
            } : ((clone $attempts)->where('status', 'sent')->where('dry_run', false)->exists() ? 'CONTACTED' : 'ENRICHED'));
            if ($lead->excluded_at) {
                $status = 'PAUSED';
            }
            DB::table('campaign_lead')->where('id', $membership->id)->update([
                'status' => $status, 'next_action_at' => (clone $attempts)->where('status', 'planned')->min('due_at'),
                'interested_at' => in_array($status, ['INTERESTED', 'PROPOSAL', 'NEGOTIATION', 'WON']) ? $lead->updated_at : null,
                'won_at' => $status === 'WON' ? $lead->updated_at : null, 'lost_at' => $status === 'LOST' ? $lead->updated_at : null,
            ]);
        }
        foreach (DB::table('contact_attempts')->get() as $attempt) {
            DB::table('contact_attempts')->where('id', $attempt->id)->update(['offer_id' => DB::table('campaigns')->where('id', $attempt->campaign_id)->value('offer_id') ?? DB::table('leads')->where('id', $attempt->lead_id)->value('suggested_offer_id')]);
        }
        DB::table('scoring_rules')->where('component', 'offer_fit_health')->update(['active' => false]);
        DB::table('scoring_rules')->where('component', 'like', 'contact_quality_%')->update(['dimension' => 'contact']);
        DB::table('scoring_rules')->whereIn('component', ['data_quality_website', 'source_identifier_available'])->update(['dimension' => 'data']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contact_attempts', fn (Blueprint $table) => $table->dropConstrainedForeignId('offer_id'));
        Schema::dropIfExists('application_preferences');
        Schema::dropIfExists('saved_lead_views');
        Schema::dropIfExists('campaign_lead');
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['city']);
            $table->dropIndex(['next_action_at']);
            $table->dropColumn(['city', 'contact_person', 'contact_role', 'relationship_type', 'tags', 'next_action_at', 'data_quality_score', 'contactability_score', 'offer_fit_score', 'offer_match_reason']);
        });
        foreach (['replies', 'proposals', 'lead_status_histories'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('campaign_id'));
        }
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('offer_id');
            $table->dropIndex(['group_key']);
            $table->dropColumn('group_key');
        });
        Schema::table('scoring_rules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('offer_id');
            $table->dropColumn('dimension');
        });
        Schema::table('signals', fn (Blueprint $table) => $table->dropColumn(['evidence_kind', 'review_status', 'evidence_url']));
        Schema::table('scrape_runs', fn (Blueprint $table) => $table->dropColumn(['heartbeat_at', 'cancel_requested_at', 'attempts', 'phase', 'arguments', 'claimed_at']));
        Schema::table('import_runs', fn (Blueprint $table) => $table->dropColumn(['status', 'error_message', 'mapping', 'excluded_count', 'processed_rows', 'file_hash', 'claim_token', 'claimed_at']));
    }
};
