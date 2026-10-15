<?php

namespace Tests\Feature;

use App\Jobs\EnrichLead;
use App\Models\Campaign;
use App\Models\ClientService;
use App\Models\Lead;
use App\Models\LeadContact;
use App\Models\Proposal;
use App\Services\CampaignMembership;
use App\Services\ExportService;
use App\Services\LeadImporter;
use App\Services\LeadScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UnifiedCrmTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_customer_who_is_also_a_collaborator_without_winning_an_opportunity(): void
    {
        Queue::fake([EnrichLead::class]);

        $this->post(route('leads.store'), ['name' => 'Organización de prueba', 'language' => 'es', 'source' => 'manual', 'relationship_type' => 'customer',
            'is_collaborator' => true, 'entity_type' => 'organization', 'manual_priority' => 'low', 'estimated_value' => '240000.00', 'estimated_currency' => 'ARS'])->assertRedirect()->assertSessionHasNoErrors();

        $lead = Lead::firstOrFail();
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'is_customer' => true, 'is_collaborator' => true, 'manual_priority' => 'low', 'status' => 'NEW', 'estimated_value_minor' => 24000000]);
        $this->assertSame('240000.00', $lead->estimated_value);
        $this->assertDatabaseCount('proposals', 0);
        $this->assertDatabaseCount('lead_status_histories', 0);
        Queue::assertPushed(EnrichLead::class, fn ($job) => $job->leadId === $lead->id);
    }

    public function test_updates_customer_classification_and_keeps_manual_priority_after_scoring(): void
    {
        $lead = Lead::factory()->create(['manual_priority' => 'high']);

        $this->put(route('leads.update', $lead), ['name' => $lead->name, 'email' => $lead->email, 'language' => 'es', 'source' => 'manual', 'relationship_type' => 'customer',
            'entity_type' => 'person', 'is_collaborator' => true, 'notes' => 'Seguimiento comercial'])->assertRedirect()->assertSessionHasNoErrors();
        app(LeadScorer::class)->score($lead);

        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'entity_type' => 'person', 'is_customer' => true, 'is_collaborator' => true, 'manual_priority' => 'high', 'notes' => 'Seguimiento comercial']);
    }

    public function test_an_organization_with_contacts_cannot_hide_them_by_becoming_a_person(): void
    {
        $lead = Lead::factory()->create();
        $contact = LeadContact::factory()->for($lead)->create();

        $this->put(route('leads.update', $lead), ['name' => $lead->name, 'language' => 'es', 'source' => 'manual', 'relationship_type' => 'prospect', 'entity_type' => 'person'])->assertSessionHasErrors('entity_type');

        $this->assertSame('organization', $lead->fresh()->entity_type);
        $this->assertModelExists($contact);
    }

    public function test_both_relationship_filters_and_exports_include_a_customer_collaborator(): void
    {
        $lead = Lead::factory()->create(['is_customer' => true, 'is_collaborator' => true]);
        Lead::factory()->create(['name' => 'Prospecto separado']);

        foreach (['customer', 'collaborator'] as $type) {
            $this->get(route('leads', ['relationship_type' => $type]))->assertOk()->assertSee($lead->name)->assertDontSee('Prospecto separado');
            $export = app(ExportService::class)->json(['relationship_type' => $type]);
            $this->assertSame([$lead->id], $export['data']['leads']->modelKeys());
        }
        $this->get(route('leads', ['relationship_type' => 'prospect']))->assertOk()->assertDontSee($lead->name)->assertSee('Prospecto separado');
    }

    public function test_multiple_contacts_preserve_primary_data_until_a_contact_is_chosen_as_primary(): void
    {
        $lead = Lead::factory()->create(['email' => 'original@example.test']);
        LeadContact::factory()->for($lead)->create(['name' => 'Contacto anterior', 'is_primary' => true]);
        $this->post(route('leads.contacts.store', $lead), ['name' => 'Ana', 'email' => 'ana@example.test', 'phone_original' => '351 123', 'is_primary' => false])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('original@example.test', $lead->fresh()->email);
        $contact = $lead->contacts()->where('name', 'Ana')->firstOrFail();
        $this->assertNull($contact->phone);
        $this->assertSame('351 123', $contact->phone_original);

        $this->put(route('leads.contacts.update', [$lead, $contact]), ['name' => 'Ana', 'email' => 'ana@example.test', 'phone_original' => '+54 351 600 1111', 'is_primary' => true])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, $lead->contacts()->where('is_primary', true)->count());
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'contact_person' => 'Ana', 'email' => 'ana@example.test', 'phone' => '+543516001111']);
        $this->delete(route('leads.contacts.delete', [$lead, $contact]))->assertRedirect();
        $this->assertModelMissing($contact);
        $this->assertSame('ana@example.test', $lead->fresh()->email);
    }

    public function test_refuses_associated_contacts_for_an_independent_person(): void
    {
        $lead = Lead::factory()->create(['entity_type' => 'person']);

        $this->post(route('leads.contacts.store', $lead), ['name' => 'Duplicado'])->assertSessionHasErrors('contact');

        $this->assertDatabaseCount('lead_contacts', 0);
    }

    public function test_contact_and_service_changes_cannot_target_another_record(): void
    {
        $lead = Lead::factory()->create(['is_customer' => true]);
        $contact = LeadContact::factory()->create();
        $service = ClientService::factory()->create();

        $this->put(route('leads.contacts.update', [$lead, $contact]), ['name' => 'Cambio'])->assertNotFound();
        $this->delete(route('leads.contacts.delete', [$lead, $contact]))->assertNotFound();
        $this->put(route('leads.services.update', [$lead, $service]), $this->serviceData())->assertNotFound();
        $this->delete(route('leads.services.delete', [$lead, $service]))->assertNotFound();

        $this->assertModelExists($contact);
        $this->assertModelExists($service);
    }

    public function test_multiple_services_keep_exact_amounts_separate_from_estimates_and_pipeline(): void
    {
        $lead = Lead::factory()->create(['is_customer' => true, 'status' => 'NEGOTIATION', 'estimated_value_minor' => 80000000, 'estimated_currency' => 'ARS']);
        $proposal = Proposal::create(['lead_id' => $lead->id, 'service' => 'Web', 'amount' => '200000.25', 'currency' => 'ARS']);

        $this->post(route('leads.services.store', $lead), $this->serviceData() + ['proposal_id' => $proposal->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('leads.services.store', $lead), array_replace($this->serviceData(), ['name' => 'Hosting', 'type' => 'hosting', 'amount' => '0.00']))->assertRedirect()->assertSessionHasNoErrors();
        $service = $lead->services()->where('name', 'Mantenimiento')->firstOrFail();
        $this->put(route('leads.services.update', [$lead, $service]), array_replace($this->serviceData(), ['status' => 'completed', 'amount' => '9999999999999.99']))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('9999999999999.99', $service->fresh()->amount);
        $this->assertDatabaseHas('client_services', ['lead_id' => $lead->id, 'name' => 'Hosting', 'amount_minor' => 0]);
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'status' => 'NEGOTIATION', 'estimated_value_minor' => 80000000]);
        $this->assertSame('200000.25', $proposal->fresh()->amount);
        $this->assertDatabaseCount('lead_status_histories', 0);
        $this->delete(route('leads.services.delete', [$lead, $service]))->assertRedirect();
        $this->assertModelMissing($service);
        $this->assertModelExists($proposal);
    }

    public function test_rejects_service_for_a_prospect_and_a_proposal_from_another_client(): void
    {
        $prospect = Lead::factory()->create();
        $customer = Lead::factory()->create(['is_customer' => true]);
        $proposal = Proposal::create(['lead_id' => $prospect->id, 'service' => 'Otro servicio', 'currency' => 'ARS']);

        $this->post(route('leads.services.store', $prospect), $this->serviceData())->assertSessionHasErrors('service');
        $this->post(route('leads.services.store', $customer), $this->serviceData() + ['proposal_id' => $proposal->id])->assertSessionHasErrors('proposal_id');
        $this->post(route('leads.services.store', $customer), array_replace($this->serviceData(), ['amount' => '1.001', 'ends_on' => '2026-09-01', 'starts_on' => '2026-10-01', 'reference_url' => 'javascript:alert(1)']))->assertSessionHasErrors(['amount', 'ends_on', 'reference_url']);

        $this->assertDatabaseCount('client_services', 0);
    }

    public function test_renewal_view_includes_overdue_services_and_excludes_completed_and_later_services(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 10)->startOfDay());
        $customer = Lead::factory()->create(['is_customer' => true]);
        $pending = ClientService::factory()->for($customer, 'lead')->create(['name' => 'Renovación pendiente', 'renews_on' => '2026-10-01']);
        ClientService::factory()->for($customer, 'lead')->create(['name' => 'Servicio finalizado', 'renews_on' => '2026-10-12', 'status' => 'completed']);
        ClientService::factory()->for($customer, 'lead')->create(['name' => 'Renovación lejana', 'renews_on' => '2027-01-01']);

        $this->get(route('services', ['renewals' => 1]))->assertOk()->assertSee($pending->name)->assertDontSee('Servicio finalizado')->assertDontSee('Renovación lejana');
    }

    public function test_customer_found_by_scraper_retains_commercial_data_and_stays_out_of_new_campaign(): void
    {
        $campaign = Campaign::factory()->create();
        $customer = Lead::factory()->create(['is_customer' => true, 'is_collaborator' => true, 'status' => 'WON', 'manual_priority' => 'low', 'notes' => 'No sobrescribir', 'source' => 'notion']);
        $customer->sources()->create(['source' => 'google_maps', 'source_identifier' => 'maps-known']);
        $service = ClientService::factory()->for($customer, 'lead')->create();
        $file = UploadedFile::fake()->createWithContent('maps.csv', "name,place_id,email\nNombre del scraper,maps-known,nuevo@example.test\n");

        $run = app(LeadImporter::class)->importCsv($file, $campaign, ['source' => 'google_maps']);

        $this->assertSame(1, $run->duplicate_count);
        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseHas('leads', ['id' => $customer->id, 'status' => 'WON', 'manual_priority' => 'low', 'notes' => 'No sobrescribir', 'is_collaborator' => true]);
        $this->assertDatabaseCount('campaign_lead', 0);
        $this->assertFalse(app(CampaignMembership::class)->query($campaign)->whereKey($customer->id)->exists());
        $this->assertModelExists($service);
        $this->get(route('queue'))->assertOk()->assertViewHas('leads', fn ($rows) => $rows->isEmpty());
    }

    public function test_crm_renders_escaped_contact_and_service_notes_and_exports_both_entities(): void
    {
        $customer = Lead::factory()->create(['is_customer' => true]);
        $contact = LeadContact::factory()->for($customer)->create(['name' => '<script>contact</script>']);
        $service = ClientService::factory()->for($customer, 'lead')->create(['notes' => '<script>service</script>', 'amount_minor' => null]);

        $this->get(route('leads.show', $customer))->assertOk()->assertSee('&lt;script&gt;contact&lt;/script&gt;', false)->assertDontSee('<script>contact</script>', false)->assertDontSee('<script>service</script>', false);
        $json = app(ExportService::class)->json(['ids' => [$customer->id]]);
        $this->assertSame([$contact->id], $json['data']['contacts']->modelKeys());
        $this->assertSame([$service->id], $json['data']['services']->modelKeys());
        $this->assertNull($service->amount);
    }

    private function serviceData(): array
    {
        return ['name' => 'Mantenimiento', 'type' => 'maintenance', 'status' => 'active', 'amount' => '240000.25', 'currency' => 'ARS'];
    }
}
