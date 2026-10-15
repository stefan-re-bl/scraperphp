<?php

namespace Tests\Feature;

use App\Jobs\EnrichLead;
use App\Jobs\ExecuteScrapeRun;
use App\Jobs\ImportLeadFile;
use App\Models\Campaign;
use App\Models\ContactAttempt;
use App\Models\ImportRun;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\Offer;
use App\Models\Proposal;
use App\Models\Reply;
use App\Models\SavedLeadView;
use App\Models\ScoringRule;
use App\Models\ScrapeRun;
use App\Services\BackupService;
use App\Services\CampaignMembership;
use App\Services\EmailContactService;
use App\Services\LeadEnricher;
use App\Services\LeadImporter;
use App\Services\LeadPipeline;
use App\Services\LeadScorer;
use App\Services\MetricsService;
use App\Services\OfferMatcher;
use App\Services\PreferencesService;
use App\Services\TemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GeneralWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function prospect(array $leadData = []): array
    {
        $campaign = Campaign::factory()->create();
        $lead = Lead::factory()->create($leadData);
        app(CampaignMembership::class)->attach($lead, $campaign);

        return [$campaign, $lead];
    }

    private function contact(Campaign $campaign, Lead $lead, string $step = 'initial', ?int $attemptId = null): array
    {
        return ['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'channel' => 'email', 'step' => $step, 'performed_at' => now()->toIso8601String(), 'contact_attempt_id' => $attemptId];
    }

    public function test_manual_contact_creates_a_real_followup_even_when_campaign_is_in_dry_run(): void
    {
        $this->travelTo(Carbon::parse('2026-10-08 10:00:00'));
        [$campaign, $lead] = $this->prospect();

        $this->post('/contact-attempts', $this->contact($campaign, $lead))->assertRedirect();

        $this->assertDatabaseHas('contact_attempts', ['lead_id' => $lead->id, 'step' => 'initial', 'status' => 'sent', 'dry_run' => false]);
        $this->assertDatabaseHas('contact_attempts', ['lead_id' => $lead->id, 'step' => 'follow_up_1', 'status' => 'planned', 'due_at' => '2026-10-11 10:00:00', 'dry_run' => false]);
        $this->assertDatabaseHas('campaign_lead', ['campaign_id' => $campaign->id, 'lead_id' => $lead->id, 'status' => 'CONTACTED']);
    }

    public function test_completing_a_followup_updates_the_existing_record_and_schedules_the_next_step(): void
    {
        $this->freezeTime();
        [$campaign, $lead] = $this->prospect();
        $pending = ContactAttempt::create(['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'channel' => 'email', 'step' => 'follow_up_1', 'status' => 'planned', 'due_at' => now(), 'dry_run' => false]);

        $this->post('/contact-attempts', $this->contact($campaign, $lead, 'follow_up_1', $pending->id))->assertRedirect();

        $this->assertDatabaseHas('contact_attempts', ['id' => $pending->id, 'status' => 'sent']);
        $this->assertDatabaseCount('contact_attempts', 2);
        $this->assertDatabaseHas('contact_attempts', ['lead_id' => $lead->id, 'step' => 'follow_up_2', 'status' => 'planned']);
    }

    public function test_duplicate_completion_does_not_add_another_contact(): void
    {
        $this->freezeTime();
        [$campaign, $lead] = $this->prospect();
        $this->post('/contact-attempts', $this->contact($campaign, $lead))->assertRedirect();

        $this->post('/contact-attempts', $this->contact($campaign, $lead))->assertSessionHasErrors('lead_id');

        $this->assertDatabaseCount('contact_attempts', 2);
    }

    public function test_email_simulation_does_not_change_pipeline_or_schedule_followups(): void
    {
        Mail::fake();
        [$campaign, $lead] = $this->prospect();
        $template = MessageTemplate::create(['name' => 'Inicial', 'channel' => 'email', 'stage' => 'initial', 'language' => 'es', 'subject' => 'Consulta', 'body' => 'Hola {{negocio}}']);

        $this->post('/email/send', ['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'message_template_id' => $template->id, 'dry_run' => true])->assertRedirect();

        $this->assertSame('QUEUED', $lead->fresh()->status);
        $this->assertDatabaseHas('contact_attempts', ['lead_id' => $lead->id, 'status' => 'dry_run', 'performed_at' => null]);
        $this->assertDatabaseCount('contact_attempts', 1);
        $this->assertSame(0, app(MetricsService::class)->funnel()['counts']['contacted']);
        Mail::assertNothingSent();
    }

    public function test_scoring_is_stable_and_inactive_rules_contribute_zero(): void
    {
        [, $lead] = $this->prospect(['phone' => 'abc', 'website_url' => null, 'signals' => []]);
        app(LeadEnricher::class)->enrich($lead);
        $scorer = app(LeadScorer::class);
        $first = $scorer->score($lead);
        $second = $scorer->score($lead);
        ScoringRule::where('component', 'contact_quality_email')->update(['active' => false]);

        $third = $scorer->score($lead);

        $this->assertSame($first, $second);
        $this->assertSame($first - 25, $third);
        $this->assertDatabaseMissing('score_components', ['lead_id' => $lead->id, 'component' => 'contact_quality_phone']);
        $this->assertDatabaseMissing('score_components', ['lead_id' => $lead->id, 'component' => 'signal_phone_format_invalid']);
    }

    public function test_health_category_does_not_receive_a_hardcoded_bonus(): void
    {
        $lead = Lead::factory()->create(['category' => 'Psicología']);
        $scorer = app(LeadScorer::class);
        $health = $scorer->score($lead);
        $lead->update(['category' => 'Construcción']);

        $general = $scorer->score($lead);

        $this->assertSame($health, $general);
    }

    public function test_offer_rules_override_global_weights_and_can_disable_a_component(): void
    {
        $offer = Offer::create(['name' => 'Servicio general']);
        $lead = Lead::factory()->create(['suggested_offer_id' => $offer->id]);
        app(LeadScorer::class)->score($lead);
        ScoringRule::create(['offer_id' => $offer->id, 'component' => 'contact_quality_email', 'points' => 10, 'weight' => 2, 'dimension' => 'contact', 'active' => true]);

        app(LeadScorer::class)->score($lead);

        $this->assertDatabaseHas('score_components', ['lead_id' => $lead->id, 'component' => 'contact_quality_email', 'points' => 20, 'weight' => 2]);
        ScoringRule::where('offer_id', $offer->id)->update(['active' => false]);
        app(LeadScorer::class)->score($lead);
        $this->assertDatabaseMissing('score_components', ['lead_id' => $lead->id, 'component' => 'contact_quality_email']);
    }

    public function test_offer_matching_can_return_no_match(): void
    {
        $lead = Lead::factory()->create(['segment' => 'construcción', 'category' => 'Constructora']);
        Offer::create(['name' => 'Agenda', 'segment' => 'psicólogos']);

        $match = app(OfferMatcher::class)->match($lead);

        $this->assertNull($match);
    }

    public function test_campaign_summary_and_list_exclude_unrelated_leads_with_the_same_segment(): void
    {
        [$campaign, $lead] = $this->prospect();
        $other = Campaign::factory()->create();

        $this->get('/campaigns/'.$other->id)->assertOk()->assertViewHas('stats', fn ($stats) => $stats['leads'] === 0);
        $this->get('/campaigns/'.$other->id.'?tab=leads')->assertOk()->assertDontSee($lead->name);
        $this->get('/campaigns/'.$campaign->id.'?tab=leads')->assertOk()->assertSee($lead->name);
    }

    public function test_reimport_preserves_the_new_campaign_and_source_without_duplicating_the_business(): void
    {
        Queue::fake([EnrichLead::class]);
        $first = Campaign::factory()->create();
        $second = Campaign::factory()->create();
        $file = UploadedFile::fake()->createWithContent('leads.csv', "name,location,email,place_id\nNegocio,Córdoba,hola@example.test,map-1");
        app(LeadImporter::class)->importCsv($file, $first);

        $run = app(LeadImporter::class)->importCsv($file, $second);

        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseCount('lead_sources', 2);
        $this->assertDatabaseCount('campaign_lead', 2);
        $this->assertSame(1, $run->duplicate_count);
        $this->assertSame('csv_manual', $run->source);
        Queue::assertPushed(EnrichLead::class, 1);
    }

    public function test_businesses_with_shared_email_remain_separate_when_their_identity_differs(): void
    {
        Queue::fake([EnrichLead::class]);
        $campaign = Campaign::factory()->create();
        $csv = UploadedFile::fake()->createWithContent('leads.csv', "name,location,email\nNegocio Centro,Córdoba,hola@example.test\nNegocio Norte,Mendoza,hola@example.test");

        app(LeadImporter::class)->importCsv($csv, $campaign);

        $this->assertDatabaseCount('leads', 2);
        $this->assertDatabaseHas('leads', ['name' => 'Negocio Norte', 'duplicate_status' => 'possible_duplicate']);
        Queue::assertPushed(EnrichLead::class, 2);
    }

    public function test_metrics_count_unique_businesses_and_ignore_pending_and_simulated_contacts(): void
    {
        $this->freezeTime();
        [$campaign, $lead] = $this->prospect();
        $real = ContactAttempt::create(['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'channel' => 'email', 'status' => 'sent', 'performed_at' => now(), 'dry_run' => false]);
        ContactAttempt::create(['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'channel' => 'email', 'status' => 'sent', 'performed_at' => now(), 'dry_run' => false, 'step' => 'follow_up_1']);
        ContactAttempt::create(['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'channel' => 'email', 'status' => 'planned']);
        ContactAttempt::create(['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'channel' => 'email', 'status' => 'dry_run', 'performed_at' => now(), 'dry_run' => true]);
        Reply::create(['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'contact_attempt_id' => $real->id, 'category' => 'interested', 'received_at' => now()]);
        Reply::create(['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'contact_attempt_id' => $real->id, 'category' => 'meeting', 'received_at' => now()]);

        $metrics = app(MetricsService::class)->funnel(['campaign' => $campaign->id]);

        $this->assertSame(1, $metrics['counts']['contacted']);
        $this->assertSame(2, $metrics['counts']['attempts']);
        $this->assertSame(1, $metrics['counts']['responses']);
        $this->assertSame(100.0, $metrics['rates']['reply_rate']);
        $this->assertSame(1, app(MetricsService::class)->segmented('campaign', ['campaign' => $campaign->id])[0]['contacts']);
    }

    public function test_metrics_period_selects_the_contact_cohort(): void
    {
        $this->travelTo(Carbon::parse('2026-10-08 10:00:00'));
        [$campaign, $lead] = $this->prospect();
        ContactAttempt::create(['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'channel' => 'email', 'status' => 'sent', 'dry_run' => false, 'performed_at' => '2026-09-01 10:00:00']);

        $metrics = app(MetricsService::class)->funnel(['from' => '2026-10-01', 'to' => '2026-10-08']);

        $this->assertSame(0, $metrics['counts']['contacted']);
    }

    public function test_new_exclusion_pauses_matching_leads_and_cancels_pending_followups(): void
    {
        [$campaign, $lead] = $this->prospect(['email' => 'hola@example.test']);
        ContactAttempt::create(['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'channel' => 'email', 'step' => 'follow_up_1', 'status' => 'planned']);

        $this->post('/exclusions', ['type' => 'email', 'value' => 'HOLA@EXAMPLE.TEST', 'reason' => 'No contactar'])->assertRedirect();

        $this->assertNotNull($lead->fresh()->excluded_at);
        $this->assertDatabaseHas('contact_attempts', ['lead_id' => $lead->id, 'status' => 'cancelled']);
        $this->post('/leads/bulk', ['action' => 'queue', 'selected_ids' => [$lead->id]])->assertSessionHasErrors('lead_id');
        $this->assertSame('PAUSED', $lead->fresh()->status);
    }

    public function test_bulk_failure_rolls_back_other_leads(): void
    {
        [, $first] = $this->prospect(['status' => 'NEW']);
        $blocked = Lead::factory()->create(['status' => 'PAUSED', 'excluded_at' => now()]);

        $this->post('/leads/bulk', ['action' => 'queue', 'selected_ids' => [$first->id, $blocked->id]])->assertSessionHasErrors('lead_id');

        $this->assertSame('NEW', $first->fresh()->status);
    }

    public function test_empty_bulk_export_does_not_export_all_leads(): void
    {
        $this->post('/leads/bulk', ['action' => 'export', 'selected_ids' => []])->assertSessionHasErrors('selected_ids');
    }

    public function test_filtered_json_excludes_unrelated_responses_proposals_and_nested_contacts(): void
    {
        [$campaign, $selected] = $this->prospect();
        [$other, $outside] = $this->prospect();
        Reply::create(['lead_id' => $outside->id, 'campaign_id' => $other->id, 'body' => 'Fuera de selección', 'category' => 'other', 'received_at' => now()]);
        Proposal::create(['lead_id' => $outside->id, 'campaign_id' => $other->id, 'service' => 'Fuera', 'version' => 1, 'currency' => 'USD']);

        $response = $this->get('/exports/json?ids='.$selected->id);

        $response->assertOk()->assertJsonCount(1, 'data.leads')->assertJsonCount(0, 'data.replies')->assertJsonCount(0, 'data.proposals')->assertDontSee('Fuera de selección');
    }

    public function test_generic_business_does_not_receive_a_manual_booking_diagnosis(): void
    {
        $lead = Lead::factory()->create(['category' => 'Constructora', 'website_url' => null]);

        app(LeadEnricher::class)->enrich($lead);

        $this->assertDatabaseMissing('signals', ['lead_id' => $lead->id, 'type' => 'manual_contact_dependency']);
    }

    public function test_failed_website_read_does_not_infer_weak_content_or_missing_booking(): void
    {
        $lead = Lead::factory()->create(['website_url' => 'https://negocio.test']);

        app(LeadEnricher::class)->analyzeWebsiteContent($lead, $lead->website_url, '<html>Error</html>', false, 503);

        $this->assertDatabaseHas('signals', ['lead_id' => $lead->id, 'type' => 'website_unreachable', 'review_status' => 'pending']);
        $this->assertDatabaseMissing('signals', ['lead_id' => $lead->id, 'type' => 'weak_website_content']);
        $this->assertDatabaseMissing('signals', ['lead_id' => $lead->id, 'type' => 'no_visible_booking']);
    }

    public function test_observed_email_can_be_confirmed_into_contact_data(): void
    {
        $lead = Lead::factory()->create(['email' => null, 'website_url' => 'https://negocio.test']);
        app(LeadEnricher::class)->analyzeWebsiteContent($lead, $lead->website_url, '<title>Negocio</title><p>Contactanos a ventas@negocio.test</p>');

        $this->post('/leads/'.$lead->id.'/confirm-email')->assertRedirect();

        $this->assertSame('ventas@negocio.test', $lead->fresh()->email);
    }

    public function test_template_renderer_handles_whitespace_and_reports_missing_variables(): void
    {
        $lead = Lead::factory()->create(['contact_person' => 'Ana']);
        $template = MessageTemplate::create(['name' => 'Mensaje', 'subject' => 'Para {{ nombre }}', 'body' => '{{negocio}}: {{demo}} {{desconocida}}']);

        $message = app(TemplateRenderer::class)->render($template, $lead);

        $this->assertSame('Para Ana', $message['subject']);
        $this->assertSame(['demo', 'desconocida'], $message['missing']);
        $this->assertStringContainsString($lead->name, $message['body']);
    }

    public function test_manual_creation_and_editing_preserve_contact_context_and_notes(): void
    {
        Queue::fake([EnrichLead::class]);
        $campaign = Campaign::factory()->create();
        $data = ['name' => 'Agencia general', 'country' => 'Argentina', 'language' => 'es', 'source' => 'referral', 'relationship_type' => 'collaborator',
            'phone' => '(381) 672-2676', 'email' => 'hola@example.test', 'website_url' => 'example.test', 'contact_person' => 'Ana', 'contact_role' => 'Dirección', 'tags' => 'referidos, diseño', 'notes' => 'Primera nota', 'campaign_id' => $campaign->id];

        $this->post('/leads', $data)->assertRedirect();
        $lead = Lead::firstOrFail();

        $this->assertSame('+543816722676', $lead->phone);
        $this->assertSame('https://example.test', $lead->website_url);
        $this->assertSame(['referidos', 'diseño'], $lead->tags);
        $this->assertDatabaseHas('campaign_lead', ['lead_id' => $lead->id, 'campaign_id' => $campaign->id]);
        Queue::assertPushed(EnrichLead::class, fn ($job) => $job->leadId === $lead->id);
        $this->put('/leads/'.$lead->id, $data + ['unused' => ''])->assertRedirect();
        $this->assertSame('Primera nota', $lead->fresh()->notes);
    }

    public function test_quick_search_and_saved_views_keep_filters(): void
    {
        [, $lead] = $this->prospect(['name' => 'Empresa buscada']);
        $this->get('/leads?q=buscada')->assertOk()->assertSee($lead->name);

        $this->post('/lead-views', ['name' => 'Mi vista', 'q' => 'buscada', 'has_email' => '1'])->assertRedirect();

        $this->assertSame(['q' => 'buscada', 'has_email' => '1'], SavedLeadView::firstOrFail()->filters);
        $this->get('/leads')->assertOk()->assertSee('Mi vista');
    }

    public function test_queue_focus_and_future_snooze_work(): void
    {
        $this->freezeTime();
        [$campaign, $lead] = $this->prospect(['name' => 'Enfocado']);
        [, $other] = $this->prospect(['name' => 'Otro prospecto']);
        $this->get('/contact-queue?lead='.$lead->id)->assertOk()->assertSee('Enfocado')->assertDontSee('Otro prospecto');

        $this->post('/leads/'.$lead->id.'/snooze', ['campaign_id' => $campaign->id, 'due_at' => now()->addDays(2)->toIso8601String()])->assertRedirect();

        $this->get('/contact-queue?campaign='.$campaign->id)->assertOk()->assertDontSee('Enfocado');
    }

    public function test_campaign_pause_blocks_contact_and_resume_restores_work(): void
    {
        [$campaign, $lead] = $this->prospect();
        $this->post('/campaigns/'.$campaign->id.'/kill-switch')->assertRedirect();

        $this->post('/contact-attempts', $this->contact($campaign, $lead))->assertSessionHasErrors('campaign_id');
        $this->post('/campaigns/'.$campaign->id.'/resume')->assertRedirect();

        $this->assertFalse($campaign->fresh()->kill_switch);
        $this->post('/contact-attempts', $this->contact($campaign, $lead))->assertRedirect();
    }

    public function test_campaign_editing_updates_manual_followup_delays(): void
    {
        [$campaign] = $this->prospect();
        $data = $campaign->only(['name', 'segment', 'country', 'city', 'language', 'source', 'channel', 'daily_limit', 'scrape_limit', 'follow_up_1_delay_days', 'follow_up_2_delay_days']);
        $data['follow_up_1_delay_days'] = 5;

        $this->put('/campaigns/'.$campaign->id, $data)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(5, $campaign->fresh()->follow_up_1_delay_days);
    }

    public function test_proposal_update_does_not_create_another_version_and_metrics_count_one_business(): void
    {
        $this->freezeTime();
        [$campaign, $lead] = $this->prospect();
        ContactAttempt::create(['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'channel' => 'email', 'status' => 'sent', 'performed_at' => now(), 'dry_run' => false]);
        $data = ['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'service' => 'Servicio general', 'version' => 1, 'currency' => 'USD', 'amount' => 400, 'sent_at' => now()->toIso8601String(), 'result' => 'pending'];
        $this->post('/proposals', $data)->assertRedirect();
        $proposal = Proposal::firstOrFail();
        $data['result'] = 'won';

        $this->put('/proposals/'.$proposal->id, $data)->assertRedirect();

        $this->assertDatabaseCount('proposals', 1);
        $this->assertSame('won', $proposal->fresh()->result);
        $metrics = app(MetricsService::class)->funnel(['campaign' => $campaign->id]);
        $this->assertSame(1, $metrics['counts']['proposals']);
        $this->assertSame(1, $metrics['counts']['won']);
    }

    public function test_reply_can_be_marked_as_attended(): void
    {
        [, $lead] = $this->prospect();
        $reply = Reply::create(['lead_id' => $lead->id, 'category' => 'other', 'received_at' => now(), 'requires_human' => true]);

        $this->patch('/replies/'.$reply->id.'/resolve')->assertRedirect();

        $this->assertFalse($reply->fresh()->requires_human);
    }

    public function test_reply_rejects_a_contact_from_another_business(): void
    {
        [$campaign, $lead] = $this->prospect();
        $outside = Lead::factory()->create();
        $attempt = ContactAttempt::create(['lead_id' => $outside->id, 'campaign_id' => $campaign->id, 'channel' => 'email', 'status' => 'sent']);

        $this->post('/replies', ['lead_id' => $lead->id, 'contact_attempt_id' => $attempt->id, 'category' => 'other', 'received_at' => now()->toIso8601String()])->assertNotFound();

        $this->assertDatabaseCount('replies', 0);
    }

    public function test_scraper_and_import_dispatch_persistent_jobs_without_external_work(): void
    {
        Queue::fake([ExecuteScrapeRun::class, ImportLeadFile::class]);
        $campaign = Campaign::factory()->create();

        $this->post('/campaigns/'.$campaign->id.'/acquisition', ['query' => 'negocios', 'location' => 'Córdoba', 'result_limit' => 20, 'dry_run' => false])->assertRedirect();
        $this->post('/imports', ['campaign_id' => $campaign->id, 'source' => 'csv_manual', 'csv' => UploadedFile::fake()->createWithContent('leads.csv', "name,email\nNegocio,hola@example.test")])->assertRedirect();

        Queue::assertPushed(ExecuteScrapeRun::class, fn ($job) => $job->queue === 'sales');
        Queue::assertPushed(ImportLeadFile::class, fn ($job) => $job->queue === 'sales');
        $this->assertDatabaseHas('scrape_runs', ['campaign_id' => $campaign->id, 'status' => 'queued']);
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_cancelled_search_can_be_retried_after_campaign_resume(): void
    {
        Queue::fake([ExecuteScrapeRun::class]);
        $campaign = Campaign::factory()->create();
        $run = ScrapeRun::create(['campaign_id' => $campaign->id, 'query' => 'negocios', 'status' => 'queued', 'dry_run' => false, 'arguments' => ['test']]);

        $this->post('/scraper/runs/'.$run->id.'/cancel')->assertRedirect();
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->post('/scraper/runs/'.$run->id.'/retry')->assertRedirect();

        $this->assertSame('queued', $run->fresh()->status);
        Queue::assertPushed(ExecuteScrapeRun::class, 1);
    }

    public function test_import_preview_supports_bom_semicolon_and_column_mapping(): void
    {
        Queue::fake([EnrichLead::class]);
        $campaign = Campaign::factory()->create();
        $file = UploadedFile::fake()->createWithContent('leads.csv', "\xEF\xBB\xBFnegocio;correo;direccion\nEmpresa;HOLA@EXAMPLE.TEST;Córdoba");

        $run = app(LeadImporter::class)->importCsv($file, $campaign, ['mapping' => ['name' => 'negocio', 'email' => 'correo', 'location' => 'direccion']]);

        $this->assertSame(1, $run->imported_count);
        $this->assertDatabaseHas('leads', ['name' => 'Empresa', 'email' => 'hola@example.test', 'source' => 'csv_manual']);
        Queue::assertPushed(EnrichLead::class, 1);
    }

    public function test_dashboard_pending_counts_are_not_capped_at_eight(): void
    {
        Lead::factory()->count(12)->create();

        $this->get('/')->assertOk()->assertViewHas('pendingCounts', fn ($counts) => $counts['queue'] === 12);
    }

    public function test_preferences_are_persisted_and_new_screens_render(): void
    {
        [$campaign, $lead] = $this->prospect();
        $this->put('/settings/preferences', ['timezone' => 'America/Montevideo', 'currency' => 'ARS', 'country' => 'Uruguay', 'language' => 'es', 'date_format' => 'Y-m-d H:i'])->assertRedirect();
        $this->assertDatabaseHas('application_preferences', ['key' => 'currency', 'value' => 'ARS']);

        foreach (['/leads/'.$lead->id, '/offers', '/templates', '/proposals', '/replies', '/scoring', '/exports', '/campaigns/'.$campaign->id.'?tab=metrics'] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_booking_evidence_is_refreshed_without_leaving_obsolete_flags(): void
    {
        $lead = Lead::factory()->create(['website_url' => 'https://negocio.test']);
        app(LeadEnricher::class)->analyzeWebsiteContent($lead, $lead->website_url, '<title>Negocio</title><a href="https://calendly.com/negocio">Reservar online</a>');

        app(LeadEnricher::class)->analyzeWebsiteContent($lead, $lead->website_url, '<title>Negocio</title><p>Contactanos</p>');

        $this->assertFalse($lead->fresh()->booking_visible);
    }

    public function test_campaign_followups_and_replies_do_not_change_other_opportunities(): void
    {
        $this->freezeTime();
        [$campaign, $lead] = $this->prospect();
        $other = Campaign::factory()->create();
        app(CampaignMembership::class)->attach($lead, $other);
        app(LeadPipeline::class)->move($lead, 'QUEUED', 'Preparar segunda oportunidad', $other);
        $this->post('/contact-attempts', $this->contact($campaign, $lead))->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/contact-attempts', $this->contact($other, $lead))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('contact_attempts', ['campaign_id' => $other->id, 'status' => 'planned']);

        $this->post('/replies', ['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'category' => 'won', 'received_at' => now()->toIso8601String()])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('campaign_lead', ['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'status' => 'WON']);
        $this->assertDatabaseHas('campaign_lead', ['lead_id' => $lead->id, 'campaign_id' => $other->id, 'status' => 'CONTACTED']);
        $this->assertDatabaseHas('contact_attempts', ['campaign_id' => $other->id, 'status' => 'planned']);
        $metrics = app(MetricsService::class);
        $this->assertSame(1, $metrics->funnel()['counts']['won']);
        $this->assertSame(0, $metrics->funnel(['campaign' => $other->id])['counts']['won']);
        $this->assertSame(0, $metrics->funnel(['campaign' => $other->id])['counts']['responses']);
    }

    public function test_snoozing_one_campaign_keeps_another_campaign_ready(): void
    {
        [$campaign, $lead] = $this->prospect();
        $other = Campaign::factory()->create();
        app(CampaignMembership::class)->attach($lead, $other);
        app(LeadPipeline::class)->move($lead, 'QUEUED', 'Nueva oportunidad', $other);

        $this->post('/leads/'.$lead->id.'/snooze', ['campaign_id' => $campaign->id, 'due_at' => now()->addDays(2)->toIso8601String()])->assertRedirect()->assertSessionHasNoErrors();

        $this->get('/contact-queue?campaign='.$campaign->id)->assertViewHas('leads', fn ($rows) => $rows->isEmpty());
        $this->get('/contact-queue?campaign='.$other->id)->assertViewHas('leads', fn ($rows) => $rows->contains('id', $lead->id));
    }

    public function test_finished_sequence_and_skipped_leads_do_not_return_as_initial_contacts(): void
    {
        [$campaign, $lead] = $this->prospect();
        $this->post('/contact-attempts', $this->contact($campaign, $lead, 'follow_up_2'))->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/contact-queue?campaign='.$campaign->id)->assertViewHas('leads', fn ($rows) => $rows->isEmpty());
        $another = Lead::factory()->create();
        app(CampaignMembership::class)->attach($another, $campaign);
        $this->get('/contact-queue?campaign='.$campaign->id.'&skip='.$another->id)->assertViewHas('leads', fn ($rows) => $rows->isEmpty());
    }

    public function test_segmented_metrics_keep_unknown_values_in_their_own_group(): void
    {
        [$campaign, $lead] = $this->prospect(['city' => null]);
        $another = Lead::factory()->create(['city' => 'Córdoba']);
        app(CampaignMembership::class)->attach($another, $campaign);
        foreach ([$lead, $another] as $prospect) {
            ContactAttempt::create(['lead_id' => $prospect->id, 'campaign_id' => $campaign->id, 'channel' => 'email', 'status' => 'sent', 'dry_run' => false, 'performed_at' => now()]);
        }

        $rows = collect(app(MetricsService::class)->segmented('city', ['campaign' => $campaign->id]))->keyBy('label');

        $this->assertSame(1, $rows['Sin dato']['contacts']);
        $this->assertSame(1, $rows['Córdoba']['contacts']);
    }

    public function test_retrying_partial_import_keeps_completed_rows_and_counters(): void
    {
        Queue::fake([EnrichLead::class, ImportLeadFile::class]);
        $campaign = Campaign::factory()->create();
        $path = tempnam(sys_get_temp_dir(), 'partial-import-');
        File::put($path, "name,email,location\nPrimero,uno@example.test,Centro\nSegundo,dos@example.test,Norte\n");
        $run = ImportRun::create(['campaign_id' => $campaign->id, 'source' => 'csv_manual', 'status' => 'failed', 'file_path' => $path, 'processed_rows' => 1, 'raw_count' => 1, 'imported_count' => 1]);
        $lead = Lead::factory()->create(['name' => 'Primero', 'email' => 'uno@example.test']);
        $lead->sources()->create(['source' => 'csv_manual', 'import_run_id' => $run->id]);
        app(CampaignMembership::class)->attach($lead, $campaign);
        try {
            $this->post('/imports/'.$run->id.'/retry')->assertRedirect()->assertSessionHasNoErrors();
            app(LeadImporter::class)->importCsv($path, $campaign, ['run_id' => $run->id]);
            $this->assertDatabaseCount('leads', 2);
            $this->assertDatabaseHas('import_runs', ['id' => $run->id, 'status' => 'completed', 'processed_rows' => 2, 'imported_count' => 2, 'duplicate_count' => 0, 'claim_token' => null]);
            $this->assertSame(2, $run->fresh()->raw_count);
            Queue::assertPushed(ImportLeadFile::class, 1);
        } finally {
            File::delete($path);
        }
    }

    public function test_duplicate_import_job_does_not_process_a_claimed_file(): void
    {
        Queue::fake([EnrichLead::class]);
        $campaign = Campaign::factory()->create();
        $run = ImportRun::create(['campaign_id' => $campaign->id, 'source' => 'csv_manual', 'status' => 'processing', 'claimed_at' => now(), 'claim_token' => fake()->uuid(), 'file_path' => '/does/not/exist']);

        app(LeadImporter::class)->importCsv($run->file_path, $campaign, ['run_id' => $run->id]);

        $this->assertDatabaseCount('leads', 0);
        $this->assertSame('processing', $run->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_import_preview_requires_confirmation_and_uses_the_selected_mapping(): void
    {
        Queue::fake([ImportLeadFile::class]);
        $campaign = Campaign::factory()->create();
        $file = UploadedFile::fake()->createWithContent('datos.csv', "negocio;correo\nEmpresa;info@example.test");
        $response = $this->post('/imports/preview', ['campaign_id' => $campaign->id, 'source' => 'referral', 'csv' => $file])->assertOk();
        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseCount('import_runs', 0);
        $token = $response->viewData('token');

        $this->post('/imports/confirm', ['token' => $token, 'mapping' => ['name' => 'negocio', 'email' => 'correo']])->assertRedirect()->assertSessionHasNoErrors();

        $run = ImportRun::firstOrFail();
        $this->assertSame('referral', $run->source);
        $this->assertSame('correo', $run->mapping['email']);
        Queue::assertPushed(ImportLeadFile::class, 1);
        $this->post('/imports/confirm', ['token' => $token])->assertNotFound();
        File::delete($run->file_path);
    }

    public function test_local_contact_dates_are_stored_in_utc_and_timezone_changes_only_affect_display(): void
    {
        $this->travelTo(Carbon::parse('2026-10-08 15:00:00', 'UTC'));
        [$campaign, $lead] = $this->prospect();
        $data = $this->contact($campaign, $lead);
        $data['performed_at'] = '2026-10-08T11:30';
        $this->post('/contact-attempts', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('contact_attempts', ['lead_id' => $lead->id, 'performed_at' => '2026-10-08 14:30:00']);
        app(PreferencesService::class)->save(['timezone' => 'Europe/Madrid']);
        $this->get('/leads/'.$lead->id)->assertOk()->assertSee('08/10/2026 16:30');
        $this->assertDatabaseHas('contact_attempts', ['lead_id' => $lead->id, 'performed_at' => '2026-10-08 14:30:00']);
    }

    public function test_bulk_status_requires_a_campaign_when_a_business_has_multiple_opportunities(): void
    {
        [$campaign, $lead] = $this->prospect();
        $other = Campaign::factory()->create();
        app(CampaignMembership::class)->attach($lead, $other);

        $this->post('/leads/bulk', ['selected_ids' => [$lead->id], 'action' => 'change_status', 'status' => 'WON'])->assertSessionHasErrors('campaign_id');

        $this->assertSame('QUEUED', app(CampaignMembership::class)->status($lead, $campaign));
    }

    public function test_invalid_backup_is_rejected_without_changing_records(): void
    {
        Lead::factory()->create(['name' => 'Conservar']);
        $file = UploadedFile::fake()->createWithContent('invalid.sqlite', 'not a database');

        $this->post('/backups/verify', ['backup' => $file])->assertRedirect()->assertSessionHasErrors();

        $this->assertDatabaseHas('leads', ['name' => 'Conservar']);
    }

    public function test_website_refresh_removes_obsolete_signals(): void
    {
        $lead = Lead::factory()->create(['website_url' => 'https://negocio.test']);
        $enricher = app(LeadEnricher::class);
        $enricher->analyzeWebsiteContent($lead, $lead->website_url, '<title>Negocio</title><a href="https://calendly.com/negocio">Reservar online</a>');
        $this->assertDatabaseHas('signals', ['lead_id' => $lead->id, 'type' => 'booking_system_visible']);

        $enricher->analyzeWebsiteContent($lead, $lead->website_url, '<title>Negocio</title><p>Contactanos</p>');

        $this->assertDatabaseHas('signals', ['lead_id' => $lead->id, 'type' => 'booking_system_visible', 'is_current' => false]);
        $this->assertDatabaseMissing('signals', ['lead_id' => $lead->id, 'type' => 'booking_system_visible', 'is_current' => true]);
    }

    public function test_real_email_records_delivery_and_schedules_followup_with_a_fake_mailer(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();
        [$campaign, $lead] = $this->prospect();
        $campaign->update(['mode' => 'automatic', 'dry_run' => false]);
        $template = MessageTemplate::create(['name' => 'Inicial', 'channel' => 'email', 'stage' => 'initial', 'language' => 'es', 'body' => 'Hola {{negocio}}', 'active' => true]);

        $this->post('/email/send', ['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'message_template_id' => $template->id, 'dry_run' => false])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('contact_attempts', ['lead_id' => $lead->id, 'step' => 'initial', 'status' => 'sent', 'dry_run' => false]);
        $this->assertDatabaseHas('contact_attempts', ['lead_id' => $lead->id, 'step' => 'follow_up_1', 'status' => 'planned']);
        $this->assertSame(1, app(MetricsService::class)->funnel()['counts']['contacted']);
    }

    public function test_failed_mail_delivery_does_not_mark_a_business_as_contacted(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::shouldReceive('raw')->once()->andThrow(new \RuntimeException('SMTP no disponible'));
        [$campaign, $lead] = $this->prospect();
        $campaign->update(['mode' => 'automatic', 'dry_run' => false]);
        $template = MessageTemplate::create(['name' => 'Inicial', 'channel' => 'email', 'stage' => 'initial', 'language' => 'es', 'body' => 'Hola {{negocio}}', 'active' => true]);
        try {
            app(EmailContactService::class)->sendInitial($lead, $campaign, $template);
            $this->fail('El error de envío debe propagarse.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('SMTP no disponible', $exception->getMessage());
        }

        $this->assertDatabaseHas('contact_attempts', ['lead_id' => $lead->id, 'status' => 'failed', 'performed_at' => null]);
        $this->assertSame('QUEUED', $lead->fresh()->status);
        $this->assertSame(0, app(MetricsService::class)->funnel()['counts']['contacted']);
    }

    public function test_log_mail_transport_is_always_counted_as_simulation(): void
    {
        config(['mail.default' => 'log']);
        Mail::fake();
        [$campaign, $lead] = $this->prospect();
        $campaign->update(['mode' => 'automatic', 'dry_run' => false]);
        $template = MessageTemplate::create(['name' => 'Inicial', 'channel' => 'email', 'stage' => 'initial', 'language' => 'es', 'body' => 'Hola {{negocio}}', 'active' => true]);

        $this->post('/email/send', ['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'message_template_id' => $template->id, 'dry_run' => false])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('contact_attempts', ['lead_id' => $lead->id, 'status' => 'dry_run', 'performed_at' => null]);
        $this->assertSame(0, app(MetricsService::class)->funnel()['counts']['contacted']);
        Mail::assertNothingOutgoing();
    }

    public function test_bulk_selection_preserves_the_original_status_filter(): void
    {
        $lead = Lead::factory()->create(['status' => 'NEW']);
        $this->post('/leads/bulk', ['selected_ids' => [$lead->id], 'action' => 'queue', 'view_filters' => ['status' => 'QUEUED']])->assertRedirect()->assertSessionHasErrors();
        $this->assertSame('NEW', $lead->fresh()->status);
    }

    public function test_reopening_a_manually_paused_opportunity_keeps_the_pending_followup(): void
    {
        [$campaign, $lead] = $this->prospect();
        $this->post('/contact-attempts', $this->contact($campaign, $lead))->assertRedirect()->assertSessionHasNoErrors();
        $pending = ContactAttempt::where('step', 'follow_up_1')->firstOrFail();
        $this->post('/leads/'.$lead->id.'/status', ['campaign_id' => $campaign->id, 'status' => 'PAUSED'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('paused', $pending->fresh()->status);

        $this->post('/leads/'.$lead->id.'/status', ['campaign_id' => $campaign->id, 'status' => 'QUEUED'])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('planned', $pending->fresh()->status);
        $this->assertDatabaseCount('contact_attempts', 2);
    }

    public function test_backup_roundtrip_restores_records_and_keeps_a_previous_snapshot(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sales-roundtrip-');
        $previousConnection = DB::getDefaultConnection();
        config(['database.connections.backup_test' => array_merge(config('database.connections.sqlite'), ['database' => $path])]);
        DB::setDefaultConnection('backup_test');
        try {
            Artisan::call('migrate', ['--database' => 'backup_test', '--force' => true, '--no-interaction' => true]);
            Lead::factory()->create(['name' => 'Conservado']);
            $service = app(BackupService::class);
            $backup = $service->create();
            $this->assertSame(1, $service->verify($backup)['leads']);
            Lead::factory()->create(['name' => 'Posterior']);

            $previous = $service->restore($backup);

            $this->assertSame(['Conservado'], Lead::pluck('name')->all());
            $this->assertSame(2, $service->verify($previous)['leads']);
            File::delete([$backup, $previous]);
        } finally {
            DB::purge('backup_test');
            DB::setDefaultConnection($previousConnection);
            File::delete($path);
        }
    }
}
