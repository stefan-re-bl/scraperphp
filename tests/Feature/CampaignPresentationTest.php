<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Lead;
use App\Services\CampaignMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_summary_opens_its_queue_when_contacts_are_ready(): void
    {
        $campaign = Campaign::factory()->create();
        $lead = Lead::factory()->create(['status' => 'QUEUED']);
        app(CampaignMembership::class)->attach($lead, $campaign);

        $response = $this->get('/campaigns/'.$campaign->id);

        $response->assertOk()->assertViewHas('stats', fn (array $stats): bool => $stats['queue'] === 1);
        $response->assertSee('/contact-queue?campaign='.$campaign->id, false);
    }
}
