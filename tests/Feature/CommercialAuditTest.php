<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\ContactAttempt;
use App\Models\Experiment;
use App\Models\ExperimentVariant;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\Offer;
use App\Models\Proposal;
use App\Models\Reply;
use App\Services\CampaignMembership;
use App\Services\CommercialWorkService;
use App\Services\ContactWorkflow;
use App\Services\CsvSerializer;
use App\Services\ExperimentService;
use App\Services\ExportService;
use App\Services\LeadContactSynchronizer;
use App\Services\LeadFilterService;
use App\Services\MetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CommercialAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_and_explicitly_empty_selections_never_expand_queries_or_exports(): void
    {
        $lead = Lead::factory()->create();
        $filters = app(LeadFilterService::class);
        foreach (['', [], null, 'bad', '0', '-1', '1,bad', ['1', []], range(1, 2001)] as $selection) {
            $this->assertSame(0, $filters->query(['ids' => $selection])->count());
            $this->assertCount(0, app(ExportService::class)->json(['ids' => $selection])['data']['leads']);
        }
        $this->assertSame(['ids' => ''], $filters->fromRequest(Request::create('/leads', 'GET', ['ids' => ''])));
        $this->assertSame(1, $filters->query(['ids' => (string) $lead->id])->count());
        $this->assertSame(0, $filters->query(['campaign' => 'invalid'])->count());
        $this->get('/exports/csv/leads?ids=')->assertOk()->assertStreamedContent('');
    }

    public function test_campaign_filters_use_only_the_selected_opportunity(): void
    {
        $a = Campaign::factory()->create();
        $b = Campaign::factory()->create();
        $offerA = Offer::create(['name' => 'Oferta A']);
        $offerB = Offer::create(['name' => 'Oferta B']);
        $lead = Lead::factory()->create(['next_action_at' => now()->subDay(), 'suggested_offer_id' => $offerB->id, 'status' => 'WON']);
        $lead->campaigns()->attach($a, ['status' => 'INTERESTED', 'offer_id' => $offerA->id, 'next_action_at' => now()->addDay()]);
        $lead->campaigns()->attach($b, ['status' => 'WON', 'offer_id' => $offerB->id, 'next_action_at' => now()->subDay()]);
        $this->attempt($lead, $b, 'whatsapp');
        Reply::create(['lead_id' => $lead->id, 'campaign_id' => $b->id, 'category' => 'interested', 'received_at' => now()]);
        $filters = app(LeadFilterService::class);
        foreach ([['due' => '1'], ['offer_id' => $offerB->id], ['channel' => 'whatsapp'], ['replied' => '1'], ['won' => '1']] as $filter) {
            $this->assertSame(0, $filters->query(['campaign' => $a->id] + $filter)->count());
            $this->assertSame(1, $filters->query(['campaign' => $b->id] + $filter)->count());
        }
        $selection = ['campaign' => $a->id, 'offer_id' => $offerA->id, 'status' => 'INTERESTED', 'replied' => '0'];
        $this->assertSame([$lead->id], $filters->query($selection)->pluck('id')->all());
        $this->assertSame([$lead->id], app(ExportService::class)->json($selection)['data']['leads']->modelKeys());
        $this->assertSame([], $filters->query()->getEagerLoads());
    }

    public function test_both_csv_exports_protect_external_formulas_and_preserve_quoted_fields(): void
    {
        $lead = Lead::factory()->create(['name' => "=SUM(1,2)\n\"Texto\"", 'phone' => '+543511234567', 'segment' => '@external']);
        $normal = app(ExportService::class)->csv('leads', ['ids' => [$lead->id]]);
        ob_start();
        $normal->sendContent();
        $csv = ob_get_clean();
        $rows = $this->csvRows($csv);
        $row = array_combine($rows[0], $rows[1]);
        $this->assertSame("'".$lead->name, $row['name']);
        $this->assertSame("'+543511234567", $row['phone']);
        $analytic = app(ExportService::class)->analyticsCsv(['ids' => [$lead->id]]);
        ob_start();
        $analytic->sendContent();
        $rows = $this->csvRows(ob_get_clean());
        $this->assertSame("'".$lead->name, $rows[1][1]);
        foreach (["\t=1", "\r=1", "\n@1", '  =1', '-2', '+2'] as $external) {
            $this->assertSame("'".$external, CsvSerializer::cell($external));
        }
        $this->assertSame(-2, CsvSerializer::cell(-2));
    }

    public function test_analytics_exports_each_opportunity_with_its_own_status_and_attributed_reply(): void
    {
        $lead = Lead::factory()->create(['status' => 'WON']);
        $a = Campaign::factory()->create(['name' => 'Campaña A']);
        $b = Campaign::factory()->create(['name' => 'Campaña B']);
        $lead->campaigns()->attach($a, ['status' => 'PROPOSAL']);
        $lead->campaigns()->attach($b, ['status' => 'WON']);
        $this->attempt($lead, $a, 'email');
        $actual = $this->attempt($lead, $a, 'whatsapp', ['variant' => 'B']);
        $this->attempt($lead, $b, 'phone');
        Reply::create(['lead_id' => $lead->id, 'campaign_id' => $a->id, 'contact_attempt_id' => $actual->id, 'category' => 'interested', 'received_at' => now()]);
        ob_start();
        app(ExportService::class)->analyticsCsv(['ids' => [$lead->id]])->sendContent();
        $rows = $this->csvRows(ob_get_clean());
        $this->assertCount(3, $rows);
        $data = collect(array_slice($rows, 1))->map(fn ($row) => array_combine($rows[0], $row))->keyBy('campaign');
        $this->assertSame('0', $data['Campaña A']['won']);
        $this->assertSame('1', $data['Campaña B']['won']);
        $this->assertSame('whatsapp', $data['Campaña A']['reply_channel']);
        $this->assertSame('B', $data['Campaña A']['reply_variant']);
    }

    public function test_replies_and_outcomes_are_attributed_to_the_actual_channel_and_variant(): void
    {
        $campaign = Campaign::factory()->create();
        $lead = Lead::factory()->create();
        $lead->campaigns()->attach($campaign, ['status' => 'WON', 'won_at' => now(), 'updated_at' => now()]);
        $email = $this->attempt($lead, $campaign, 'email', ['variant' => 'A', 'performed_at' => now()->subDays(2)]);
        $whatsapp = $this->attempt($lead, $campaign, 'whatsapp', ['variant' => 'B', 'performed_at' => now()->subDay()]);
        Reply::create(['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'contact_attempt_id' => $whatsapp->id, 'category' => 'interested', 'received_at' => now()]);
        Proposal::create(['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'service' => 'Web', 'currency' => 'ARS', 'version' => 1, 'sent_at' => now(), 'result' => 'won']);
        foreach (['channel' => ['email', 'whatsapp'], 'variant' => ['A', 'B']] as $dimension => [$other, $actual]) {
            $rows = collect(app(MetricsService::class)->segmented($dimension, ['campaign' => $campaign->id]))->keyBy('label');
            foreach (['replies', 'proposals', 'won'] as $metric) {
                $this->assertSame(0, $rows[$other][$metric]);
                $this->assertSame(1, $rows[$actual][$metric]);
            }
        }
        $this->assertSame(1, app(MetricsService::class)->funnel()['counts']['responses']);
    }

    public function test_variant_metrics_keep_experiments_separate_and_ignore_simulated_replies(): void
    {
        $campaign = Campaign::factory()->create();
        $lead = Lead::factory()->create();
        $lead->campaigns()->attach($campaign, ['status' => 'CONTACTED']);
        $first = Experiment::create(['campaign_id' => $campaign->id, 'name' => 'Primero', 'variable' => 'apertura', 'status' => 'completed']);
        $second = Experiment::create(['campaign_id' => $campaign->id, 'name' => 'Segundo', 'variable' => 'apertura', 'status' => 'active']);
        $a = ExperimentVariant::create(['experiment_id' => $first->id, 'name' => 'A']);
        $b = ExperimentVariant::create(['experiment_id' => $second->id, 'name' => 'A']);
        $this->attempt($lead, $campaign, 'email', ['variant' => 'A', 'experiment_variant_id' => $a->id]);
        $real = $this->attempt($lead, $campaign, 'email', ['variant' => 'A', 'experiment_variant_id' => $b->id]);
        $simulated = $this->attempt($lead, $campaign, 'whatsapp', ['dry_run' => true]);
        Reply::create(['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'contact_attempt_id' => $real->id, 'category' => 'interested', 'received_at' => now()]);
        Reply::create(['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'contact_attempt_id' => $simulated->id, 'category' => 'won', 'received_at' => now()]);
        $rows = collect(app(MetricsService::class)->segmented('variant'))->keyBy('label');
        $this->assertSame(0, $rows['Primero · A']['replies']);
        $this->assertSame(1, $rows['Segundo · A']['replies']);
        $this->assertSame(1, app(MetricsService::class)->funnel()['counts']['responses']);
    }

    public function test_primary_contact_updates_in_both_directions_and_delete_preserves_lead(): void
    {
        $lead = Lead::factory()->create(['entity_type' => 'organization']);
        $this->post(route('leads.contacts.store', $lead), ['name' => 'Principal', 'email' => 'old@example.org', 'phone_original' => '+543511234567', 'is_primary' => 1])->assertSessionHasNoErrors();
        $contact = $lead->contacts()->firstOrFail();
        app(LeadContactSynchronizer::class)->updateLead($lead, ['email' => 'new@example.org', 'phone' => '+543511111111', 'contact_person' => 'Nuevo']);
        $this->assertSame('new@example.org', $contact->refresh()->email);
        $this->assertSame('Nuevo', $contact->name);
        $this->put(route('leads.contacts.update', [$lead, $contact]), ['name' => 'Editado', 'email' => 'last@example.org', 'is_primary' => 1])->assertSessionHasNoErrors();
        $this->assertSame('last@example.org', $lead->refresh()->email);
        $this->delete(route('leads.contacts.delete', [$lead, $contact]))->assertRedirect();
        $this->assertSame('last@example.org', $lead->refresh()->email);
        $this->assertSame(0, $lead->contacts()->count());
    }

    public function test_customer_contact_requires_explicit_opportunity_and_duplicate_is_blocked(): void
    {
        $campaign = Campaign::factory()->create();
        $lead = Lead::factory()->create(['is_customer' => true]);
        $membership = app(CampaignMembership::class);
        $membership->attach($lead, $campaign);
        $lead->campaigns()->updateExistingPivot($campaign, ['status' => 'QUEUED']);
        $this->assertSame(0, $membership->ready($campaign)->count());
        try {
            $membership->assertContactEnabled($lead, $campaign);
            $this->fail('Cliente no habilitado');
        } catch (ValidationException) {
            $membership->attach($lead, $campaign, true);
        }
        $membership->assertContactEnabled($lead, $campaign);
        $this->assertSame(1, $membership->ready($campaign)->count());
        $lead->update(['duplicate_status' => 'possible_duplicate']);
        $this->assertSame(0, $membership->ready($campaign)->count());
    }

    public function test_experiment_preparation_and_actual_manual_attempt_are_connected_without_false_attribution(): void
    {
        $campaign = Campaign::factory()->create();
        $lead = Lead::factory()->create();
        $lead->campaigns()->attach($campaign, ['status' => 'QUEUED']);
        $template = MessageTemplate::create(['name' => 'A', 'channel' => 'email', 'stage' => 'initial', 'language' => 'es', 'subject' => 'Hola', 'body' => 'Plantilla base', 'active' => true]);
        $experiment = Experiment::create(['campaign_id' => $campaign->id, 'name' => 'Piloto', 'variable' => 'apertura', 'status' => 'active']);
        $variant = ExperimentVariant::create(['experiment_id' => $experiment->id, 'name' => 'A', 'message_template_id' => $template->id, 'content' => 'Contenido probado']);
        app(ExperimentService::class)->assign($lead, $experiment);
        $this->get(route('queue', ['campaign' => $campaign->id]))->assertOk()->assertSee('Contenido probado');
        $data = ['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'channel' => 'email', 'step' => 'initial', 'message_template_id' => $template->id, 'subject' => 'Hola', 'message_snapshot' => 'Contenido probado', 'performed_at' => now(), 'preparation_seconds' => 45];
        $attempt = app(ContactWorkflow::class)->record($data);
        $this->assertSame($variant->id, $attempt->experiment_variant_id);
        $this->assertSame('A', $attempt->variant);
        $this->assertSame(1, $variant->real_attempts_count);
        $data['message_snapshot'] = 'Editado manualmente';
        $this->assertNull(app(ExperimentService::class)->attribution($lead, $campaign, $data)['experiment_variant_id']);
        $attempt->update(['dry_run' => true]);
        $this->assertSame(0, $variant->real_attempts_count);
    }

    public function test_daily_work_includes_negotiations_and_renewals_without_creating_new_contacts(): void
    {
        $campaign = Campaign::factory()->create();
        $lead = Lead::factory()->create(['is_customer' => true]);
        $lead->campaigns()->attach($campaign, ['status' => 'NEGOTIATION', 'next_action_at' => now()->subDay()]);
        $lead->services()->create(['name' => 'Hosting', 'type' => 'hosting', 'status' => 'active', 'renews_on' => now()->subDay()->toDateString()]);
        $items = collect(app(CommercialWorkService::class)->actions());
        $this->assertTrue($items->contains('type', 'overdue'));
        $this->assertTrue($items->contains('type', 'renewal'));
        $this->assertSame(0, ContactAttempt::count());
        $this->assertSame(0, app(CampaignMembership::class)->ready()->count());
        $this->get('/')->assertOk()->assertSee('Renovación · Hosting')->assertSee('Negociación');
    }

    public function test_global_pipeline_preserves_separate_campaign_opportunities_and_context(): void
    {
        $lead = Lead::factory()->create(['status' => 'WON']);
        $a = Campaign::factory()->create(['name' => 'Primera']);
        $b = Campaign::factory()->create(['name' => 'Segunda']);
        $lead->campaigns()->attach($a, ['status' => 'WON']);
        $lead->campaigns()->attach($b, ['status' => 'PROPOSAL']);
        $this->get('/pipeline')->assertOk()->assertSee('Primera')->assertSee('Segunda')->assertSee('name="campaign_id" value="'.$a->id.'"', false)->assertSee('name="campaign_id" value="'.$b->id.'"', false);
    }

    public function test_scoring_evaluation_reports_samples_and_only_real_preparation_times(): void
    {
        $campaign = Campaign::factory()->create();
        $lead = Lead::factory()->create(['score_total' => 50]);
        $lead->campaigns()->attach($campaign, ['status' => 'CONTACTED']);
        $this->attempt($lead, $campaign, 'email', ['preparation_seconds' => 120]);
        $this->attempt($lead, $campaign, 'email', ['preparation_seconds' => 999, 'dry_run' => true]);
        $result = app(MetricsService::class)->scoreEvaluation();
        $this->assertSame(0, $result['scores'][2]['sample_size']);
        $this->assertSame(1, $result['unknown_sample_size']);
        $this->assertSame(1, $result['scores'][4]['sample_size']);
        $this->assertSame(0, $result['scores'][0]['sample_size']);
        $this->assertSame(1, $result['preparation']['sample_size']);
        $this->assertEquals(120, $result['preparation']['average_seconds']);
        $this->get('/metrics')->assertOk()->assertSee('Evaluación histórica de la puntuación')->assertSee('Desconocida: 1');
    }

    private function attempt(Lead $lead, Campaign $campaign, string $channel, array $extra = []): ContactAttempt
    {
        return ContactAttempt::create($extra + ['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'channel' => $channel, 'step' => 'initial', 'status' => 'sent', 'dry_run' => false, 'performed_at' => now()->subHour()]);
    }

    private function csvRows(string $csv): array
    {
        $handle = fopen('php://temp', 'w+');
        fwrite($handle, $csv);
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }
}
