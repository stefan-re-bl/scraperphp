<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Lead;
use App\Models\Offer;
use App\Models\ScoringRule;
use App\Models\Signal;
use App\Services\CampaignMembership;
use App\Services\ExclusionService;
use App\Services\LeadScorer;
use App\Services\OfferMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OfferScoringTest extends TestCase
{
    use RefreshDatabase;

    private function signal(Lead $lead, string $type, string $value = 'true', array $overrides = []): Signal
    {
        return $lead->signalRows()->create($overrides + ['type' => $type, 'value' => $value, 'source' => 'website', 'source_reference' => 'https://business.test/services',
            'extraction_method' => 'structured_data', 'evidence' => 'Observación sintética', 'evidence_kind' => 'observed', 'review_status' => 'confirmed',
            'confidence' => 0.9, 'detected_at' => now(), 'expires_at' => now()->addDays(30), 'is_current' => true, 'rule_version' => 'evidence-v1']);
    }

    public function test_offer_profiles_produce_distinct_explained_scores_for_the_same_business(): void
    {
        $lead = Lead::factory()->create(['name' => 'Centro sintético', 'category' => 'Consultorio', 'segment' => 'salud', 'country' => 'Argentina',
            'email' => 'hola@business.test', 'phone' => '+543516001111', 'website_url' => 'https://business.test', 'source' => 'google_maps', 'source_identifier' => 'synthetic-center']);
        $booking = Offer::factory()->create(['rules' => ['scoring_profile' => 'booking', 'keywords' => ['salud']]]);
        $web = Offer::factory()->create(['rules' => ['scoring_profile' => 'web', 'keywords' => ['salud']]]);
        $shop = Offer::factory()->create(['rules' => ['scoring_profile' => 'ecommerce', 'keywords' => ['productos']]]);
        $business = $this->signal($lead, 'booking_business');
        $manual = $this->signal($lead, 'manual_booking_dependency', 'true', ['evidence_kind' => 'inferred']);
        $this->signal($lead, 'website_verified');
        $this->signal($lead, 'website_functional');
        app(LeadScorer::class)->score($lead);
        $scores = $lead->offerScores()->get()->keyBy('offer_id');
        $this->assertSame([87, ['fit' => 80, 'need' => 100, 'contact' => 100, 'evidence' => 58]], [$scores[$booking->id]->total, $scores[$booking->id]->dimensions]);
        $this->assertSame([48, ['fit' => 70, 'need' => 0, 'contact' => 100, 'evidence' => 55]], [$scores[$web->id]->total, $scores[$web->id]->dimensions]);
        $this->assertSame([22, ['fit' => 0, 'need' => 0, 'contact' => 100, 'evidence' => 44]], [$scores[$shop->id]->total, $scores[$shop->id]->dimensions]);
        $this->assertGreaterThan($scores[$web->id]->total, $scores[$booking->id]->total);
        $this->assertGreaterThan($scores[$shop->id]->total, $scores[$booking->id]->total);
        $this->assertSame(100, $scores[$booking->id]->dimensions['need']);
        $this->assertSame(0, $scores[$web->id]->dimensions['need']);
        $this->assertContains($manual->id, $scores[$booking->id]->explanation['evidence_ids']);
        $this->assertContains($business->id, $scores[$booking->id]->explanation['evidence_ids']);
        $this->assertSame('booking', $scores[$booking->id]->explanation['profile']);
        $this->assertLessThanOrEqual(100, $scores[$booking->id]->total);
        $this->assertGreaterThan(0, $scores[$booking->id]->confidence);
    }

    public function test_unknown_website_and_transport_failures_do_not_create_need_or_penalties(): void
    {
        $lead = Lead::factory()->create(['website_url' => null]);
        $offer = Offer::factory()->create(['rules' => ['scoring_profile' => 'web']]);
        $before = app(LeadScorer::class)->evaluateOffer($lead, $offer);
        $this->signal($lead, 'no_website', 'unknown', ['evidence_kind' => 'unknown']);
        $this->signal($lead, 'website_unreachable', 'unknown', ['evidence_kind' => 'unknown']);
        $after = app(LeadScorer::class)->evaluateOffer($lead, $offer);
        $this->assertSame($before['total'], $after['total']);
        $this->assertSame(0, $after['dimensions']['need']);
        app(LeadScorer::class)->score($lead);
        $this->assertDatabaseMissing('score_components', ['lead_id' => $lead->id, 'component' => 'problem_no_website']);
        $this->assertNotEmpty($after['explanation']['unknowns']);
    }

    public function test_crm_outcomes_are_context_only_even_when_an_offer_or_weight_mentions_them(): void
    {
        $lead = Lead::factory()->create(['category' => null, 'segment' => null]);
        $offer = Offer::factory()->create(['rules' => ['scoring_profile' => 'generic', 'signals' => ['crm_history_available']]]);
        $scorer = app(LeadScorer::class);
        $before = $scorer->evaluateOffer($lead, $offer);
        $this->signal($lead, 'crm_history_available', 'true', ['source' => 'crm', 'metadata' => ['not_a_score_predictor' => true]]);
        ScoringRule::create(['component' => 'signal_crm_history_available', 'points' => 90, 'weight' => 1, 'dimension' => 'fit', 'active' => true]);
        $after = $scorer->evaluateOffer($lead, $offer);
        $this->assertSame($before['total'], $after['total']);
        $this->assertSame(0, $after['dimensions']['fit']);
        $scorer->score($lead);
        $this->assertDatabaseMissing('score_components', ['lead_id' => $lead->id, 'component' => 'signal_crm_history_available']);
    }

    public function test_pending_expired_and_retired_signals_do_not_influence_scoring(): void
    {
        $lead = Lead::factory()->create();
        $offer = Offer::factory()->create(['rules' => ['scoring_profile' => 'booking']]);
        $baseline = app(LeadScorer::class)->evaluateOffer($lead, $offer);
        $this->signal($lead, 'manual_booking_dependency', 'true', ['review_status' => 'pending']);
        $this->signal($lead, 'booking_business', 'true', ['expires_at' => now()->subSecond()]);
        $this->signal($lead, 'operational_complexity', 'true', ['is_current' => false]);
        $result = app(LeadScorer::class)->evaluateOffer($lead, $offer);
        $this->assertSame($baseline['total'], $result['total']);
        $this->assertSame([], $result['explanation']['evidence_ids']);
    }

    public function test_duplicates_and_signals_from_the_same_page_do_not_add_points_or_corroboration(): void
    {
        $lead = Lead::factory()->create();
        $offer = Offer::factory()->create(['rules' => ['scoring_profile' => 'booking']]);
        $this->signal($lead, 'booking_business');
        $this->signal($lead, 'manual_booking_dependency');
        $first = app(LeadScorer::class)->evaluateOffer($lead, $offer);
        $this->signal($lead, 'manual_booking_dependency');
        $this->signal($lead, 'booking_business', 'true', ['source' => 'http']);
        $second = app(LeadScorer::class)->evaluateOffer($lead, $offer);
        $this->assertSame($first['total'], $second['total']);
        $this->assertSame($first['confidence'], $second['confidence']);
        $this->assertSame(1, $second['explanation']['independent_sources']);
        $this->assertCount(2, $second['explanation']['evidence_ids']);
    }

    public function test_dns_http_and_website_observations_share_the_same_origin_group(): void
    {
        $lead = Lead::factory()->create();
        $offer = Offer::factory()->create(['rules' => ['scoring_profile' => 'booking']]);
        $this->signal($lead, 'booking_business');
        $this->signal($lead, 'manual_booking_dependency', 'true', ['source' => 'dns_http']);
        $result = app(LeadScorer::class)->evaluateOffer($lead, $offer);
        $this->assertSame(1, $result['explanation']['independent_sources']);
        $this->assertSame(0.775, $result['confidence']);
    }

    public function test_contradictory_observations_and_identity_conflicts_cannot_generate_need(): void
    {
        $lead = Lead::factory()->create(['category' => null, 'segment' => null]);
        $offer = Offer::factory()->create(['rules' => ['scoring_profile' => 'booking']]);
        $this->signal($lead, 'manual_booking_dependency');
        $this->signal($lead, 'manual_booking_dependency', 'false', ['source_reference' => 'https://business.test/contact']);
        $contradictory = app(LeadScorer::class)->evaluateOffer($lead, $offer);
        $this->assertSame(0, $contradictory['dimensions']['need']);
        $this->assertNotEmpty($contradictory['explanation']['contradictions']);
        $this->signal($lead, 'booking_business');
        $this->signal($lead, 'identity_conflict', 'true', ['evidence_kind' => 'conflict', 'review_status' => 'pending']);
        $result = app(LeadScorer::class)->evaluateOffer($lead, $offer);
        $this->assertSame(0, $result['dimensions']['fit']);
        $this->assertSame(0, $result['dimensions']['need']);
    }

    public function test_complete_fields_alone_do_not_generate_maximum_product_priority(): void
    {
        $lead = Lead::factory()->create(['category' => null, 'segment' => null, 'source_identifier' => 'synthetic-id']);
        $offer = Offer::factory()->create(['rules' => ['scoring_profile' => 'ecommerce']]);
        $result = app(LeadScorer::class)->evaluateOffer($lead, $offer);
        $this->assertSame(0, $result['dimensions']['fit']);
        $this->assertSame(0, $result['dimensions']['need']);
        $this->assertLessThan(30, $result['total']);
    }

    public function test_active_and_explicit_archived_offers_are_scored_without_changing_opportunities(): void
    {
        $archived = Offer::factory()->create(['active' => false]);
        $active = Offer::factory()->create();
        $campaign = Campaign::factory()->create(['offer_id' => $archived->id]);
        $lead = Lead::factory()->create(['suggested_offer_id' => $archived->id]);
        app(CampaignMembership::class)->attach($lead, $campaign, true);
        $pivot = $lead->campaigns()->first()->pivot->getAttributes();
        app(LeadScorer::class)->score($lead);
        $this->assertCount(2, $lead->offerScores);
        $this->assertSame($archived->id, $lead->fresh()->suggested_offer_id);
        $this->assertSame($pivot, $lead->campaigns()->first()->pivot->getAttributes());
        $this->assertDatabaseHas('lead_offer_scores', ['lead_id' => $lead->id, 'offer_id' => $active->id]);
    }

    public function test_read_evaluation_does_not_create_rules_or_scores_and_recalculations_are_idempotent(): void
    {
        $lead = Lead::factory()->create();
        $offer = Offer::factory()->create();
        $scorer = app(LeadScorer::class);
        $scorer->evaluateOffer($lead, $offer);
        $this->assertDatabaseCount('lead_offer_scores', 0);
        $this->assertDatabaseCount('scoring_rules', 0);
        $scorer->score($lead);
        $first = $lead->offerScores()->firstOrFail();
        $scorer->score($lead);
        $this->assertDatabaseCount('lead_offer_scores', 1);
        $this->assertSame($first->total, $first->fresh()->total);
    }

    public function test_current_scope_detects_same_second_input_changes_and_rules_versions_but_ignores_crm_outcomes(): void
    {
        $this->travelTo(now()->startOfSecond());
        $lead = Lead::factory()->create();
        $offer = Offer::factory()->create();
        app(LeadScorer::class)->score($lead);
        $row = $lead->offerScores()->firstOrFail();
        $this->assertTrue($row->isFresh());
        $lead->update(['status' => 'WON', 'notes' => 'Resultado comercial']);
        $this->assertTrue($row->isFresh());
        $lead->update(['email' => null]);
        $this->assertFalse($row->isFresh());
        app(LeadScorer::class)->score($lead);
        $this->assertTrue($row->refresh()->isFresh());
        config(['commercial_scoring.version' => 'commercial-v2']);
        $this->assertFalse($row->isFresh());
        $this->assertNull(app(LeadScorer::class)->forOffer($lead, $offer));
    }

    public function test_current_scope_detects_same_second_offer_evidence_and_rule_changes(): void
    {
        $this->travelTo(now()->startOfSecond());
        $lead = Lead::factory()->create();
        $offer = Offer::factory()->create(['rules' => ['scoring_profile' => 'booking']]);
        $signal = $this->signal($lead, 'booking_business');
        $scorer = app(LeadScorer::class);
        $scorer->score($lead);
        $row = $lead->offerScores()->firstOrFail();
        $this->assertTrue($row->isFresh());
        $offer->update(['rules' => ['scoring_profile' => 'web']]);
        $this->assertFalse($row->isFresh());
        $scorer->score($lead);
        $signal->update(['confidence' => 0.5, 'source_reference' => 'https://other.test/contact']);
        $this->assertFalse($row->refresh()->isFresh());
        $scorer->score($lead);
        $signal->update(['evidence_kind' => 'inferred']);
        $this->assertFalse($row->refresh()->isFresh());
        $scorer->score($lead);
        ScoringRule::where('component', 'contact_quality_email')->update(['points' => 5]);
        $this->assertFalse($row->refresh()->isFresh());
    }

    public function test_new_confirmed_evidence_added_in_the_same_second_invalidates_a_score(): void
    {
        $this->travelTo(now()->startOfSecond());
        $lead = Lead::factory()->create();
        $offer = Offer::factory()->create(['rules' => ['scoring_profile' => 'booking']]);
        app(LeadScorer::class)->score($lead);
        $row = $lead->offerScores()->firstOrFail();
        $this->signal($lead, 'manual_booking_dependency');
        $this->assertFalse($row->isFresh());
        app(LeadScorer::class)->score($lead);
        $this->assertTrue($row->refresh()->isFresh());
        $this->assertSame(100, $row->dimensions['need']);
    }

    public function test_expiration_invalidates_scores_then_new_calculation_excludes_expired_evidence(): void
    {
        $this->travelTo(now()->startOfSecond());
        $lead = Lead::factory()->create();
        $offer = Offer::factory()->create(['rules' => ['scoring_profile' => 'booking']]);
        $this->signal($lead, 'manual_booking_dependency', 'true', ['expires_at' => now()->addSecond()]);
        app(LeadScorer::class)->score($lead);
        $row = $lead->offerScores()->firstOrFail();
        $this->assertTrue($row->isFresh());
        $this->travel(2)->seconds();
        $this->assertFalse($row->isFresh());
        app(LeadScorer::class)->score($lead);
        $this->assertTrue($row->refresh()->isFresh());
        $this->assertSame(0, $row->dimensions['need']);
    }

    public function test_existing_booking_solution_caps_need_without_inventing_absence_or_error_penalties(): void
    {
        $lead = Lead::factory()->create();
        $offer = Offer::factory()->create(['rules' => ['scoring_profile' => 'booking']]);
        $this->signal($lead, 'manual_booking_dependency');
        $this->signal($lead, 'booking_system_visible');
        $this->assertSame(35, app(LeadScorer::class)->evaluateOffer($lead, $offer)['dimensions']['need']);
    }

    public function test_weight_changes_are_versioned_and_blocked_identity_remains_blocked(): void
    {
        $lead = Lead::factory()->create(['duplicate_status' => 'possible_duplicate']);
        $offer = Offer::factory()->create(['rules' => ['scoring_profile' => 'booking']]);
        $this->signal($lead, 'manual_booking_dependency');
        $this->signal($lead, 'booking_business');
        $old = app(LeadScorer::class)->evaluateOffer($lead, $offer);
        config(['commercial_scoring.profiles.booking.need.manual_booking_dependency' => 50]);
        $new = app(LeadScorer::class)->evaluateOffer($lead, $offer);
        $this->assertNotSame($old['rule_version'], $new['rule_version']);
        $this->assertGreaterThan($new['total'], $old['total']);
        $this->expectException(ValidationException::class);
        app(ExclusionService::class)->assertContactable($lead);
    }

    public function test_per_offer_legacy_contact_rules_keep_their_configured_weight(): void
    {
        $lead = Lead::factory()->create(['phone' => null]);
        $offer = Offer::factory()->create();
        ScoringRule::create(['offer_id' => $offer->id, 'component' => 'contact_quality_email', 'points' => 10, 'weight' => 2, 'active' => true, 'dimension' => 'contact']);
        $result = app(LeadScorer::class)->evaluateOffer($lead, $offer);
        $this->assertSame(44, $result['dimensions']['contact']);
    }

    public function test_recommendation_ties_are_stable_and_only_compatible_offers_are_eligible(): void
    {
        $lead = Lead::factory()->create(['segment' => 'salud', 'category' => 'Consultorio']);
        $first = Offer::factory()->create(['rules' => ['scoring_profile' => 'booking', 'keywords' => ['salud']]]);
        Offer::factory()->create(['rules' => ['scoring_profile' => 'booking', 'keywords' => ['salud']]]);
        $this->signal($lead, 'booking_business');
        $this->assertSame($first->id, app(OfferMatcher::class)->match($lead)->id);
    }
}
