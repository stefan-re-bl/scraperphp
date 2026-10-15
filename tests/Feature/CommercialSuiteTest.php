<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignQuery;
use App\Models\Exclusion;
use App\Models\Experiment;
use App\Models\ExperimentVariant;
use App\Models\ImportRun;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\MessageTemplate;
use App\Models\Offer;
use App\Services\ExperimentService;
use App\Services\GoogleMapsScraperService;
use App\Services\LeadEnricher;
use App\Services\LeadScorer;
use App\Services\LlmBridgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CommercialSuiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_scraper_dry_run_records_run_without_external_action(): void
    {
        [$campaign] = $this->fixture();
        $query = CampaignQuery::create([
            'campaign_id' => $campaign->id,
            'query' => 'psicologos',
            'location' => 'Cordoba',
            'result_limit' => 20,
            'status' => 'ready',
        ]);

        $run = app(GoogleMapsScraperService::class)->run($campaign, $query, true);

        $this->assertSame('dry_run', $run->status);
        $this->assertDatabaseHas('system_logs', ['type' => 'scraping']);
    }

    public function test_exclusion_blocks_imported_lead(): void
    {
        [$campaign] = $this->fixture();
        Exclusion::create(['type' => 'email', 'value' => 'bloqueado@test.com']);

        $csv = UploadedFile::fake()->createWithContent('leads.csv', implode("\n", [
            'name,category,location,email,phone,place_id',
            'Lead Bloqueado,Psicologia,Cordoba,bloqueado@test.com,+543510000001,blocked',
        ]));

        $this->post('/imports', [
            'campaign_id' => $campaign->id,
            'csv' => $csv,
        ])->assertRedirect('/leads');

        $this->assertDatabaseMissing('leads', [
            'email' => 'bloqueado@test.com',
        ]);
    }

    public function test_llm_bridge_validates_and_imports_suggestions(): void
    {
        [, $lead] = $this->fixture();

        $run = app(LlmBridgeService::class)->createPrompt('pain_hypothesis', [$lead->id]);
        $result = app(LlmBridgeService::class)->importJson($run, json_encode([
            'suggestions' => [[
                'lead_id' => $lead->id,
                'type' => 'pain_hypothesis',
                'payload' => ['summary' => 'Agenda manual', 'evidence' => 'Sin reserva visible'],
            ]],
        ]));

        $this->assertTrue($result['ok']);
        $this->assertDatabaseHas('llm_suggestions', [
            'lead_id' => $lead->id,
            'type' => 'pain_hypothesis',
            'status' => 'AI_SUGGESTION',
        ]);
    }

    public function test_experiment_assigns_balanced_variant(): void
    {
        [$campaign, $lead] = $this->fixture();
        $experiment = Experiment::create([
            'campaign_id' => $campaign->id,
            'name' => 'Apertura',
            'variable' => 'apertura',
            'status' => 'active',
        ]);
        ExperimentVariant::create(['experiment_id' => $experiment->id, 'name' => 'A']);
        ExperimentVariant::create(['experiment_id' => $experiment->id, 'name' => 'B']);

        $variant = app(ExperimentService::class)->assign($lead, $experiment);

        $this->assertNotNull($variant);
        $this->assertDatabaseHas('lead_experiment_variant', [
            'lead_id' => $lead->id,
            'experiment_variant_id' => $variant->id,
        ]);
    }

    public function test_segmented_metrics_and_analytics_export_are_available(): void
    {
        $this->fixture();

        $this->get('/metrics?dimension=segment')->assertOk()->assertSee('Métricas segmentadas');
        $this->get('/exports/analytics')->assertOk();
    }

    public function test_lead_filters_combine_and_keep_query_string_context(): void
    {
        [$campaign, $lead] = $this->fixture();
        $campaign->update(['offer_id' => null]);
        $this->attachLeadToCampaign($lead, $campaign);
        Lead::create([
            'name' => 'Otro rubro',
            'location' => 'Rosario',
            'country' => 'Argentina',
            'language' => 'es',
            'source' => 'google_maps',
            'status' => 'NEW',
            'score_total' => 20,
        ]);

        $this->get("/leads?campaign={$campaign->id}&city=Cordoba&min_score=60&has_email=1")
            ->assertOk()
            ->assertSee('Consultorio Norte')
            ->assertDontSee('Otro rubro')
            ->assertSee('CSV de esta vista');
    }

    public function test_filtered_lead_export_does_not_include_leads_outside_filter(): void
    {
        [$campaign, $lead] = $this->fixture();
        $campaign->update(['offer_id' => null]);
        $this->attachLeadToCampaign($lead, $campaign);
        Lead::create([
            'name' => 'Lead Fuera',
            'location' => 'Mendoza',
            'country' => 'Argentina',
            'language' => 'es',
            'source' => 'google_maps',
            'status' => 'NEW',
            'score_total' => 10,
        ]);

        $content = $this->get("/exports/csv/leads?campaign={$campaign->id}&min_score=60")->streamedContent();

        $this->assertStringContainsString('Consultorio Norte', $content);
        $this->assertStringNotContainsString('Lead Fuera', $content);
    }

    public function test_selected_lead_export_uses_selected_ids_only(): void
    {
        [, $lead] = $this->fixture();
        $outside = Lead::create([
            'name' => 'No seleccionado',
            'location' => 'Cordoba',
            'country' => 'Argentina',
            'language' => 'es',
            'source' => 'google_maps',
            'status' => 'NEW',
            'score_total' => 90,
        ]);

        $content = $this->get('/exports/csv/leads?ids='.$lead->id)->streamedContent();

        $this->assertStringContainsString('Consultorio Norte', $content);
        $this->assertStringNotContainsString($outside->name, $content);
    }

    public function test_phone_export_normalizes_argentina_numbers_and_respects_filters(): void
    {
        [$campaign, $lead] = $this->fixture();
        $campaign->update(['offer_id' => null]);
        $lead->update(['phone' => '(381) 672-2676']);
        $this->attachLeadToCampaign($lead, $campaign);

        $duplicateFormat = Lead::create([
            'name' => 'Mismo teléfono',
            'location' => 'Cordoba',
            'country' => 'Argentina',
            'language' => 'es',
            'phone' => '+54 381 672 2676',
            'source' => 'google_maps',
            'status' => 'QUEUED',
            'score_total' => 80,
        ]);
        $this->attachLeadToCampaign($duplicateFormat, $campaign);

        Lead::create([
            'name' => 'Fuera de filtro',
            'location' => 'Mendoza',
            'country' => 'Argentina',
            'language' => 'es',
            'phone' => '223 304 0391',
            'source' => 'google_maps',
            'status' => 'NEW',
            'score_total' => 10,
        ]);

        $content = $this->get("/exports/phones?campaign={$campaign->id}&min_score=60")->streamedContent();

        $this->assertSame('+543816722676', $content);
    }

    public function test_enrichment_detects_channels_booking_systems_and_operational_signals(): void
    {
        [, $lead] = $this->fixture();
        $html = <<<'HTML'
            <html>
                <head><title>Centro Psicologico Norte</title></head>
                <body>
                    Nuestro equipo de profesionales atiende varias especialidades.
                    Pedir turno por WhatsApp o reservar online con Doctoralia.
                    <a href="https://instagram.com/centronorte">Instagram</a>
                    <a href="https://facebook.com/centronorte">Facebook</a>
                    <a href="https://doctoralia.com.ar/centro-norte">Reservar online</a>
                    <a href="https://wa.me/543516000001">WhatsApp</a>
                    <form><input name="email"><textarea name="message"></textarea></form>
                    contacto@centronorte.test
                </body>
            </html>
        HTML;

        $result = app(LeadEnricher::class)->analyzeWebsiteContent($lead, 'https://centro.test', $html);
        $lead->refresh();

        $this->assertTrue((bool) $lead->booking_visible);
        $this->assertSame('booking_system', $lead->primary_cta);
        $this->assertContains('whatsapp', $result['contact_methods']);
        $this->assertContains('form', $result['contact_methods']);
        $this->assertSame('https://instagram.com/centronorte', $result['social_urls']['instagram']);
        $this->assertDatabaseHas('signals', ['lead_id' => $lead->id, 'type' => 'booking_system_visible']);
        $this->assertDatabaseHas('signals', ['lead_id' => $lead->id, 'type' => 'manual_booking_dependency']);
        $this->assertDatabaseHas('signals', ['lead_id' => $lead->id, 'type' => 'operational_complexity']);
    }

    public function test_scoring_preserves_enrichment_signals_without_changing_scoring_rules(): void
    {
        [, $lead] = $this->fixture();
        $lead->update([
            'website_url' => null,
            'phone' => '+543516000001',
            'signals' => [],
        ]);

        app(LeadEnricher::class)->enrich($lead);
        app(LeadScorer::class)->score($lead->refresh());

        $this->assertDatabaseHas('signals', ['lead_id' => $lead->id, 'type' => 'phone_format_valid']);
        $this->assertDatabaseMissing('signals', ['lead_id' => $lead->id, 'type' => 'manual_contact_dependency', 'is_current' => true]);
        $this->assertDatabaseHas('signals', ['lead_id' => $lead->id, 'type' => 'no_website', 'evidence_kind' => 'unknown']);
        $this->assertDatabaseHas('score_components', ['lead_id' => $lead->id, 'component' => 'contact_quality_phone']);
    }

    public function test_campaign_workspace_and_possible_duplicate_are_visible(): void
    {
        [$campaign, $lead] = $this->fixture();
        $this->attachLeadToCampaign($lead, $campaign);
        $duplicate = Lead::create([
            'name' => 'Sucursal Norte',
            'location' => 'Cordoba Norte',
            'country' => 'Argentina',
            'language' => 'es',
            'source' => 'google_maps',
            'status' => 'QUEUED',
            'score_total' => 65,
            'duplicate_status' => 'possible_duplicate',
            'duplicate_rule' => 'shared_domain',
            'possible_duplicate_of_lead_id' => $lead->id,
        ]);
        $this->attachLeadToCampaign($duplicate, $campaign);

        $this->get("/campaigns/{$campaign->id}")
            ->assertOk()
            ->assertSee('Obtener prospectos')
            ->assertSee('Revisar prospectos');

        $this->get("/campaigns/{$campaign->id}?tab=leads&possible_duplicate=1")
            ->assertOk()
            ->assertSee('Sucursal Norte')
            ->assertSee('Revisar duplicado');
    }

    public function test_bulk_action_sends_selected_leads_to_queue(): void
    {
        [, $lead] = $this->fixture();
        $lead->update(['status' => 'NEW']);

        $this->post('/leads/bulk', [
            'action' => 'queue',
            'selected_ids' => [$lead->id],
        ])->assertRedirect();

        $this->assertDatabaseHas('leads', [
            'id' => $lead->id,
            'status' => 'QUEUED',
        ]);
    }

    public function test_main_operational_screens_render(): void
    {
        [$campaign] = $this->fixture();

        foreach ([
            '/',
            '/campaigns',
            "/campaigns/{$campaign->id}",
            "/campaigns/{$campaign->id}?tab=acquisition",
            "/campaigns/{$campaign->id}?tab=leads",
            '/leads',
            '/contact-queue',
            "/campaigns/{$campaign->id}/pipeline",
            '/settings',
        ] as $path) {
            $this->get($path)->assertOk();
        }
    }

    private function fixture(): array
    {
        $offer = Offer::create([
            'name' => 'Gestor de consultorio/turnos',
            'segment' => 'psicologos',
        ]);
        $campaign = Campaign::create([
            'offer_id' => $offer->id,
            'name' => 'Piloto',
            'segment' => 'psicologos',
            'country' => 'Argentina',
            'city' => 'Cordoba',
            'language' => 'es',
            'source' => 'google_maps',
            'channel' => 'email',
            'mode' => 'manual',
            'daily_limit' => 20,
            'status' => 'active',
        ]);
        $lead = Lead::create([
            'suggested_offer_id' => $offer->id,
            'name' => 'Consultorio Norte',
            'category' => 'Psicologia',
            'location' => 'Cordoba',
            'country' => 'Argentina',
            'language' => 'es',
            'email' => 'hola@test.com',
            'phone' => '+543510000001',
            'source' => 'google_maps',
            'segment' => 'psicologos',
            'status' => 'QUEUED',
            'score_total' => 70,
        ]);
        MessageTemplate::create([
            'offer_id' => $offer->id,
            'name' => 'Inicial',
            'language' => 'es',
            'channel' => 'email',
            'stage' => 'initial',
            'body' => 'Hola {{negocio}}',
        ]);

        return [$campaign, $lead, $offer];
    }

    private function attachLeadToCampaign(Lead $lead, Campaign $campaign): void
    {
        $run = ImportRun::create([
            'campaign_id' => $campaign->id,
            'source' => 'google_maps',
            'query' => 'psicologos cordoba',
            'segment' => $campaign->segment,
            'location' => $campaign->city,
        ]);

        LeadSource::create([
            'lead_id' => $lead->id,
            'import_run_id' => $run->id,
            'source' => 'google_maps',
            'query' => 'psicologos cordoba',
            'observed_at' => now(),
        ]);
    }
}
