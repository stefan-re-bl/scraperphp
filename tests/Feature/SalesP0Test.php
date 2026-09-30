<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\ContactAttempt;
use App\Models\Lead;
use App\Models\LeadStatusHistory;
use App\Models\Offer;
use App\Models\Reply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class SalesP0Test extends TestCase
{
    use RefreshDatabase;

    public function test_csv_import_deduplicates_and_scores_leads(): void
    {
        $campaign = Campaign::create([
            'name' => 'Psicologos Argentina',
            'segment' => 'psicologos',
            'country' => 'Argentina',
            'language' => 'es',
            'source' => 'google_maps',
            'channel' => 'email',
            'daily_limit' => 20,
        ]);

        $csv = UploadedFile::fake()->createWithContent('leads.csv', implode("\n", [
            'name,category,location,website,email,phone,place_id',
            'Consultorio Norte,Psicologia,Buenos Aires,https://consultorio.test,hola@consultorio.test,+541100000001,abc',
            'Consultorio Norte,Psicologia,Buenos Aires,https://consultorio.test,hola@consultorio.test,+541100000001,abc',
        ]));

        $this->post('/imports', [
            'campaign_id' => $campaign->id,
            'csv' => $csv,
            'query' => 'psicologos buenos aires',
            'location' => 'Buenos Aires',
        ])->assertRedirect('/leads');

        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseHas('import_runs', [
            'raw_count' => 2,
            'imported_count' => 1,
            'duplicate_count' => 1,
        ]);
        $this->assertDatabaseHas('score_components', [
            'component' => 'contact_quality_email',
            'points' => 25,
        ]);
        $this->assertDatabaseHas('lead_status_histories', [
            'from_status' => 'NEW',
            'to_status' => 'QUEUED',
            'reason' => 'import_csv',
        ]);
    }

    public function test_csv_import_keeps_possible_commercial_duplicates(): void
    {
        $campaign = Campaign::create([
            'name' => 'Psicologos Argentina',
            'segment' => 'psicologos',
            'country' => 'Argentina',
            'language' => 'es',
            'source' => 'google_maps',
            'channel' => 'email',
            'daily_limit' => 20,
        ]);

        $csv = UploadedFile::fake()->createWithContent('leads.csv', implode("\n", [
            'name,category,location,website,email,phone,place_id',
            'Psicologos Cordoba,Psicologia,Cordoba Centro,https://psicologos.test,,+543510000001,abc',
            'Psicologos Cordoba Sucursal Norte,Psicologia,Cordoba Norte,https://psicologos.test,,+543510000001,def',
        ]));

        $this->post('/imports', [
            'campaign_id' => $campaign->id,
            'csv' => $csv,
            'query' => 'psicologos cordoba',
            'location' => 'Cordoba',
        ])->assertRedirect('/leads');

        $this->assertDatabaseCount('leads', 2);
        $this->assertDatabaseHas('leads', [
            'name' => 'Psicologos Cordoba Sucursal Norte',
            'duplicate_status' => 'possible_duplicate',
            'duplicate_rule' => 'shared_domain',
        ]);
        $this->assertDatabaseHas('import_runs', [
            'raw_count' => 2,
            'imported_count' => 2,
            'duplicate_count' => 0,
            'possible_duplicate_count' => 1,
        ]);
    }

    public function test_prevents_duplicate_active_sequences(): void
    {
        [$campaign, $lead] = $this->campaignAndQueuedLead();

        ContactAttempt::create([
            'lead_id' => $lead->id,
            'campaign_id' => $campaign->id,
            'channel' => 'email',
            'step' => 'initial',
            'status' => 'planned',
            'manual_intervention' => true,
        ]);

        $this->from('/contact-queue')->post('/contact-attempts', [
            'lead_id' => $lead->id,
            'campaign_id' => $campaign->id,
            'channel' => 'email',
            'step' => 'follow_up_1',
        ])->assertRedirect('/contact-queue');

        $this->assertDatabaseCount('contact_attempts', 1);
    }

    public function test_reply_pauses_pending_followups_and_updates_pipeline(): void
    {
        [$campaign, $lead] = $this->campaignAndQueuedLead();

        $sent = ContactAttempt::create([
            'lead_id' => $lead->id,
            'campaign_id' => $campaign->id,
            'channel' => 'email',
            'step' => 'initial',
            'performed_at' => now(),
            'status' => 'sent',
            'manual_intervention' => true,
        ]);

        ContactAttempt::create([
            'lead_id' => $lead->id,
            'campaign_id' => $campaign->id,
            'channel' => 'email',
            'step' => 'follow_up_1',
            'status' => 'planned',
            'manual_intervention' => true,
        ]);

        $this->post('/replies', [
            'lead_id' => $lead->id,
            'contact_attempt_id' => $sent->id,
            'category' => 'interested',
            'body' => 'Me interesa.',
            'received_at' => now()->format('Y-m-d H:i:s'),
        ])->assertRedirect();

        $this->assertDatabaseHas('contact_attempts', [
            'step' => 'follow_up_1',
            'status' => 'paused',
            'result' => 'paused_after_reply',
        ]);
        $this->assertDatabaseHas('leads', [
            'id' => $lead->id,
            'status' => 'INTERESTED',
        ]);
    }

    public function test_proposal_updates_pipeline_and_metrics(): void
    {
        [$campaign, $lead] = $this->campaignAndQueuedLead();

        ContactAttempt::create([
            'lead_id' => $lead->id,
            'campaign_id' => $campaign->id,
            'channel' => 'email',
            'step' => 'initial',
            'performed_at' => now(),
            'status' => 'sent',
            'manual_intervention' => true,
        ]);

        Reply::create([
            'lead_id' => $lead->id,
            'category' => 'interested',
            'body' => 'Enviar propuesta.',
            'received_at' => now(),
            'requires_human' => true,
        ]);

        $this->post('/proposals', [
            'lead_id' => $lead->id,
            'service' => 'Gestor de consultorios',
            'amount' => 500,
            'currency' => 'USD',
            'version' => 1,
            'sent_at' => now()->format('Y-m-d H:i:s'),
            'result' => 'won',
        ])->assertRedirect();

        $this->assertDatabaseHas('proposals', [
            'lead_id' => $lead->id,
            'version' => 1,
            'result' => 'won',
        ]);
        $this->assertDatabaseHas('leads', [
            'id' => $lead->id,
            'status' => 'WON',
        ]);

        $this->get('/')->assertOk()->assertSee('close rate')->assertSee('100');
    }

    public function test_exports_csv_and_json(): void
    {
        $this->campaignAndQueuedLead();

        $this->get('/exports/csv/leads')->assertOk();
        $this->get('/exports/json')
            ->assertOk()
            ->assertJsonPath('data.leads.0.name', 'Consultorio Norte');
    }

    private function campaignAndQueuedLead(): array
    {
        $offer = Offer::create(['name' => 'Gestor de consultorios']);
        $campaign = Campaign::create([
            'offer_id' => $offer->id,
            'name' => 'Psicologos Argentina',
            'segment' => 'psicologos',
            'country' => 'Argentina',
            'language' => 'es',
            'source' => 'google_maps',
            'channel' => 'email',
            'daily_limit' => 20,
            'status' => 'active',
        ]);
        $lead = Lead::create([
            'name' => 'Consultorio Norte',
            'category' => 'Psicologia',
            'location' => 'Buenos Aires',
            'country' => 'Argentina',
            'language' => 'es',
            'email' => 'hola@consultorio.test',
            'phone' => '+541100000001',
            'source' => 'google_maps',
            'status' => 'QUEUED',
            'score_total' => 75,
        ]);

        LeadStatusHistory::create([
            'lead_id' => $lead->id,
            'from_status' => 'NEW',
            'to_status' => 'QUEUED',
            'reason' => 'test_seed',
            'changed_at' => now(),
        ]);

        return [$campaign, $lead];
    }
}
