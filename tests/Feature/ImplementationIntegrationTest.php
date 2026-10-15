<?php

namespace Tests\Feature;

use App\Jobs\ExecuteScrapeRun;
use App\Jobs\ImportLeadFile;
use App\Models\Campaign;
use App\Models\CampaignQuery;
use App\Models\ContactAttempt;
use App\Models\Experiment;
use App\Models\ExperimentVariant;
use App\Models\ImportRun;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\Offer;
use App\Services\CampaignMembership;
use App\Services\ExperimentService;
use App\Services\GoogleMapsScraperService;
use App\Services\LeadImporter;
use App\Services\MetricsService;
use App\Services\TemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ImplementationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_acquisition_preview_import_enrichment_duplicate_review_and_sales_flow(): void
    {
        $this->travelTo(now()->startOfSecond());
        Queue::fake([ExecuteScrapeRun::class, ImportLeadFile::class]);
        Http::fake(['*' => Http::response('<title>Servicios comerciales</title><p>Contactanos por email para consultar nuestros servicios.</p>')]);
        $offer = Offer::create(['name' => 'Sitio web', 'active' => true, 'benefits' => 'Mostrar los servicios']);
        $campaign = Campaign::factory()->create(['source' => 'google_maps', 'offer_id' => $offer->id]);
        $query = CampaignQuery::create(['campaign_id' => $campaign->id, 'query' => 'Servicios sintéticos', 'result_limit' => 10]);
        $scraper = app(GoogleMapsScraperService::class)->start($campaign, $query, false);
        Queue::assertPushed(ExecuteScrapeRun::class);
        $content = "name,location,website,email,phone,place_id\nSucursal Centro,Calle Centro,https://empresa.test,info@empresa.test,+543516123456,place-centro\nSucursal Norte,Calle Norte,https://empresa.test,info@empresa.test,+543516123456,place-norte\n";
        file_put_contents($scraper->output_file, $content);
        $file = UploadedFile::fake()->createWithContent('maps.csv', $content);
        $response = $this->post('/imports/preview', ['campaign_id' => $campaign->id, 'source' => 'google_maps', 'csv' => $file])->assertOk();
        $this->assertDatabaseCount('leads', 0);
        $analysis = $response->viewData('analysis');
        $this->assertSame(2, $analysis['valid']);
        $this->post('/imports/confirm', ['token' => $response->viewData('token'), 'analysis_hash' => $analysis['analysis_hash']])->assertSessionHasNoErrors()->assertRedirect();
        $run = ImportRun::firstOrFail();
        Queue::assertPushed(ImportLeadFile::class);
        $run->update(['scrape_run_id' => $scraper->id]);
        $completedScrape = app(GoogleMapsScraperService::class)->importExistingOutput($scraper);
        $this->assertSame('completed', $completedScrape->status);
        (new ImportLeadFile($run->id))->handle(app(LeadImporter::class));
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertDatabaseCount('leads', 2);
        $this->assertSame(1, $run->fresh()->possible_duplicate_count);
        $center = Lead::where('source_identifier', 'place-centro')->firstOrFail();
        $north = Lead::where('source_identifier', 'place-norte')->firstOrFail();
        $this->assertNotNull($center->enriched_at);
        $this->assertNotNull($north->enriched_at);
        $this->assertTrue($center->score_total >= 45);
        $this->assertFalse(app(CampaignMembership::class)->ready($campaign)->whereKey($north->id)->exists());
        $this->get('/leads/'.$north->id.'/whatsapp?campaign_id='.$campaign->id)->assertSessionHasErrors('lead_id');
        $this->post('/leads/'.$north->id.'/distinct')->assertRedirect();
        $this->post('/leads/'.$north->id.'/status', ['status' => 'QUEUED', 'campaign_id' => $campaign->id])->assertSessionHasNoErrors();
        $this->assertTrue(app(CampaignMembership::class)->ready($campaign)->whereKey($north->id)->exists());
        $template = MessageTemplate::create(['name' => 'Inicial', 'channel' => 'email', 'language' => 'es', 'stage' => 'initial', 'active' => true, 'offer_id' => $offer->id, 'body' => 'Hola {{negocio}}']);
        $experiment = Experiment::create(['campaign_id' => $campaign->id, 'name' => 'Apertura', 'variable' => 'opening', 'status' => 'active']);
        $variant = ExperimentVariant::create(['experiment_id' => $experiment->id, 'name' => 'A', 'message_template_id' => $template->id, 'content' => 'Mensaje A para {{negocio}}']);
        app(ExperimentService::class)->assign($center, $experiment);
        $this->get('/contact-queue?campaign='.$campaign->id.'&lead='.$center->id)->assertOk()->assertSee('Mensaje A para Sucursal Centro');
        $message = app(TemplateRenderer::class)->render(app(ExperimentService::class)->template($variant), $center, $offer);
        $this->post('/contact-attempts', ['lead_id' => $center->id, 'campaign_id' => $campaign->id, 'channel' => 'email', 'step' => 'initial', 'performed_at' => now()->toIso8601String(), 'message_template_id' => $template->id, 'message_snapshot' => $message['body'], 'preparation_seconds' => 30])->assertSessionHasNoErrors();
        $attempt = ContactAttempt::where('lead_id', $center->id)->where('status', 'sent')->firstOrFail();
        $this->assertSame($variant->id, $attempt->experiment_variant_id);
        $this->assertDatabaseHas('contact_attempts', ['lead_id' => $center->id, 'status' => 'planned', 'step' => 'follow_up_1']);
        $this->post('/replies', ['lead_id' => $center->id, 'campaign_id' => $campaign->id, 'contact_attempt_id' => $attempt->id, 'category' => 'interested', 'received_at' => now()->toIso8601String()])->assertSessionHasNoErrors();
        $this->post('/proposals', ['lead_id' => $center->id, 'campaign_id' => $campaign->id, 'offer_id' => $offer->id, 'service' => 'Sitio web', 'amount' => '250', 'currency' => 'ARS', 'version' => 1, 'result' => 'won', 'sent_at' => now()->toIso8601String()])->assertSessionHasNoErrors();
        $this->assertTrue($center->fresh()->is_customer);
        $this->assertSame('WON', app(CampaignMembership::class)->status($center, $campaign));
        $this->assertSame(1, app(MetricsService::class)->funnel(['campaign' => $campaign->id])['counts']['won']);
        $this->get('/leads/'.$center->id.'?history_campaign='.$campaign->id)->assertOk()->assertSee($campaign->name)->assertSee('Ganado');
        $counts = ['leads' => Lead::count(), 'attempts' => ContactAttempt::count(), 'runs' => ImportRun::count()];
        $this->assertSame(0, Artisan::call('sales:inspect', ['resource' => 'leads', '--campaign' => $campaign->id, '--filters' => json_encode(['q' => 'Sucursal Centro'])]));
        $data = json_decode(Artisan::output(), true);
        $this->assertSame([$center->id], array_column($data['data'], 'id'));
        $this->assertSame($counts, ['leads' => Lead::count(), 'attempts' => ContactAttempt::count(), 'runs' => ImportRun::count()]);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_existing_customer_opportunity_can_be_enabled_only_by_explicit_action(): void
    {
        $campaign = Campaign::factory()->create();
        $lead = Lead::factory()->create(['is_customer' => true]);
        app(CampaignMembership::class)->attach($lead, $campaign);
        $lead->campaigns()->updateExistingPivot($campaign->id, ['status' => 'QUEUED']);
        $this->assertFalse(app(CampaignMembership::class)->ready($campaign)->whereKey($lead->id)->exists());
        $this->get('/leads/'.$lead->id)->assertOk()->assertSee('Habilitar contacto de esta oportunidad');
        $this->post('/leads/'.$lead->id.'/campaigns', ['campaign_id' => $campaign->id])->assertSessionHasNoErrors();
        $this->assertTrue(app(CampaignMembership::class)->ready($campaign)->whereKey($lead->id)->exists());
        $this->assertDatabaseCount('contact_attempts', 0);
    }

    public function test_acquisition_tab_query_count_does_not_grow_with_metric_groups(): void
    {
        $counts = [];
        foreach ([1, 25] as $size) {
            $campaign = Campaign::factory()->create();
            for ($i = 0; $i < $size; $i++) {
                $lead = Lead::factory()->create(['city' => 'Ciudad '.$i]);
                app(CampaignMembership::class)->attach($lead, $campaign);
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            $started = microtime(true);
            $this->get('/campaigns/'.$campaign->id.'?tab=acquisition')->assertOk()->assertViewHas('metricRows', [])->assertViewHas('stats', null);
            $counts[$size] = ['queries' => count(DB::getQueryLog()), 'milliseconds' => round((microtime(true) - $started) * 1000, 2)];
            DB::disableQueryLog();
        }
        $this->assertLessThanOrEqual(15, $counts[25]['queries']);
        $this->assertSame($counts[1]['queries'], $counts[25]['queries']);
        fwrite(STDERR, 'PERFORMANCE '.json_encode($counts).PHP_EOL);
    }
}
