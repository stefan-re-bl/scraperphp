<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\ContactAttempt;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\Offer;
use App\Models\Proposal;
use App\Services\CampaignMembership;
use App\Services\ContactNormalizer;
use App\Services\TemplateRenderer;
use App\Services\WhatsAppMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class OfferMessagingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Campaign, 1: Lead, 2: Offer} */
    private function prospect(): array
    {
        $offer = Offer::create(['name' => 'Agenda online', 'benefits' => 'Menos tareas manuales.', 'suggested_cta' => '¿Te muestro una demo?', 'active' => true]);
        $campaign = Campaign::factory()->create(['offer_id' => $offer->id, 'channel' => 'whatsapp']);
        $lead = Lead::factory()->create(['name' => 'Consultorio Álamo', 'contact_person' => 'Ana', 'phone' => '+54 341 15 6123456']);
        app(CampaignMembership::class)->attach($lead, $campaign);

        return [$campaign, $lead, $offer];
    }

    public function test_proposals_use_the_selected_offer_and_fill_an_empty_service(): void
    {
        [$campaign, $lead, $original] = $this->prospect();
        $selected = Offer::create(['name' => 'Sitio web', 'active' => true]);

        $this->post('/proposals', ['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'offer_id' => $selected->id, 'currency' => 'ARS', 'version' => 1])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('proposals', ['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'offer_id' => $selected->id, 'service' => 'Sitio web']);
        $this->assertSame($original->id, $campaign->fresh()->offer_id);
        $this->assertDatabaseHas('campaign_lead', ['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'status' => 'PROPOSAL']);
    }

    public function test_legacy_proposal_requests_keep_the_contextual_offer_default(): void
    {
        [$campaign, $lead, $offer] = $this->prospect();

        $this->post('/proposals', ['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'currency' => 'ARS', 'version' => 1])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('proposals', ['lead_id' => $lead->id, 'offer_id' => $offer->id, 'service' => 'Agenda online']);
    }

    public function test_custom_services_can_be_saved_without_an_offer(): void
    {
        [$campaign, $lead] = $this->prospect();

        $this->post('/proposals', ['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'offer_id' => '', 'service' => 'Integración a medida', 'currency' => 'ARS', 'version' => 1])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('proposals', ['lead_id' => $lead->id, 'offer_id' => null, 'service' => 'Integración a medida']);
    }

    public function test_missing_service_and_offer_do_not_create_a_proposal_or_change_the_opportunity(): void
    {
        [$campaign, $lead] = $this->prospect();

        $this->post('/proposals', ['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'offer_id' => '', 'currency' => 'ARS', 'version' => 1])
            ->assertSessionHasErrors(['service' => 'Elegí una oferta o escribí el servicio de la propuesta.']);

        $this->assertDatabaseCount('proposals', 0);
        $this->assertDatabaseHas('campaign_lead', ['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'status' => 'QUEUED']);
    }

    public function test_unavailable_offers_do_not_create_a_proposal(): void
    {
        [$campaign, $lead] = $this->prospect();

        $this->post('/proposals', ['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'offer_id' => 99999, 'service' => 'Servicio', 'currency' => 'ARS', 'version' => 1])
            ->assertSessionHasErrors(['offer_id' => 'La oferta seleccionada ya no está disponible.']);

        $this->assertDatabaseCount('proposals', 0);
    }

    public function test_editing_a_proposal_preserves_its_offer_and_custom_description_when_the_campaign_changes(): void
    {
        [$campaign, $lead, $offer] = $this->prospect();
        $proposal = $lead->proposals()->create(['campaign_id' => $campaign->id, 'offer_id' => $offer->id, 'service' => 'Agenda con importación inicial', 'currency' => 'ARS', 'version' => 1]);
        $newOffer = Offer::create(['name' => 'Nueva oferta', 'active' => true]);
        $campaign->update(['offer_id' => $newOffer->id]);

        $this->put('/proposals/'.$proposal->id, ['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'service' => $proposal->service, 'currency' => 'ARS', 'version' => 1, 'amount' => 250])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseCount('proposals', 1);
        $this->assertDatabaseHas('proposals', ['id' => $proposal->id, 'offer_id' => $offer->id, 'service' => 'Agenda con importación inicial', 'amount' => 250]);
    }

    public function test_the_offer_of_an_existing_proposal_can_be_changed_explicitly(): void
    {
        [$campaign, $lead, $offer] = $this->prospect();
        $proposal = $lead->proposals()->create(['campaign_id' => $campaign->id, 'offer_id' => $offer->id, 'service' => 'Descripción propia', 'currency' => 'ARS', 'version' => 1]);
        $selected = Offer::create(['name' => 'Sitio web', 'active' => true]);

        $this->put('/proposals/'.$proposal->id, ['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'offer_id' => $selected->id, 'service' => 'Descripción propia', 'currency' => 'ARS', 'version' => 1])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('proposals', ['id' => $proposal->id, 'offer_id' => $selected->id, 'service' => 'Descripción propia']);
    }

    public function test_proposals_for_different_offers_do_not_share_a_version_group(): void
    {
        [$campaign, $lead, $offer] = $this->prospect();
        $other = Offer::create(['name' => 'Otro servicio', 'active' => true]);
        $data = ['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'service' => 'Descripción general', 'currency' => 'ARS', 'version' => 1];

        $this->post('/proposals', $data + ['offer_id' => $offer->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/proposals', $data + ['offer_id' => $other->id])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseCount('proposals', 2);
        $this->assertSame(2, Proposal::distinct()->count('group_key'));
    }

    public function test_offer_selectors_show_contextual_defaults_and_escape_offer_names(): void
    {
        [$campaign, $lead, $offer] = $this->prospect();
        $offer->update(['name' => '<script>alert("offer")</script>']);

        $this->get('/proposals')->assertOk()->assertSee('name="offer_id"', false)->assertSee('data-service="&lt;script&gt;', false)->assertDontSee('<script>alert("offer")</script>', false);
        $this->get('/leads/'.$lead->id)->assertOk()->assertSee('name="offer_id"', false)->assertSee('data-offer-context', false)->assertSee('WhatsApp')->assertDontSee('>Llamar<', false);
    }

    public function test_templates_render_benefits_problems_and_the_complete_offer(): void
    {
        [$campaign, $lead, $offer] = $this->prospect();
        $offer->update(['pains' => 'Agenda manual.', 'target_customer' => 'Consultorios', 'demo_url' => 'https://example.test/demo', 'portfolio_url' => 'https://example.test/trabajos']);
        $template = MessageTemplate::create(['name' => 'Oferta completa', 'body' => '{{ beneficios }}|{{problemas}}|{{cliente_objetivo}}|{{oferta_detallada}}']);

        $message = app(TemplateRenderer::class)->render($template, $lead, $offer);

        $this->assertSame("Menos tareas manuales.|Agenda manual.|Consultorios|Agenda online\n\nMenos tareas manuales.\n\nDemo: https://example.test/demo\n\nPortfolio: https://example.test/trabajos\n\n¿Te muestro una demo?", $message['body']);
        $this->assertSame([], $message['missing']);
    }

    public function test_whatsapp_uses_the_campaign_template_and_encodes_the_prepared_message_without_recording_a_contact(): void
    {
        Queue::fake();
        Mail::fake();
        [$campaign, $lead, $offer] = $this->prospect();
        $template = MessageTemplate::create(['name' => 'WhatsApp inicial', 'offer_id' => $offer->id, 'channel' => 'whatsapp', 'stage' => 'initial', 'language' => 'es', 'active' => true,
            'body' => "Hola {{nombre}} 👋\n{{beneficios}} & {{CTA}}"]);
        $body = "Hola Ana 👋\nMenos tareas manuales. & ¿Te muestro una demo?";

        $response = $this->getJson('/leads/'.$lead->id.'/whatsapp?campaign_id='.$campaign->id);

        $response->assertOk()->assertJsonPath('body', $body)->assertJsonPath('template_id', $template->id)->assertJsonPath('phone', '5493416123456');
        $this->assertSame('wa.me', parse_url($response->json('url'), PHP_URL_HOST));
        $this->assertSame('/5493416123456', parse_url($response->json('url'), PHP_URL_PATH));
        parse_str(parse_url($response->json('url'), PHP_URL_QUERY), $query);
        $this->assertSame(['text' => $body], $query);
        $this->assertSame('+54 341 15 6123456', $lead->fresh()->phone);
        $this->assertDatabaseCount('contact_attempts', 0);
        $this->assertDatabaseHas('campaign_lead', ['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'status' => 'QUEUED']);
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
    }

    public function test_whatsapp_generates_a_message_from_the_offer_when_no_template_exists(): void
    {
        [$campaign, $lead] = $this->prospect();

        $this->getJson('/leads/'.$lead->id.'/whatsapp')->assertOk()
            ->assertJsonPath('body', "Hola Ana.\n\nAgenda online\n\nMenos tareas manuales.\n\n¿Te muestro una demo?")->assertJsonPath('template_id', null);
        $this->get('/contact-queue?campaign='.$campaign->id)->assertOk()->assertSee('Abrir WhatsApp')->assertSee('Menos tareas manuales.')->assertDontSee('>Llamar<', false);
    }

    public function test_whatsapp_uses_the_offer_selected_for_the_prospect_in_that_campaign(): void
    {
        [$campaign, $lead] = $this->prospect();
        $specific = Offer::create(['name' => 'Oferta especial', 'benefits' => 'Beneficio específico', 'active' => true]);
        $lead->campaigns()->updateExistingPivot($campaign->id, ['offer_id' => $specific->id]);

        $this->getJson('/leads/'.$lead->id.'/whatsapp?campaign_id='.$campaign->id)->assertOk()->assertJsonPath('offer_id', $specific->id)->assertJsonPath('offer_name', 'Oferta especial');
        $this->assertSame($specific->id, app(CampaignMembership::class)->offerContext($lead->fresh())['default']);
    }

    public function test_followup_preparation_uses_its_whatsapp_template_without_completing_the_pending_contact(): void
    {
        $this->freezeTime();
        [$campaign, $lead, $offer] = $this->prospect();
        $attempt = ContactAttempt::create(['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'channel' => 'whatsapp', 'step' => 'follow_up_1', 'status' => 'planned', 'due_at' => now()]);
        $template = MessageTemplate::create(['name' => 'Seguimiento', 'offer_id' => $offer->id, 'channel' => 'whatsapp', 'stage' => 'follow_up_1', 'language' => 'es', 'active' => true, 'body' => '¿Pudiste revisar {{oferta}}?']);

        $this->getJson('/leads/'.$lead->id.'/whatsapp')->assertOk()->assertJsonPath('body', '¿Pudiste revisar Agenda online?')->assertJsonPath('step', 'follow_up_1')->assertJsonPath('contact_attempt_id', $attempt->id);

        $this->assertDatabaseCount('contact_attempts', 1);
        $this->assertSame('planned', $attempt->fresh()->status);
    }

    public function test_missing_template_data_keeps_whatsapp_disabled_until_the_message_is_completed(): void
    {
        [$campaign, $lead, $offer] = $this->prospect();
        MessageTemplate::create(['name' => 'Datos pendientes', 'offer_id' => $offer->id, 'channel' => 'whatsapp', 'stage' => 'initial', 'language' => 'es', 'active' => true, 'body' => '{{cliente_objetivo}}']);

        $this->getJson('/leads/'.$lead->id.'/whatsapp')->assertOk()->assertJsonPath('missing', ['cliente_objetivo'])->assertJsonPath('url', null);
    }

    public function test_a_lead_without_an_offer_can_prepare_a_custom_message(): void
    {
        $lead = Lead::factory()->create(['phone' => '+5493416123456']);

        $this->getJson('/leads/'.$lead->id.'/whatsapp')->assertOk()->assertJsonPath('body', '')->assertJsonPath('missing', ['oferta'])->assertJsonPath('url', null)->assertJsonPath('phone', '5493416123456');
        $this->assertSame('https://wa.me/5493416123456?text=Mensaje%20propio', app(WhatsAppMessageService::class)->url($lead, 'Mensaje propio'));
    }

    #[TestWith(['+54 341 15 6123456', 'Argentina', '5493416123456'])]
    #[TestWith(['0341 15 6123456', 'Argentina', '5493416123456'])]
    #[TestWith(['+5493416123456', 'Argentina', '5493416123456'])]
    #[TestWith(['+54 11 15 12345678', 'Argentina', '5491112345678'])]
    #[TestWith(['+54 341 6123456', 'Argentina', '5493416123456'])]
    #[TestWith(['+56 9 1234 5678', 'Chile', '56912345678'])]
    #[TestWith(['+34 612 345 678', 'España', '34612345678'])]
    #[TestWith(['123', 'Argentina', null])]
    #[TestWith(['javascript:5493416123456', 'Argentina', null])]
    public function test_whatsapp_phone_format_uses_international_digits_and_removes_dialing_prefixes(string $phone, string $country, ?string $expected): void
    {
        $this->assertSame($expected, app(ContactNormalizer::class)->whatsappPhone($phone, $country));
    }

    public function test_unrelated_campaigns_return_404_when_preparing_whatsapp(): void
    {
        [$campaign, $lead] = $this->prospect();
        $other = Campaign::factory()->create();

        $this->getJson('/leads/'.$lead->id.'/whatsapp?campaign_id='.$other->id)->assertNotFound();

        $this->assertDatabaseCount('contact_attempts', 0);
    }

    public function test_multiple_campaigns_require_an_explicit_message_context(): void
    {
        [$campaign, $lead] = $this->prospect();
        app(CampaignMembership::class)->attach($lead, Campaign::factory()->create());

        $this->getJson('/leads/'.$lead->id.'/whatsapp')->assertUnprocessable()->assertJsonValidationErrors(['campaign_id'])
            ->assertJsonPath('errors.campaign_id.0', 'Seleccioná una campaña para preparar el mensaje.');

        $this->assertDatabaseCount('contact_attempts', 0);
    }

    public function test_excluded_prospects_cannot_prepare_a_whatsapp_contact(): void
    {
        $lead = Lead::factory()->create(['excluded_at' => '2026-01-01 00:00:00']);

        $this->getJson('/leads/'.$lead->id.'/whatsapp')->assertUnprocessable()->assertJsonPath('errors.lead_id.0', 'Este prospecto está excluido.');

        $this->assertDatabaseCount('contact_attempts', 0);
    }

    #[TestWith(['PAUSED'])]
    #[TestWith(['WON'])]
    #[TestWith(['LOST'])]
    public function test_paused_or_closed_opportunities_cannot_prepare_a_whatsapp_contact(string $status): void
    {
        $lead = Lead::factory()->create(['status' => $status]);

        $this->getJson('/leads/'.$lead->id.'/whatsapp')->assertUnprocessable()->assertJsonPath('errors.lead_id.0', 'La oportunidad está pausada o cerrada.');

        $this->assertDatabaseCount('contact_attempts', 0);
    }

    public function test_paused_campaigns_cannot_prepare_a_whatsapp_contact(): void
    {
        [$campaign, $lead] = $this->prospect();
        $campaign->update(['kill_switch' => true]);

        $this->getJson('/leads/'.$lead->id.'/whatsapp?campaign_id='.$campaign->id)->assertUnprocessable()->assertJsonPath('errors.campaign_id.0', 'La campaña está pausada.');

        $this->assertDatabaseCount('contact_attempts', 0);
    }

    public function test_manual_confirmation_records_the_final_whatsapp_message(): void
    {
        $this->freezeTime();
        [$campaign, $lead, $offer] = $this->prospect();
        $finalMessage = "Hola Ana 👋\nMensaje adaptado para tu consultorio.";

        $this->post('/contact-attempts', ['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'channel' => 'whatsapp', 'step' => 'initial', 'performed_at' => now()->toIso8601String(), 'message_snapshot' => $finalMessage, 'next' => 1])
            ->assertRedirect('/contact-queue?campaign='.$campaign->id)->assertSessionHasNoErrors();

        $this->assertDatabaseHas('contact_attempts', ['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'offer_id' => $offer->id, 'channel' => 'whatsapp', 'status' => 'sent', 'dry_run' => false, 'message_snapshot' => $finalMessage]);
    }
}
