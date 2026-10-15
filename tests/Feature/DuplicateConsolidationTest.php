<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\ClientService;
use App\Models\ContactAttempt;
use App\Models\Experiment;
use App\Models\ExperimentVariant;
use App\Models\Lead;
use App\Models\LeadContact;
use App\Models\LlmBridgeRun;
use App\Models\Offer;
use App\Models\Proposal;
use App\Models\Reply;
use App\Models\SavedLeadView;
use App\Models\SystemLog;
use App\Services\BackupService;
use App\Services\CampaignMembership;
use App\Services\ContactWorkflow;
use App\Services\LeadMergeService;
use App\Services\LeadScorer;
use App\Services\MetricsService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DuplicateConsolidationTest extends TestCase
{
    use DatabaseMigrations;

    private function decisions(array $plan): array
    {
        return ['fields' => array_fill_keys(array_keys($plan['fields']), 'target'), 'campaigns' => array_fill_keys(array_keys($plan['campaigns']), 'target'),
            'sequences' => array_fill_keys(array_keys($plan['sequences']), 'pause_all'), 'proposals' => array_fill_keys(array_keys($plan['proposals']), 'new_group'),
            'assignments' => collect($plan['assignments'])->map(fn ($rows) => (string) $rows[0]['experiment_variant_id'])->all(), 'primary_contact' => 'target'];
    }

    public function test_preview_shows_conflicts_without_writing_and_requires_each_resolution(): void
    {
        $target = Lead::factory()->create(['name' => 'Empresa principal']);
        $source = Lead::factory()->create(['name' => 'Empresa duplicada', 'duplicate_status' => 'possible_duplicate', 'possible_duplicate_of_lead_id' => $target->id]);
        $plan = app(LeadMergeService::class)->preview($target, $source);
        $this->get('/leads/'.$source->id.'/duplicates')->assertOk()->assertSee('Empresa principal')->assertSee('Empresa duplicada')->assertSee('Resolver diferencia');
        $this->assertDatabaseCount('leads', 2);
        $this->assertDatabaseCount('lead_merges', 0);
        $this->post('/duplicates/merge', ['target_id' => $target->id, 'source_id' => $source->id, 'plan_sha256' => $plan['plan_sha256'], 'confirmation' => 'CONSOLIDAR', 'primary_contact' => 'target'])->assertSessionHasErrors('fields.name');
        $this->assertDatabaseCount('leads', 2);
    }

    public function test_merge_preserves_all_commercial_relations_and_original_conflicts(): void
    {
        $target = Lead::factory()->create(['name' => 'Empresa', 'entity_type' => 'organization', 'notes' => 'Nota principal']);
        $source = Lead::factory()->create(['name' => 'Empresa duplicada', 'entity_type' => 'organization', 'is_customer' => true, 'notes' => 'Nota secundaria', 'source_identifier' => 'source-id', 'duplicate_status' => 'possible_duplicate', 'possible_duplicate_of_lead_id' => $target->id]);
        $campaign = Campaign::factory()->create();
        app(CampaignMembership::class)->attach($target, $campaign, true);
        app(CampaignMembership::class)->attach($source, $campaign, true);
        DB::table('campaign_lead')->where('lead_id', $source->id)->update(['status' => 'INTERESTED']);
        $target->sources()->create(['source' => 'csv_manual', 'source_identifier' => 'target']);
        $source->sources()->create(['source' => 'notion', 'source_identifier' => 'source', 'import_key' => 'notion:source']);
        LeadContact::factory()->for($target)->create(['name' => 'Principal', 'import_key' => 'shared-contact', 'is_primary' => true]);
        LeadContact::factory()->for($source)->create(['name' => 'Secundario', 'import_key' => 'shared-contact', 'is_primary' => true]);
        $proposal = Proposal::create(['lead_id' => $target->id, 'campaign_id' => $campaign->id, 'service' => 'Web', 'version' => 1, 'group_key' => 'shared-group', 'currency' => 'ARS']);
        $otherProposal = Proposal::create(['lead_id' => $source->id, 'campaign_id' => $campaign->id, 'service' => 'Web adicional', 'version' => 1, 'group_key' => 'shared-group', 'currency' => 'ARS']);
        ClientService::factory()->for($source)->create(['proposal_id' => $otherProposal->id]);
        $source->statusHistories()->create(['campaign_id' => $campaign->id, 'to_status' => 'INTERESTED', 'reason' => 'respuesta', 'changed_at' => now()]);
        foreach ([$target, $source] as $lead) {
            ContactAttempt::create(['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'channel' => 'email', 'step' => 'follow_up_1', 'status' => 'planned', 'dry_run' => false, 'due_at' => now()]);
        }
        $sent = ContactAttempt::create(['lead_id' => $source->id, 'campaign_id' => $campaign->id, 'channel' => 'email', 'step' => 'initial', 'status' => 'sent', 'dry_run' => false, 'performed_at' => now()]);
        Reply::create(['lead_id' => $source->id, 'contact_attempt_id' => $sent->id, 'campaign_id' => $campaign->id, 'category' => 'interested', 'received_at' => now()]);
        $experiment = Experiment::create(['campaign_id' => $campaign->id, 'name' => 'A/B', 'variable' => 'opening', 'status' => 'active']);
        foreach ([$target, $source] as $i => $lead) {
            $variant = ExperimentVariant::create(['experiment_id' => $experiment->id, 'name' => 'Variante '.$i]);
            DB::table('lead_experiment_variant')->insert(['lead_id' => $lead->id, 'experiment_variant_id' => $variant->id, 'assigned_at' => now()]);
        }
        LlmBridgeRun::create(['use_case' => 'pain', 'lead_ids' => [$source->id], 'status' => 'prompt_ready']);
        SystemLog::create(['type' => 'lead_edit', 'message' => 'Cambio sintético', 'context' => ['lead_id' => $source->id]]);
        $saved = SavedLeadView::factory()->create(['filters' => ['ids' => [$source->id]]]);
        $third = Lead::factory()->create(['duplicate_status' => 'possible_duplicate', 'possible_duplicate_of_lead_id' => $source->id]);
        $service = app(LeadMergeService::class);
        $plan = $service->preview($target, $source);
        $result = $service->merge($target->id, $source->id, $plan['plan_sha256'], $this->decisions($plan));
        $this->assertDatabaseMissing('leads', ['id' => $source->id]);
        $this->assertDatabaseCount('lead_merges', 1);
        $this->assertCount(3, $result->sources);
        $this->assertCount(4, $result->contacts);
        $this->assertSame(1, $result->contacts()->where('is_primary', true)->count());
        $this->assertCount(1, $result->services);
        $this->assertCount(3, $result->contactAttempts);
        $this->assertSame(2, $result->contactAttempts()->where('status', 'paused')->count());
        $this->assertCount(1, $result->replies);
        $this->assertCount(2, $result->proposals);
        $this->assertNotSame($proposal->group_key, $otherProposal->fresh()->group_key);
        $this->assertCount(1, $result->campaigns);
        $this->assertStringContainsString('Nota principal', $result->notes);
        $this->assertStringContainsString('Nota secundaria', $result->notes);
        $this->assertTrue($result->is_customer);
        $this->assertSame($target->id, $third->fresh()->possible_duplicate_of_lead_id);
        $this->assertSame([$target->id], $saved->fresh()->filters['ids']);
        $this->assertDatabaseHas('system_logs', ['context->lead_id' => $target->id]);
        $audit = DB::table('lead_merges')->first();
        $this->assertFileExists($audit->backup_path);
        $this->assertSame($audit->backup_sha256, hash_file('sha256', $audit->backup_path));
        $this->assertCount(2, json_decode($audit->original_records, true)['leads']);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertSame($result->id, $service->merge($target->id, $source->id, $plan['plan_sha256'], $this->decisions($plan))->id);
        $this->assertDatabaseCount('lead_merges', 1);
    }

    public function test_stale_preview_and_active_sending_are_rejected_without_losing_records(): void
    {
        $target = Lead::factory()->create();
        $source = Lead::factory()->create();
        $service = app(LeadMergeService::class);
        $plan = $service->preview($target, $source);
        $source->update(['notes' => 'Cambio posterior']);
        try {
            $service->merge($target->id, $source->id, $plan['plan_sha256'], $this->decisions($plan));
            $this->fail('La revisión desactualizada debía rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('confirmation', $exception->errors());
        }
        $campaign = Campaign::factory()->create();
        ContactAttempt::create(['lead_id' => $source->id, 'campaign_id' => $campaign->id, 'channel' => 'email', 'status' => 'sending']);
        $plan = $service->preview($target->fresh(), $source->fresh());
        try {
            $service->merge($target->id, $source->id, $plan['plan_sha256'], $this->decisions($plan));
            $this->fail('El envío activo debía impedir la consolidación.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('confirmation', $exception->errors());
        }
        $this->assertDatabaseCount('leads', 2);
        $this->assertDatabaseCount('lead_merges', 0);
    }

    public function test_standalone_state_and_next_action_require_and_preserve_explicit_choices(): void
    {
        $target = Lead::factory()->create(['status' => 'QUEUED', 'next_action_at' => null]);
        $source = Lead::factory()->create(['status' => 'PAUSED', 'next_action_at' => now()->addDays(3)]);
        $service = app(LeadMergeService::class);
        $plan = $service->preview($target, $source);
        $this->assertArrayHasKey('status', $plan['fields']);
        $choices = $this->decisions($plan);
        $choices['fields']['status'] = 'source';
        $result = $service->merge($target->id, $source->id, $plan['plan_sha256'], $choices);
        $this->assertSame('PAUSED', $result->status);
        $this->assertSame($source->next_action_at->toDateTimeString(), $result->next_action_at->toDateTimeString());
        $this->assertFalse(app(CampaignMembership::class)->ready()->whereKey($target->id)->exists());
    }

    public function test_nonconflicting_steps_from_one_business_are_preserved_and_real_conflict_selects_whole_sequence(): void
    {
        $target = Lead::factory()->create(['status' => 'CONTACTED']);
        $source = Lead::factory()->create(['status' => 'CONTACTED']);
        $campaign = Campaign::factory()->create();
        app(CampaignMembership::class)->attach($target, $campaign);
        app(CampaignMembership::class)->attach($source, $campaign);
        $source->campaigns()->updateExistingPivot($campaign->id, ['status' => 'CONTACTED']);
        foreach (['follow_up_1', 'follow_up_2'] as $step) {
            ContactAttempt::create(['lead_id' => $source->id, 'campaign_id' => $campaign->id, 'channel' => 'email', 'step' => $step, 'status' => 'planned']);
        }
        $service = app(LeadMergeService::class);
        $plan = $service->preview($target, $source);
        $this->assertSame([], $plan['sequences']);
        ContactAttempt::create(['lead_id' => $target->id, 'campaign_id' => $campaign->id, 'channel' => 'email', 'step' => 'follow_up_1', 'status' => 'planned']);
        $plan = $service->preview($target, $source);
        $choices = $this->decisions($plan);
        $choices['sequences'][$campaign->id] = 'lead:'.$source->id;
        $choices['campaigns'][$campaign->id] = 'source';
        $result = $service->merge($target->id, $source->id, $plan['plan_sha256'], $choices);
        $this->assertSame(2, $result->contactAttempts()->where('status', 'planned')->count());
        $this->assertSame(1, $result->contactAttempts()->where('status', 'paused')->count());
    }

    public function test_consecutive_merges_keep_the_current_primary_contact_instead_of_old_projection(): void
    {
        $target = Lead::factory()->create(['entity_type' => 'organization', 'name' => 'Empresa', 'email' => 'original@example.test']);
        $source = Lead::factory()->create(['entity_type' => 'organization', 'name' => 'Empresa', 'email' => 'selected@example.test']);
        $third = Lead::factory()->create(['entity_type' => 'organization', 'name' => 'Empresa', 'email' => 'third@example.test']);
        $service = app(LeadMergeService::class);
        $firstPlan = $service->preview($target, $source);
        $firstChoices = $this->decisions($firstPlan);
        $firstChoices['primary_contact'] = 'source';
        $result = $service->merge($target->id, $source->id, $firstPlan['plan_sha256'], $firstChoices);
        $this->assertSame('selected@example.test', $result->email);

        $secondPlan = $service->preview($result, $third);
        $result = $service->merge($result->id, $third->id, $secondPlan['plan_sha256'], $this->decisions($secondPlan));

        $this->assertSame('selected@example.test', $result->email);
        $this->assertSame('selected@example.test', $result->contacts()->where('is_primary', true)->sole()->email);
        $this->assertSame(1, $result->contacts()->where('is_primary', true)->count());
        $this->assertTrue($result->contacts()->where('email', 'original@example.test')->exists());
        $this->assertTrue($result->contacts()->where('email', 'third@example.test')->exists());
        $this->assertDatabaseCount('lead_merges', 2);
    }

    public function test_consolidation_keeps_original_contact_score_and_its_historical_evaluation(): void
    {
        $this->travelTo(now()->startOfSecond());
        $offer = Offer::factory()->create(['rules' => ['scoring_profile' => 'generic', 'keywords' => ['servicios']]]);
        $campaign = Campaign::factory()->create(['offer_id' => $offer->id]);
        $target = Lead::factory()->create(['entity_type' => 'organization']);
        $source = Lead::factory()->create(['entity_type' => 'organization']);
        app(CampaignMembership::class)->attach($source, $campaign);
        app(LeadScorer::class)->score($source);
        $attempt = app(ContactWorkflow::class)->record(['lead_id' => $source->id, 'campaign_id' => $campaign->id, 'channel' => 'email', 'step' => 'initial', 'performed_at' => now()->toIso8601String()]);
        $snapshot = $attempt->score_snapshot;
        $this->assertTrue($snapshot['known']);
        $this->assertSame(1, app(MetricsService::class)->scoreEvaluation()['known_sample_size']);
        $service = app(LeadMergeService::class);
        $plan = $service->preview($target, $source);
        $merged = $service->merge($target->id, $source->id, $plan['plan_sha256'], $this->decisions($plan));
        $this->assertSame($snapshot, $attempt->fresh()->score_snapshot);
        $this->assertSame($merged->id, $attempt->fresh()->lead_id);
        $this->assertSame(1, app(MetricsService::class)->scoreEvaluation()['known_sample_size']);
        $archive = json_decode(DB::table('lead_merges')->first()->original_records, true);
        $this->assertNotEmpty($archive['lead_offer_scores']);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_failure_during_merge_rolls_back_every_relation_and_keeps_verified_backup(): void
    {
        $target = Lead::factory()->create();
        $source = Lead::factory()->create(['notes' => 'Debe conservarse']);
        $source->sources()->create(['source' => 'manual', 'source_identifier' => 'retained']);
        $service = app(LeadMergeService::class);
        $plan = $service->preview($target, $source);
        $scorer = \Mockery::mock(LeadScorer::class);
        $scorer->shouldReceive('score')->once()->andThrow(new \RuntimeException('Fallo sintético después de mover relaciones'));
        $this->app->instance(LeadScorer::class, $scorer);
        try {
            $service->merge($target->id, $source->id, $plan['plan_sha256'], $this->decisions($plan));
            $this->fail('La operación debía abortar');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Fallo sintético', $exception->getMessage());
        }
        $this->assertDatabaseCount('leads', 2);
        $this->assertDatabaseCount('lead_merges', 0);
        $this->assertDatabaseHas('lead_sources', ['lead_id' => $source->id, 'source_identifier' => 'retained']);
        $this->assertSame('Debe conservarse', $source->fresh()->notes);
        $backups = glob(storage_path('app/private/backups/*.sqlite'));
        $this->assertCount(1, $backups);
        $this->assertSame(2, app(BackupService::class)->verify($backups[0])['leads']);
    }
}
