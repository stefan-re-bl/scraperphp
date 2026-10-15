<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\ContactAttempt;
use App\Models\Lead;
use App\Models\LeadStatusHistory;
use App\Models\Offer;
use App\Models\Proposal;
use App\Models\Reply;
use App\Services\ExportService;
use App\Services\MetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class HistoricalScoringMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_later_score_changes_do_not_rewrite_the_historical_bucket_or_filter(): void
    {
        $this->freezeTime();
        [$lead, $campaign, $offer] = $this->context();
        $contact = $this->contact($lead, $campaign, $offer, 35, 'v1', now()->subDays(2));
        Reply::create(['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'contact_attempt_id' => $contact->id, 'category' => 'interested', 'received_at' => now()->subDay()]);
        $before = $contact->fresh()->score_snapshot;
        $lead->update(['score_total' => 99, 'status' => 'WON']);
        $result = app(MetricsService::class)->scoreEvaluation(['campaign' => $campaign->id, 'min_score' => 25, 'max_score' => 44]);
        $this->assertSame(1, $result['scores'][1]['sample_size']);
        $this->assertSame(1, $result['scores'][1]['counts']['responses']);
        $this->assertSame(0, $result['scores'][3]['sample_size']);
        $this->assertSame(0, $result['scores'][1]['counts']['won']);
        $this->assertSame($before, $contact->fresh()->score_snapshot);
        $this->assertSame(35, $result['cohorts'][0]['score_total']);
    }

    public function test_repeated_attempts_use_the_first_score_per_offer_campaign_version_and_versions_are_separate(): void
    {
        $this->freezeTime();
        [$lead, $campaign, $offer] = $this->context();
        $first = $this->contact($lead, $campaign, $offer, 30, 'v1', now()->subDays(4));
        $this->contact($lead, $campaign, $offer, 95, 'v1', now()->subDays(3));
        $new = $this->contact($lead, $campaign, $offer, 80, 'v2', now()->subDays(2));
        Reply::create(['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'contact_attempt_id' => $new->id, 'category' => 'interested', 'received_at' => now()->subDay()]);
        $result = app(MetricsService::class)->scoreEvaluation();
        $this->assertCount(2, $result['cohorts']);
        $this->assertSame($first->id, $result['cohorts'][0]['first_contact_id']);
        $this->assertSame(2, $result['cohorts'][0]['attempts']);
        $versions = collect($result['versions'])->keyBy('rule_version');
        $this->assertSame(1, $versions['v1']['sample_size']);
        $this->assertSame(1, $versions['v1']['scores'][1]['sample_size']);
        $this->assertSame(0, $versions['v1']['counts']['responses']);
        $this->assertSame(1, $versions['v2']['counts']['responses']);
        $this->assertSame(1, app(MetricsService::class)->scoreEvaluation(['rule_version' => 'v2'])['known_sample_size']);
    }

    public function test_legacy_and_explicitly_unknown_snapshots_remain_unknown_without_backfills(): void
    {
        $this->freezeTime();
        [$lead, $campaign, $offer] = $this->context();
        $lead->update(['score_total' => 100]);
        $legacy = ContactAttempt::create(['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'offer_id' => $offer->id, 'channel' => 'email', 'status' => 'sent', 'dry_run' => false, 'performed_at' => now()->subDays(2)]);
        $this->contact($lead, $campaign, $offer, null, null, now()->subDay());
        $result = app(MetricsService::class)->scoreEvaluation();
        $this->assertSame(0, $result['known_sample_size']);
        $this->assertSame(1, $result['unknown_sample_size']);
        $this->assertSame(1, $result['scores'][4]['sample_size']);
        $this->assertSame(0, $result['scores'][3]['sample_size']);
        $this->assertNull($legacy->fresh()->score_snapshot);
    }

    public function test_results_stay_in_the_correct_campaign_offer_and_version_and_do_not_use_global_won(): void
    {
        $this->freezeTime();
        [$lead, $a, $offerA] = $this->context();
        $offerB = Offer::factory()->create(['name' => 'Oferta B']);
        $b = Campaign::factory()->create(['offer_id' => $offerB->id]);
        $lead->campaigns()->attach($b, ['status' => 'WON', 'offer_id' => $offerB->id, 'won_at' => now()]);
        $lead->update(['status' => 'WON']);
        $contactA = $this->contact($lead, $a, $offerA, 20, 'v1', now()->subDays(3));
        $this->contact($lead, $b, $offerB, 80, 'v1', now()->subDays(2));
        Reply::create(['lead_id' => $lead->id, 'campaign_id' => $a->id, 'contact_attempt_id' => $contactA->id, 'category' => 'interested', 'received_at' => now()->subDay()]);
        Proposal::create(['lead_id' => $lead->id, 'campaign_id' => $b->id, 'offer_id' => $offerB->id, 'service' => 'Servicio B', 'currency' => 'ARS', 'version' => 1, 'sent_at' => now()->subDay()]);
        LeadStatusHistory::create(['lead_id' => $lead->id, 'campaign_id' => $b->id, 'to_status' => 'WON', 'changed_at' => now()]);
        $comparisons = collect(app(MetricsService::class)->scoreEvaluation()['comparisons'])->keyBy('campaign_id');
        $this->assertSame(1, $comparisons[$a->id]['counts']['responses']);
        $this->assertSame(0, $comparisons[$a->id]['counts']['won']);
        $this->assertSame(0, $comparisons[$a->id]['counts']['proposals']);
        $this->assertSame(1, $comparisons[$b->id]['counts']['won']);
        $this->assertSame(1, $comparisons[$b->id]['counts']['proposals']);
        $this->assertSame(0, $comparisons[$b->id]['counts']['responses']);
    }

    public function test_outcomes_before_contact_are_not_used_and_followups_do_not_restart_the_period_cohort(): void
    {
        $this->travelTo('2026-10-14 12:00:00');
        [$lead, $campaign, $offer] = $this->context();
        $first = $this->contact($lead, $campaign, $offer, 60, 'v1', '2026-10-01 12:00:00');
        $this->contact($lead, $campaign, $offer, 90, 'v1', '2026-10-12 12:00:00');
        Reply::create(['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'contact_attempt_id' => $first->id, 'category' => 'interested', 'received_at' => '2026-09-30 12:00:00']);
        Proposal::create(['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'offer_id' => $offer->id, 'service' => 'Web', 'currency' => 'ARS', 'version' => 1, 'sent_at' => '2026-09-30 12:00:00']);
        LeadStatusHistory::create(['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'to_status' => 'WON', 'changed_at' => '2026-09-30 12:00:00']);
        $result = app(MetricsService::class)->scoreEvaluation();
        $this->assertSame(0, $result['cohorts'][0]['responses'] ? 1 : 0);
        $this->assertFalse($result['cohorts'][0]['proposals']);
        $this->assertFalse($result['cohorts'][0]['won']);
        $this->assertSame([], app(MetricsService::class)->scoreEvaluation(['from' => '2026-10-10', 'to' => '2026-10-14'])['cohorts']);
    }

    public function test_simulated_pending_and_future_calculated_scores_do_not_provide_known_samples(): void
    {
        $this->freezeTime();
        [$lead, $campaign, $offer] = $this->context();
        $attempt = $this->contact($lead, $campaign, $offer, 60, 'v1', now()->subDay(), ['calculated_at' => now()->toISOString()]);
        $this->contact($lead, $campaign, $offer, 80, 'v1', now())->update(['dry_run' => true]);
        ContactAttempt::create(['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'offer_id' => $offer->id, 'status' => 'planned', 'channel' => 'email']);
        $result = app(MetricsService::class)->scoreEvaluation();
        $this->assertSame(0, $result['known_sample_size']);
        $this->assertSame(1, $result['unknown_sample_size']);
        $this->assertSame($attempt->id, $result['cohorts'][0]['first_contact_id']);
    }

    public function test_cli_can_filter_historical_versions_and_analytics_exports_unknown_without_using_current_score(): void
    {
        $this->freezeTime();
        [$lead, $campaign, $offer] = $this->context();
        $contact = $this->contact($lead, $campaign, $offer, 40, 'v1', now()->subDay());
        $lead->update(['score_total' => 100]);
        $this->assertSame(0, Artisan::call('sales:inspect', ['resource' => 'metrics', '--campaign' => $campaign->id, '--rule-version' => 'v1']));
        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(40, $json['data']['evaluation']['cohorts'][0]['score_total']);
        $this->assertSame('v1', $json['data']['evaluation']['versions'][0]['rule_version']);
        ob_start();
        app(ExportService::class)->analyticsCsv(['campaign' => $campaign->id])->sendContent();
        $handle = fopen('php://temp', 'w+');
        fwrite($handle, ob_get_clean());
        rewind($handle);
        $columns = fgetcsv($handle, escape: '');
        $row = array_combine($columns, fgetcsv($handle, escape: ''));
        fclose($handle);
        $this->assertSame('40', $row['historical_score']);
        $this->assertSame('1', $row['historical_score_known']);
        $this->assertSame('v1', $row['historical_score_version']);
        $this->assertSame('0', $row['current_offer_score_known']);
        $this->assertSame($contact->score_snapshot, $contact->fresh()->score_snapshot);
    }

    private function context(): array
    {
        $offer = Offer::factory()->create(['name' => 'Oferta']);
        $campaign = Campaign::factory()->create(['offer_id' => $offer->id]);
        $lead = Lead::factory()->create(['score_total' => 50]);
        $lead->campaigns()->attach($campaign, ['status' => 'CONTACTED', 'offer_id' => $offer->id]);

        return [$lead, $campaign, $offer];
    }

    private function contact(Lead $lead, Campaign $campaign, Offer $offer, ?int $total, ?string $version, mixed $at, array $override = []): ContactAttempt
    {
        $at = Carbon::parse($at);
        $snapshot = $override + ['known' => $total !== null, 'total' => $total, 'lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'offer_id' => $offer->id,
            'opportunity_id' => $lead->campaigns()->find($campaign->id)->pivot->id, 'dimensions' => [], 'evidence' => [], 'evidence_ids' => [], 'rule_version' => $version,
            'calculated_at' => $at->copy()->subMinute()->toISOString(), 'recorded_at' => $at->toISOString(), 'as_of' => $at->toISOString()];

        return ContactAttempt::create(['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'offer_id' => $offer->id, 'channel' => 'email', 'status' => 'sent', 'dry_run' => false, 'performed_at' => $at, 'score_snapshot' => $snapshot]);
    }
}
