<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\ContactAttempt;
use App\Models\Lead;
use App\Models\LeadOfferScore;
use App\Models\Offer;
use App\Services\CampaignMembership;
use App\Services\ExportService;
use App\Services\LeadFilterService;
use App\Services\LeadScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OfferScoreIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_uses_the_selected_pivot_offer_not_the_suggestion_and_unknown_is_not_filtered_as_known(): void
    {
        $this->freezeTime();
        $selected = Offer::factory()->create(['name' => 'Web']);
        $suggested = Offer::factory()->create(['name' => 'Turnos']);
        $campaign = Campaign::factory()->create(['offer_id' => $selected->id]);
        $high = Lead::factory()->create(['score_total' => 1, 'suggested_offer_id' => $suggested->id]);
        $low = Lead::factory()->create(['score_total' => 99]);
        $unknown = Lead::factory()->create(['score_total' => 100]);
        foreach ([$high, $low, $unknown] as $lead) {
            $lead->campaigns()->attach($campaign, ['offer_id' => $selected->id, 'status' => 'QUEUED']);
        }
        $this->score($high, $selected, 80);
        $this->score($high, $suggested, 10);
        $this->score($low, $selected, 20);
        $query = app(LeadFilterService::class)->query(['campaign' => $campaign->id]);
        $rows = $query->get()->keyBy('id');
        $this->assertSame([$high->id, $low->id, $unknown->id], $query->pluck('leads.id')->all());
        $this->assertEquals(80, $rows[$high->id]->resolved_score);
        $this->assertEquals($selected->id, $rows[$high->id]->resolved_score_offer_id);
        $this->assertSame('offer', $rows[$high->id]->resolved_score_source);
        $this->assertNull($rows[$unknown->id]->resolved_score);
        $this->assertSame('unknown', $rows[$unknown->id]->resolved_score_source);
        $filters = ['campaign' => $campaign->id, 'min_score' => 60];
        $this->assertSame([$high->id], app(LeadFilterService::class)->query($filters)->pluck('leads.id')->all());
        $this->assertSame([$high->id], app(ExportService::class)->json($filters)['data']['leads']->modelKeys());
        $this->assertSame([$low->id], app(LeadFilterService::class)->query(['campaign' => $campaign->id, 'max_score' => 30])->pluck('leads.id')->all());
    }

    public function test_global_offer_filter_is_explicit_and_legacy_without_offer_remains_distinguishable(): void
    {
        $this->freezeTime();
        $offer = Offer::factory()->create(['name' => 'Web']);
        $lead = Lead::factory()->create(['score_total' => 99]);
        $other = Lead::factory()->create(['score_total' => 80]);
        $this->score($lead, $offer, 30);
        $this->assertSame([$lead->id], app(LeadFilterService::class)->query(['score_offer_id' => $offer->id, 'max_score' => 40])->pluck('id')->all());
        $this->assertSame([], app(LeadFilterService::class)->query(['score_offer_id' => $offer->id, 'min_score' => 60])->pluck('id')->all());
        $legacyCampaign = Campaign::factory()->create();
        $lead->campaigns()->attach($legacyCampaign, ['status' => 'QUEUED']);
        $row = app(LeadFilterService::class)->query(['campaign' => $legacyCampaign->id])->firstOrFail();
        $this->assertEquals(99, $row->resolved_score);
        $this->assertSame('legacy_global', $row->resolved_score_source);
        $this->assertSame([$lead->id, $other->id], app(LeadFilterService::class)->query()->pluck('id')->all());
    }

    public function test_stale_offer_and_changed_rules_are_unknown_without_a_read_recalculating_anything(): void
    {
        $this->freezeTime();
        $offer = Offer::factory()->create(['name' => 'Web']);
        $lead = Lead::factory()->create();
        $score = $this->score($lead, $offer, 70);
        $before = $score->fresh()->getAttributes();
        $this->travel(1)->minute();
        $offer->update(['name' => 'Web actualizada']);
        $row = app(LeadFilterService::class)->query(['score_offer_id' => $offer->id])->firstOrFail();
        $this->assertNull($row->resolved_score);
        $this->assertSame('unknown', $row->resolved_score_source);
        $this->assertSame($before, $score->fresh()->getAttributes());
        $this->assertSame(1, LeadOfferScore::count());
    }

    public function test_queue_orders_and_selects_context_using_specific_offer_scores(): void
    {
        $this->freezeTime();
        $a = Offer::factory()->create(['name' => 'Web']);
        $b = Offer::factory()->create(['name' => 'Turnos']);
        $campaign = Campaign::factory()->create(['offer_id' => $a->id]);
        $second = Campaign::factory()->create(['offer_id' => $b->id]);
        $lead = Lead::factory()->create(['score_total' => 1]);
        $other = Lead::factory()->create(['score_total' => 100]);
        $lead->campaigns()->attach($campaign, ['status' => 'QUEUED', 'offer_id' => $a->id]);
        $lead->campaigns()->attach($second, ['status' => 'QUEUED', 'offer_id' => $b->id]);
        $other->campaigns()->attach($campaign, ['status' => 'QUEUED', 'offer_id' => $a->id]);
        $this->score($lead, $a, 90);
        $this->score($lead, $b, 10);
        $this->score($other, $a, 20);
        $this->get(route('queue', ['campaign' => $campaign->id]))->assertOk()->assertViewHas('leads', fn ($leads) => $leads->first()->id === $lead->id);
        $global = app(CampaignMembership::class)->ready()->orderByDesc('resolved_score')->firstOrFail();
        $this->assertSame($lead->id, $global->id);
        $this->assertEquals($campaign->id, $global->queue_campaign_id);
        $this->assertEquals(90, $global->resolved_score);
    }

    public function test_scores_and_evidence_cli_reads_do_not_modify_models_or_generate_external_work(): void
    {
        $this->freezeTime();
        $offer = Offer::factory()->create(['name' => 'Web']);
        $lead = Lead::factory()->create();
        $score = $this->score($lead, $offer, 70);
        $lead->signalRows()->create(['type' => 'clear_offer', 'value' => 'true', 'source' => 'manual', 'review_status' => 'confirmed', 'is_current' => true, 'rule_version' => 'manual', 'detected_at' => now()]);
        $before = [$lead->fresh()->getAttributes(), $score->fresh()->getAttributes(), DB::table('signals')->get()->toJson(), ContactAttempt::count()];
        foreach (['scores', 'evidence', 'metrics', 'export'] as $resource) {
            $args = ['resource' => $resource, '--ids' => (string) $lead->id];
            if ($resource === 'export') {
                $args['--dataset'] = 'offer_scores';
            }
            $this->assertSame(0, Artisan::call('sales:inspect', $args));
            $this->assertTrue(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['ok']);
        }
        $this->assertSame($before, [$lead->fresh()->getAttributes(), $score->fresh()->getAttributes(), DB::table('signals')->get()->toJson(), ContactAttempt::count()]);
    }

    private function score(Lead $lead, Offer $offer, int $total): LeadOfferScore
    {
        $evaluation = app(LeadScorer::class)->evaluateOffer($lead, $offer);

        // Fixed totals isolate ordering and filters; evidence validity comes from the actual evaluator.
        return LeadOfferScore::factory()->create(array_merge($evaluation, ['lead_id' => $lead->id, 'offer_id' => $offer->id, 'total' => $total]));
    }
}
