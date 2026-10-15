<?php

namespace Tests\Feature;

use App\Jobs\EnrichLead;
use App\Models\Campaign;
use App\Models\ContactAttempt;
use App\Models\Lead;
use App\Models\Offer;
use App\Services\CampaignMembership;
use App\Services\ContactWorkflow;
use App\Services\LeadEnricher;
use App\Services\LeadImporter;
use App\Services\LeadScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EnrichmentScoringIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_preserves_public_coordinates_in_provenance_without_overwriting_identity(): void
    {
        Queue::fake([EnrichLead::class]);
        $campaign = Campaign::factory()->create(['source' => 'google_maps']);
        $path = storage_path('app/private/coordinates.csv');
        file_put_contents($path, "name,email,phone,place_id,lat,lng\nCentro QA,centro@qa.invalid,+543516000001,qa-centro,-31.4,-64.2\nNorte QA,norte@qa.invalid,+543516000002,qa-norte,999,-64.2\n");
        $run = app(LeadImporter::class)->importCsv($path, $campaign);
        $this->assertSame('completed', $run->status);
        $this->assertDatabaseCount('leads', 2);
        $center = Lead::where('source_identifier', 'qa-centro')->firstOrFail();
        $this->assertSame(['latitude' => -31.4, 'longitude' => -64.2], $center->sources()->firstOrFail()->metadata['coordinates']);
        $north = Lead::where('source_identifier', 'qa-norte')->firstOrFail();
        $this->assertArrayNotHasKey('coordinates', $north->sources()->firstOrFail()->metadata);
        $this->assertSame('Centro QA', $center->name);
        Queue::assertPushed(EnrichLead::class, 2);
    }

    public function test_manual_reenrichment_is_explicit_and_same_lead_jobs_cannot_overlap(): void
    {
        Queue::fake();
        $lead = Lead::factory()->create();
        $this->post(route('leads.enrich', $lead))->assertRedirect()->assertSessionHasNoErrors();
        Queue::assertPushed(EnrichLead::class, fn (EnrichLead $job): bool => $job->leadId === $lead->id && $job->force);
        $middleware = (new EnrichLead($lead->id))->middleware();
        $this->assertCount(1, $middleware);
        $this->assertSame('enrichment:'.$lead->id, $middleware[0]->key);
    }

    public function test_contact_snapshot_is_preserved_after_recalculation_and_visible_on_the_lead(): void
    {
        $this->travelTo(now()->startOfSecond());
        $offer = Offer::factory()->create(['name' => 'Gestión de turnos QA', 'rules' => ['scoring_profile' => 'booking', 'keywords' => ['salud']]]);
        $campaign = Campaign::factory()->create(['offer_id' => $offer->id]);
        $lead = Lead::factory()->create(['segment' => 'salud', 'category' => 'Consultorio', 'source_identifier' => 'qa-clinic']);
        app(CampaignMembership::class)->attach($lead, $campaign);
        app(LeadScorer::class)->score($lead);
        $attempt = app(ContactWorkflow::class)->record(['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'channel' => 'email', 'step' => 'initial', 'performed_at' => now()->toIso8601String()]);
        $snapshot = $attempt->score_snapshot;
        $this->assertNotNull($snapshot);
        $this->assertSame($offer->id, $snapshot['offer_id']);
        $this->travel(1)->hours();
        $lead->update(['segment' => 'comercio', 'email' => null, 'phone' => null]);
        app(LeadScorer::class)->score($lead);
        $this->assertSame($snapshot, $attempt->fresh()->score_snapshot);
        $this->get(route('leads.show', $lead))->assertOk()->assertSee('Puntuaciones por oferta')->assertSee('Puntuación al contactar');
        $this->assertSame(1, ContactAttempt::where('status', 'sent')->count());
    }

    public function test_expired_or_unreviewed_web_contact_cannot_replace_the_primary_email(): void
    {
        $lead = Lead::factory()->create(['email' => 'principal@qa.invalid']);
        $signal = $lead->signalRows()->create(['type' => 'channel_email_visible', 'value' => 'nuevo@qa.invalid', 'source' => 'website', 'evidence' => 'Email publicado', 'detected_at' => now(), 'review_status' => 'pending', 'evidence_kind' => 'observed', 'expires_at' => now()->addDay(), 'is_current' => true]);
        $this->post(route('leads.email.confirm', $lead))->assertStatus(422);
        $signal->update(['review_status' => 'confirmed', 'expires_at' => now()->subSecond()]);
        $this->post(route('leads.email.confirm', $lead))->assertStatus(422);
        $signal->update(['expires_at' => now()->addDay(), 'evidence_kind' => 'unknown']);
        $this->post(route('leads.email.confirm', $lead))->assertStatus(422);
        $signal->update(['evidence_kind' => 'conflict']);
        $this->post(route('leads.email.confirm', $lead))->assertStatus(422);
        $this->assertSame('principal@qa.invalid', $lead->fresh()->email);
        $signal->update(['evidence_kind' => 'observed']);
        $this->post(route('leads.email.confirm', $lead))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('nuevo@qa.invalid', $lead->fresh()->email);
        Http::assertNothingSent();
    }

    public function test_new_manual_evidence_keeps_human_provenance_and_survives_automatic_refresh(): void
    {
        $lead = Lead::factory()->create(['website_url' => null]);
        $this->post(route('leads.signals.store', $lead), ['type' => 'observed_problem', 'evidence' => 'Problema comprobado por una persona', 'evidence_kind' => 'observed', 'review_status' => 'confirmed'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $signal = $lead->signalRows()->where('source', 'manual')->firstOrFail();
        $this->assertSame('human_review', $signal->extraction_method);
        $this->assertSame('human-v1', $signal->rule_version);
        $this->assertSame(1.0, $signal->confidence);
        $this->assertSame('manual:lead:'.$lead->id, $signal->source_reference);
        app(LeadEnricher::class)->enrich($lead);
        $this->assertTrue($signal->fresh()->is_current);
        $this->assertNull($signal->expires_at);
        $this->assertSame('confirmed', $signal->review_status);
        Http::assertNothingSent();
    }

    public function test_editing_offer_retains_versioned_rules_and_saves_its_explicit_profile(): void
    {
        Queue::fake();
        $offer = Offer::factory()->create(['rules' => ['scoring_profile' => 'web', 'custom_setting' => ['needs_review' => true]]]);
        $this->put(route('offers.update', $offer), ['name' => $offer->name, 'keywords' => 'comercio', 'minimum_match' => 20, 'scoring_profile' => 'ecommerce'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('ecommerce', $offer->fresh()->rules['scoring_profile']);
        $this->assertSame(['needs_review' => true], $offer->fresh()->rules['custom_setting']);
    }
}
