<?php

namespace Tests\Feature;

use App\Jobs\ExecuteScrapeRun;
use App\Models\Campaign;
use App\Models\CampaignQuery;
use App\Models\ScrapeRun;
use App\Services\GoogleMapsScraperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScraperRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_search_uses_the_configured_execution_timeout(): void
    {
        Queue::fake([ExecuteScrapeRun::class]);
        config(['services.scraper.timeout' => 620]);
        $campaign = Campaign::factory()->create();
        $query = CampaignQuery::create(['campaign_id' => $campaign->id, 'query' => 'servicios', 'result_limit' => 20]);

        $run = app(GoogleMapsScraperService::class)->start($campaign, $query, false);

        $this->assertDatabaseHas('scrape_runs', ['id' => $run->id, 'status' => 'queued', 'timeout_seconds' => 620]);
        Queue::assertPushed(ExecuteScrapeRun::class, fn (ExecuteScrapeRun $job): bool => $job->runId === $run->id);
    }

    public function test_retry_replaces_the_old_timeout_with_the_current_configuration(): void
    {
        Queue::fake([ExecuteScrapeRun::class]);
        config(['services.scraper.timeout' => 600]);
        $campaign = Campaign::factory()->create();
        $run = ScrapeRun::create(['campaign_id' => $campaign->id, 'query' => 'servicios', 'status' => 'failed', 'dry_run' => false, 'timeout_seconds' => 300]);

        $this->post('/scraper/runs/'.$run->id.'/retry')->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('scrape_runs', ['id' => $run->id, 'status' => 'queued', 'timeout_seconds' => 600]);
        Queue::assertPushed(ExecuteScrapeRun::class, fn (ExecuteScrapeRun $job): bool => $job->runId === $run->id && $job->queue === 'sales');
    }
}
