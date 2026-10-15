<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\ContactAttempt;
use App\Models\Lead;
use App\Models\Offer;
use App\Services\CampaignMembership;
use App\Services\LeadScorer;
use App\Services\MetricsService;
use App\Services\ScoreSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ContactScoreSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
    }

    private function attempt(array $attributes = [], array $leadData = []): ContactAttempt
    {
        $offer = Offer::factory()->create(['rules' => ['scoring_profile' => 'booking', 'keywords' => ['salud']]]);
        $campaign = Campaign::factory()->create(['offer_id' => $offer->id]);
        $lead = Lead::factory()->create($leadData + ['category' => 'Consultorio', 'segment' => 'salud']);
        app(CampaignMembership::class)->attach($lead, $campaign, true);

        return ContactAttempt::create($attributes + ['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'offer_id' => $offer->id,
            'channel' => 'email', 'status' => 'sent', 'dry_run' => false, 'performed_at' => now(), 'step' => 'initial']);
    }

    public function test_real_contact_captures_offer_specific_local_knowledge_without_score_writes(): void
    {
        $attempt = $this->attempt();
        $signal = $attempt->lead->signalRows()->create(['type' => 'manual_booking_dependency', 'value' => 'true', 'source' => 'manual',
            'source_reference' => 'manual-review', 'extraction_method' => 'human_review', 'confidence' => 1, 'detected_at' => now(), 'expires_at' => null,
            'review_status' => 'confirmed', 'evidence_kind' => 'inferred', 'is_current' => true, 'rule_version' => 'human-v1', 'evidence' => 'Fricción confirmada sintética.']);
        app(ScoreSnapshotService::class)->capture($attempt);
        $snapshot = $attempt->fresh()->score_snapshot;
        $this->assertTrue($snapshot['known']);
        $this->assertSame($attempt->offer_id, $snapshot['offer_id']);
        $this->assertSame($attempt->lead_id, $snapshot['lead_id']);
        $this->assertSame($attempt->campaign_id, $snapshot['campaign_id']);
        $this->assertNotNull($snapshot['opportunity_id']);
        $this->assertContains($signal->id, $snapshot['evidence_ids']);
        $this->assertSame(100, $snapshot['dimensions']['need']);
        $this->assertSame(LeadScorer::ruleVersion(), $snapshot['rule_version']);
        foreach (['input_state', 'offer_state', 'signal_state', 'rule_state'] as $internalState) {
            $this->assertArrayNotHasKey($internalState, $snapshot['explanation']);
        }
        $this->assertDatabaseCount('lead_offer_scores', 0);
    }

    public function test_subsecond_calculation_uses_the_database_event_precision_in_metrics(): void
    {
        $this->travelTo(now()->startOfSecond()->addMicroseconds(700000));
        $attempt = $this->attempt();
        app(ScoreSnapshotService::class)->capture($attempt);
        $snapshot = $attempt->score_snapshot;
        $this->assertTrue($snapshot['known']);
        $this->assertTrue(Carbon::parse($snapshot['calculated_at'])->lessThanOrEqualTo($attempt->performed_at));
        $this->assertTrue(app(MetricsService::class)->validSnapshot($attempt, $snapshot));
    }

    public function test_snapshot_is_created_before_smtp_and_failed_send_keeps_it_for_audit(): void
    {
        $attempt = $this->attempt(['status' => 'sending', 'performed_at' => null]);
        app(ScoreSnapshotService::class)->capture($attempt);
        $snapshot = $attempt->score_snapshot;
        $this->assertTrue($snapshot['known']);
        $this->assertNotNull($snapshot['calculated_at']);
        $attempt->update(['status' => 'failed', 'result' => 'SMTP simulated failure']);
        $this->assertSame($snapshot, $attempt->fresh()->score_snapshot);
    }

    public function test_later_recalculation_does_not_change_an_existing_snapshot(): void
    {
        $attempt = $this->attempt();
        app(ScoreSnapshotService::class)->capture($attempt);
        $snapshot = $attempt->score_snapshot;
        $this->travel(2)->hours();
        $attempt->lead->update(['email' => null, 'phone' => null, 'category' => 'Comercio', 'segment' => 'productos']);
        app(LeadScorer::class)->score($attempt->lead);
        app(ScoreSnapshotService::class)->capture($attempt);
        $this->assertSame($snapshot, $attempt->fresh()->score_snapshot);
    }

    public function test_backdated_contact_before_the_known_calculation_is_unknown_and_not_reconstructed(): void
    {
        $attempt = $this->attempt(['performed_at' => now()->subDay()]);
        app(LeadScorer::class)->score($attempt->lead);
        app(ScoreSnapshotService::class)->capture($attempt);
        $snapshot = $attempt->score_snapshot;
        $this->assertFalse($snapshot['known']);
        $this->assertNull($snapshot['total']);
        $this->assertNull($snapshot['calculated_at']);
        $this->assertStringContainsString('No se reconstruyen', $snapshot['reason']);
    }

    public function test_backdated_contact_may_reference_an_actual_preserved_calculation_before_it(): void
    {
        $attempt = $this->attempt(['status' => 'planned', 'performed_at' => null]);
        app(LeadScorer::class)->score($attempt->lead);
        $recordedScore = $attempt->lead->offerScores()->firstOrFail();
        $this->travel(10)->minutes();
        $attempt->update(['status' => 'sent', 'performed_at' => now()->subMinutes(5)]);
        app(ScoreSnapshotService::class)->capture($attempt);
        $this->assertTrue($attempt->score_snapshot['known']);
        $this->assertSame($recordedScore->calculated_at->toIso8601String(), $attempt->score_snapshot['calculated_at']);
    }

    public function test_legacy_contact_is_unknown_even_if_a_new_score_exists(): void
    {
        $attempt = $this->attempt(['created_at' => now()->subDays(10), 'updated_at' => now()->subDays(10), 'performed_at' => now()->subDays(10)]);
        app(LeadScorer::class)->score($attempt->lead);
        app(ScoreSnapshotService::class)->capture($attempt);
        $this->assertFalse($attempt->score_snapshot['known']);
        $this->assertNull($attempt->score_snapshot['total']);
        $this->assertStringContainsString('Contacto histórico', $attempt->score_snapshot['reason']);
    }

    public function test_simulations_planned_failed_and_unperformed_contacts_have_no_score_snapshot(): void
    {
        foreach ([['dry_run' => true], ['status' => 'planned'], ['status' => 'failed'], ['performed_at' => null]] as $attributes) {
            $attempt = $this->attempt($attributes);
            app(ScoreSnapshotService::class)->capture($attempt);
            $this->assertNull($attempt->fresh()->score_snapshot);
        }
    }

    public function test_missing_evidence_is_explicitly_unknown_instead_of_fabricating_a_precise_score(): void
    {
        $attempt = $this->attempt([], ['category' => null, 'segment' => null, 'email' => null, 'phone' => null, 'website_url' => null]);
        app(ScoreSnapshotService::class)->capture($attempt);
        $this->assertFalse($attempt->score_snapshot['known']);
        $this->assertNull($attempt->score_snapshot['total']);
    }

    public function test_snapshot_cannot_be_modified_or_cleared_by_normal_model_updates(): void
    {
        $attempt = $this->attempt();
        app(ScoreSnapshotService::class)->capture($attempt);
        $snapshot = $attempt->score_snapshot;
        try {
            $attempt->update(['score_snapshot' => null]);
            $this->fail('El snapshot debe ser inmutable.');
        } catch (ValidationException) {
            $this->assertSame($snapshot, $attempt->fresh()->score_snapshot);
        }
        $this->expectException(ValidationException::class);
        $attempt->update(['score_snapshot' => ['known' => true, 'total' => 100]]);
    }

    public function test_stale_model_cannot_overwrite_a_snapshot_captured_by_another_caller(): void
    {
        $attempt = $this->attempt();
        $stale = ContactAttempt::findOrFail($attempt->id);
        app(ScoreSnapshotService::class)->capture($attempt);
        $this->expectException(ValidationException::class);
        $stale->update(['score_snapshot' => ['known' => true, 'total' => 100]]);
    }

    public function test_future_observations_are_not_used_for_the_contact_snapshot(): void
    {
        $attempt = $this->attempt();
        $signal = $attempt->lead->signalRows()->create(['type' => 'manual_booking_dependency', 'value' => 'true', 'source' => 'manual',
            'confidence' => 1, 'detected_at' => now()->addDay(), 'review_status' => 'confirmed', 'evidence_kind' => 'observed', 'is_current' => true]);
        app(ScoreSnapshotService::class)->capture($attempt);
        $this->assertNotContains($signal->id, $attempt->score_snapshot['evidence_ids']);
        $this->assertSame(0, $attempt->score_snapshot['dimensions']['need']);
    }
}
