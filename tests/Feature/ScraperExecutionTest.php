<?php

namespace Tests\Feature;

use App\Jobs\ExecuteScrapeRun;
use App\Models\Campaign;
use App\Models\CampaignQuery;
use App\Models\ScrapeRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScraperExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_acquisition_queues_a_real_search_without_an_execution_option(): void
    {
        Queue::fake([ExecuteScrapeRun::class]);
        $campaign = Campaign::factory()->create();

        $this->post('/campaigns/'.$campaign->id.'/acquisition', ['query' => 'servicios', 'location' => 'Córdoba', 'result_limit' => 20])
            ->assertRedirect('/campaigns/'.$campaign->id.'?tab=acquisition')->assertSessionHasNoErrors();

        $run = ScrapeRun::firstOrFail();
        $this->assertFalse($run->dry_run);
        $this->assertSame('queued', $run->status);
        $this->assertDatabaseHas('campaign_queries', ['campaign_id' => $campaign->id, 'query' => 'servicios', 'result_limit' => 20]);
        Queue::assertPushed(ExecuteScrapeRun::class, fn (ExecuteScrapeRun $job): bool => $job->runId === $run->id && $job->queue === 'sales');
    }

    public function test_saved_query_queues_a_real_search_without_a_simulation_flag(): void
    {
        Queue::fake([ExecuteScrapeRun::class]);
        $campaign = Campaign::factory()->create();
        $query = CampaignQuery::create(['campaign_id' => $campaign->id, 'query' => 'servicios', 'result_limit' => 20]);

        $this->post('/scraper/run', ['campaign_query_id' => $query->id])->assertRedirect()->assertSessionHasNoErrors();

        $run = ScrapeRun::firstOrFail();
        $this->assertFalse($run->dry_run);
        $this->assertSame('queued', $run->status);
        Queue::assertPushed(ExecuteScrapeRun::class, fn (ExecuteScrapeRun $job): bool => $job->runId === $run->id);
    }

    public function test_old_simulation_requests_do_not_start_an_unexpected_real_search(): void
    {
        Queue::fake([ExecuteScrapeRun::class]);
        $campaign = Campaign::factory()->create();

        $this->post('/campaigns/'.$campaign->id.'/acquisition', ['query' => 'servicios', 'result_limit' => 20, 'dry_run' => true])
            ->assertSessionHasErrors(['dry_run' => 'La simulación de búsqueda ya no está disponible. Actualizá la página para iniciar una búsqueda real.']);

        $this->assertDatabaseCount('scrape_runs', 0);
        $this->assertDatabaseCount('campaign_queries', 0);
        Queue::assertNothingPushed();
    }

    public function test_acquisition_screens_do_not_offer_simulated_searches(): void
    {
        $campaign = Campaign::factory()->create();
        CampaignQuery::create(['campaign_id' => $campaign->id, 'query' => 'servicios', 'result_limit' => 20]);

        $this->get('/campaigns/'.$campaign->id.'?tab=acquisition')->assertOk()->assertDontSee('Simular búsqueda')->assertDontSee('name="dry_run"', false);
        $this->get('/scraper')->assertOk()->assertDontSee('name="dry_run"', false);
    }

    public function test_successful_search_logs_are_not_presented_as_errors(): void
    {
        $campaign = Campaign::factory()->create();
        ScrapeRun::create(['campaign_id' => $campaign->id, 'query' => 'servicios', 'status' => 'completed', 'phase' => 'completed', 'dry_run' => false, 'stderr' => 'Búsqueda finalizada.']);

        $this->get('/campaigns/'.$campaign->id.'?tab=acquisition')->assertOk()->assertSee('Ver registro')->assertDontSee('Ver error');
        $this->get('/scraper')->assertOk()->assertSee('Ver registro')->assertDontSee('Detalle del error');
    }

    public function test_failed_searches_keep_their_error_details_visible(): void
    {
        $campaign = Campaign::factory()->create();
        ScrapeRun::create(['campaign_id' => $campaign->id, 'query' => 'servicios', 'status' => 'failed', 'phase' => 'failed', 'dry_run' => false, 'stderr' => 'No se pudo iniciar la búsqueda.']);

        $this->get('/campaigns/'.$campaign->id.'?tab=acquisition')->assertOk()->assertSee('Ver error')->assertSee('No se pudo iniciar la búsqueda.');
        $this->get('/scraper')->assertOk()->assertSee('Detalle del error')->assertSee('No se pudo iniciar la búsqueda.');
    }
}
