<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Offer;
use App\Models\Signal;
use App\Services\EnrichmentRequestBudget;
use App\Services\HtmlCommercialExtractor;
use App\Services\LeadEnricher;
use App\Services\LeadScorer;
use App\Services\PublicWebsiteFetcher;
use App\Services\RobotsPolicy;
use App\Services\SecondaryIdentityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnrichmentMultisourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['enrichment.domain_min_interval_seconds' => 0, 'enrichment.osm.enabled' => false]);
    }

    public function test_json_ld_contacts_services_catalog_and_strong_technology_signatures_are_extracted(): void
    {
        $lead = $this->lead();
        $html = '<html><head><meta name="generator" content="WordPress 6.8"><script src="/wp-content/plugins/woocommerce/assets/js/cart.js"></script>'
            .'<script type="application/ld+json">'.json_encode(['@context' => 'https://schema.org', '@graph' => [
                ['@type' => 'LocalBusiness', 'name' => $lead->name, 'url' => $lead->website_url, 'telephone' => $lead->phone,
                    'address' => ['streetAddress' => 'Av Colón 100', 'addressLocality' => 'Córdoba'], 'sameAs' => ['https://instagram.com/centro']],
                ['@type' => 'ContactPoint', 'email' => 'public@example.test'], ['@type' => 'Product', 'name' => 'Crema', 'offers' => ['@type' => 'Offer', 'price' => '100']],
                ['@type' => 'Service', 'name' => 'Consulta profesional'],
            ]]).'</script></head><body><a href="tel:+543516000001">Teléfono</a><a href="mailto:public@example.test">Email</a>'
            .'<a href="https://wa.me/5493516000001">WhatsApp</a><form action="/contact"><input name="email"><textarea name="message"></textarea></form></body></html>';
        $this->site($html);

        app(LeadEnricher::class)->enrich($lead);

        foreach (['website_verified', 'channel_email_visible', 'channel_phone_visible', 'channel_whatsapp', 'channel_form', 'social_instagram', 'service_offered', 'products_visible', 'catalog_visible', 'technology_wordpress', 'technology_woocommerce', 'dns_public'] as $type) {
            $this->assertDatabaseHas('signals', ['lead_id' => $lead->id, 'type' => $type, 'is_current' => true]);
        }
        $this->assertDatabaseMissing('signals', ['lead_id' => $lead->id, 'type' => 'online_purchase_visible', 'is_current' => true]);
        $row = $lead->signalRows()->where('type', 'service_offered')->firstOrFail();
        $this->assertSame('json_ld', $row->extraction_method);
        $this->assertSame('https://business.test/', $row->source_reference);
        $this->assertSame('enrichment-v1', $row->rule_version);
        $this->assertNotNull($row->expires_at);
        $this->assertSame('website:business.test', $row->metadata['independence_key']);
        $this->assertSame($lead->phone, $lead->fresh()->phone);
        $this->assertSame($lead->email, $lead->fresh()->email);
        $this->assertSame(2, Http::recorded()->count());
        $this->assertStringNotContainsString('<html>', DB::table('enrichment_source_states')->value('result'));
    }

    public function test_malformed_html_invalid_json_ld_and_array_fields_do_not_hide_valid_contacts(): void
    {
        $lead = $this->lead();
        $html = '<title>Centro</title><script type="application/ld+json">{INVALID}</script>'
            .'<script type="application/ld+json">'.json_encode(['@type' => 'LocalBusiness', 'name' => ['unexpected' => 'value'], 'telephone' => ['wrong'],
                'address' => ['streetAddress' => ['wrong'], 'addressLocality' => ['wrong']]]).'</script>'
            .'<p><a href="mailto:visible@example.test">Consulta</a><form action="/contact"><textarea name="consulta"></textarea>';
        $result = app(HtmlCommercialExtractor::class)->extract($lead, $lead->website_url, $html);
        $types = array_column($result['evidence'], 'type');
        $this->assertContains('structured_data_unknown', $types);
        $this->assertContains('channel_email_visible', $types);
        $this->assertContains('channel_form', $types);
        $this->assertNotContains('identity_conflict', $types);
    }

    public function test_multiple_relevant_pages_are_bounded_and_external_or_parameter_links_are_not_followed(): void
    {
        $lead = $this->lead();
        $home = '<title>Centro</title>';
        $pages = [];
        foreach (['contacto', 'servicios', 'reservas', 'tienda', 'productos', 'catalogo'] as $path) {
            $home .= '<a href="/'.$path.'">'.$path.'</a>';
            $pages['https://business.test/'.$path] = '<title>Página</title><p>Información pública</p>';
        }
        $home .= '<a href="https://127.0.0.1/contacto">Contacto externo</a><a href="/contacto?session=SECRET">Contacto con sesión</a><a href="/archivo.pdf">Catálogo PDF</a>';
        $pages['https://business.test/contacto'] = '<title>Contacto</title><a href="mailto:info@example.test">Consulta</a>';
        $pages['https://business.test/reservas'] = '<title>Reservas</title><iframe src="https://calendly.com/centro"></iframe>';
        $this->site($home, $pages);

        app(LeadEnricher::class)->enrich($lead);

        Http::assertSentCount(6);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '127.0.0.1') || str_contains($request->url(), '?') || str_ends_with($request->url(), '.pdf'));
        $this->assertTrue($lead->fresh()->booking_visible);
        $this->assertDatabaseHas('signals', ['type' => 'channel_email_visible', 'source_reference' => 'https://business.test/contacto', 'is_current' => true]);
        $summary = json_decode(DB::table('enrichment_source_states')->where('source', 'website')->value('result'), true);
        $this->assertSame(5, $summary['page_count']);
        $this->assertLessThanOrEqual(600000, $summary['bytes']);
    }

    public function test_http_page_does_not_assert_https_is_absent_or_generate_web_need(): void
    {
        $lead = $this->lead(['website_url' => 'http://business.test/']);
        Http::fake([
            'http://business.test/robots.txt' => Http::response("User-agent: *\nAllow: /", 200, ['Content-Type' => 'text/plain']),
            'http://business.test/' => Http::response('<html><title>Centro</title><body>Información pública del negocio.</body></html>', 200, ['Content-Type' => 'text/html']),
        ]);
        app(LeadEnricher::class)->enrich($lead);
        $this->assertDatabaseHas('signals', ['lead_id' => $lead->id, 'type' => 'http_page_observed', 'evidence_kind' => 'observed']);
        $this->assertDatabaseMissing('signals', ['lead_id' => $lead->id, 'type' => 'no_https', 'is_current' => true]);
        $offer = Offer::factory()->create(['rules' => ['scoring_profile' => 'web']]);
        $this->assertSame(0, app(LeadScorer::class)->evaluateOffer($lead, $offer)['dimensions']['need']);
        Http::assertSentCount(2);
    }

    public function test_robots_disallow_and_specific_allow_are_obeyed_for_each_page(): void
    {
        $lead = $this->lead();
        $this->site('<title>Centro</title><a href="/contacto">Contacto</a><a href="/servicios">Servicios</a>', [
            'https://business.test/servicios' => '<title>Servicios</title><h2>Servicios</h2><ul><li>Consultas</li></ul>',
        ], "User-agent: *\nDisallow: /\nUser-agent: CRTWebEvidenceBot\nAllow: /$\nAllow: /servicios\nDisallow: /contacto");
        app(LeadEnricher::class)->enrich($lead);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/contacto'));
        $this->assertDatabaseHas('signals', ['type' => 'service_offered', 'is_current' => true]);
        $this->assertDatabaseHas('signals', ['type' => 'website_access_restricted', 'source_reference' => 'https://business.test/contacto', 'evidence_kind' => 'unknown']);
    }

    public function test_robots_encoding_wildcards_and_allow_ties_cannot_bypass_restrictions(): void
    {
        $robots = app(RobotsPolicy::class);
        $policy = $robots->parse("User-agent: *\nDisallow: /private\nDisallow: /products/*?\nAllow: /private/public$\nAllow: /tie\nDisallow: /tie", 'CRTWebEvidenceBot/1.0');
        $this->assertFalse($robots->allows('https://business.test/%70rivate/account', $policy));
        $this->assertFalse($robots->allows('https://business.test/products/item?page=1', $policy));
        $this->assertTrue($robots->allows('https://business.test/private/public', $policy));
        $this->assertFalse($robots->allows('https://business.test/private/public/secret', $policy));
        $this->assertTrue($robots->allows('https://business.test/tie', $policy));
    }

    public function test_robots_unavailable_prevents_crawling_but_not_import_evidence(): void
    {
        $lead = $this->lead();
        Http::fake(['*' => Http::response('', 503)]);
        app(LeadEnricher::class)->enrich($lead);
        Http::assertSentCount(1);
        $this->assertDatabaseHas('signals', ['lead_id' => $lead->id, 'type' => 'phone_format_valid', 'is_current' => true]);
        $this->assertDatabaseMissing('signals', ['type' => 'website_verified', 'is_current' => true]);
        $this->assertDatabaseMissing('signals', ['type' => 'weak_cta', 'is_current' => true]);
    }

    public function test_recent_requests_are_skipped_and_force_refresh_preserves_idempotency(): void
    {
        $lead = $this->lead();
        $this->site('<title>Centro</title><a href="mailto:info@example.test">Contacto</a>');
        $enricher = app(LeadEnricher::class);
        $enricher->enrich($lead);
        $count = Signal::count();
        $enricher->enrich($lead);
        Http::assertSentCount(2);
        $this->assertSame($count, Signal::count());
        $enricher->enrich($lead, true);
        Http::assertSentCount(3);
        $this->assertSame($count, Signal::count());
    }

    public function test_changed_import_data_invalidates_ttl_and_keeps_identity_fields(): void
    {
        $lead = $this->lead();
        $this->site('<title>Centro</title><a href="tel:+5493516000002">Teléfono público</a>');
        $enricher = app(LeadEnricher::class);
        $enricher->enrich($lead);
        $lead->update(['email' => 'updated@example.test']);
        $enricher->enrich($lead);
        Http::assertSentCount(3);
        $this->assertSame('updated@example.test', $lead->fresh()->email);
        $this->assertSame('+543516000001', $lead->fresh()->phone);
        $this->assertDatabaseHas('signals', ['type' => 'contact_data_conflict', 'evidence_kind' => 'conflict', 'review_status' => 'pending']);
    }

    public function test_same_page_signals_do_not_claim_independent_corroboration(): void
    {
        $lead = $this->lead();
        $this->site('<title>Centro</title><meta name="generator" content="WordPress 6.8"><a href="https://wa.me/5493516000001">WhatsApp</a><a href="mailto:info@example.test">Email</a>');
        app(LeadEnricher::class)->enrich($lead);
        $keys = $lead->signalRows()->whereIn('source', ['website', 'dns_http'])->get()->map(fn ($row) => $row->metadata['independence_key'])->unique();
        $this->assertCount(1, $keys);
        $this->assertSame('website:business.test', $keys->first());
    }

    public function test_technology_words_and_fake_external_hosts_do_not_prove_installation_or_booking(): void
    {
        $lead = $this->lead();
        $result = app(HtmlCommercialExtractor::class)->extract($lead, $lead->website_url,
            '<title>Centro</title><p>WordPress Shopify Calendly Doctoralia reservamos online</p><script>var email="secret@example.test";</script><a href="https://calendly.com.evil.test/">Reserva</a><a href="https://instagram.com.evil.test/">Perfil</a>');
        $types = array_column($result['evidence'], 'type');
        foreach (['technology_wordpress', 'technology_shopify', 'booking_system_visible', 'social_instagram', 'channel_email_visible'] as $type) {
            $this->assertNotContains($type, $types);
        }
    }

    public function test_product_catalog_with_explicit_purchase_controls_is_ecommerce(): void
    {
        $lead = $this->lead();
        $result = app(HtmlCommercialExtractor::class)->extract($lead, $lead->website_url,
            '<title>Productos</title><script type="application/ld+json">{"@type":"Product","name":"Libro"}</script><button>Agregar al carrito</button>');
        $types = array_column($result['evidence'], 'type');
        $this->assertContains('products_visible', $types);
        $this->assertContains('ecommerce_visible', $types);
        $this->assertContains('online_purchase_visible', $types);
        $this->assertNotContains('booking_business', $types);
    }

    public function test_identity_conflicts_preserve_originals_and_do_not_incorporate_other_branch_services(): void
    {
        $lead = $this->lead();
        $this->site('<title>Otra sucursal</title><script type="application/ld+json">'.json_encode(['@type' => 'LocalBusiness', 'name' => 'Centro Sur', 'url' => $lead->website_url, 'telephone' => $lead->phone, 'address' => ['addressLocality' => 'Rosario']]).'</script><a href="https://calendly.com/sur">Reservar online</a>');
        app(LeadEnricher::class)->enrich($lead);
        $this->assertSame('Centro Norte', $lead->fresh()->name);
        $this->assertSame('Córdoba', $lead->fresh()->city);
        $this->assertSame('+543516000001', $lead->fresh()->phone);
        $this->assertDatabaseHas('signals', ['type' => 'identity_conflict', 'evidence_kind' => 'conflict', 'review_status' => 'pending']);
        $this->assertDatabaseMissing('signals', ['type' => 'booking_system_visible', 'is_current' => true]);
    }

    public function test_shared_domain_without_branch_identity_is_ambiguous_even_with_shared_phone(): void
    {
        $lead = $this->lead();
        $this->lead(['name' => 'Centro Sur', 'city' => 'Rosario', 'location' => 'Av Sur 200']);
        $this->site('<title>Centro</title><script type="application/ld+json">{"@type":"Service","name":"Cirugía"}</script><a href="https://calendly.com/centro">Reserva</a><a href="tel:+543516000001">Teléfono compartido</a>');
        app(LeadEnricher::class)->enrich($lead);
        $this->assertDatabaseHas('signals', ['lead_id' => $lead->id, 'type' => 'identity_conflict', 'value' => 'ambiguous', 'evidence_kind' => 'unknown']);
        $this->assertDatabaseMissing('signals', ['lead_id' => $lead->id, 'type' => 'service_offered', 'is_current' => true]);
        $this->assertDatabaseMissing('signals', ['lead_id' => $lead->id, 'type' => 'channel_phone_visible', 'is_current' => true]);
    }

    public function test_strong_branch_name_and_city_discriminate_a_shared_domain_without_merging(): void
    {
        $lead = $this->lead();
        $this->lead(['name' => 'Centro Sur', 'city' => 'Rosario', 'location' => 'Av Sur 200']);
        $resolution = app(SecondaryIdentityResolver::class)->compare($lead, ['name' => 'Centro Norte', 'city' => 'Córdoba', 'website' => $lead->website_url, 'phone' => $lead->phone], true);
        $this->assertSame('strong', $resolution['status']);
        $this->assertDatabaseCount('leads', 2);
    }

    public function test_oversized_and_failed_downloads_consume_the_total_byte_budget(): void
    {
        config(['enrichment.max_total_bytes' => 100, 'enrichment.max_page_bytes' => 80]);
        Http::fake(['*' => Http::response(str_repeat('A', 90))]);
        $budget = new EnrichmentRequestBudget(app(PublicWebsiteFetcher::class));
        foreach (['https://business.test/', 'https://business.test/contacto'] as $url) {
            try {
                $budget->fetch($url);
                $this->fail('Oversized body should fail.');
            } catch (\RuntimeException) {
                $this->assertLessThanOrEqual(100, $budget->bytes());
            }
        }
        $this->assertSame(100, $budget->bytes());
        $this->expectException(\RuntimeException::class);
        $budget->fetch('https://business.test/servicios');
    }

    public function test_binary_content_and_html_body_limits_are_rejected_before_analysis(): void
    {
        Http::fake(['*' => Http::response('synthetic image', 200, ['Content-Type' => 'image/png'])]);
        $this->expectException(\RuntimeException::class);
        app(PublicWebsiteFetcher::class)->fetch('https://business.test/image.png');
    }

    public function test_host_frequency_and_active_lease_prevent_additional_requests(): void
    {
        Http::fake(['*' => Http::response('<title>Centro</title>')]);
        $budget = new EnrichmentRequestBudget(app(PublicWebsiteFetcher::class));
        $budget->fetch('https://business.test/', minInterval: 60);
        try {
            $budget->fetch('https://business.test/contacto', minInterval: 60);
            $this->fail('Frequency limit should stop the second request.');
        } catch (\RuntimeException) {
            Http::assertSentCount(1);
        }
        DB::table('enrichment_request_states')->where('key', hash('sha256', 'host:business.test'))->update(['last_started_at' => null, 'lease_until' => now()->addSeconds(30), 'lease_token' => 'other-worker']);
        $this->expectException(\RuntimeException::class);
        $budget->fetch('https://business.test/servicios');
    }

    public function test_expired_automatic_evidence_is_not_current_but_manual_notes_remain(): void
    {
        $lead = $this->lead(['website_url' => null, 'notes' => 'Nota humana']);
        app(LeadEnricher::class)->enrich($lead);
        $manual = $lead->signalRows()->create(['type' => 'observed_problem', 'value' => 'true', 'evidence' => 'Observación humana', 'source' => 'manual', 'detected_at' => now(), 'review_status' => 'confirmed', 'evidence_kind' => 'observed']);
        $this->travel(31)->days();
        $this->assertSame(0, $lead->signalRows()->current()->where('source', 'import')->count());
        $this->assertSame(1, $lead->signalRows()->current()->whereKey($manual->id)->count());
        $this->assertSame('Nota humana', $lead->fresh()->notes);
    }

    public function test_changed_evidence_keeps_previous_observation_and_requires_new_review(): void
    {
        $lead = $this->lead();
        $enricher = app(LeadEnricher::class);
        $enricher->analyzeWebsiteContent($lead, $lead->website_url, '<title>Centro</title><a href="mailto:one@example.test">Email</a>');
        $signal = $lead->signalRows()->where('type', 'channel_email_visible')->firstOrFail();
        $signal->update(['review_status' => 'rejected']);
        $enricher->analyzeWebsiteContent($lead, $lead->website_url, '<title>Centro</title><a href="mailto:one@example.test">Email</a>');
        $this->assertSame('rejected', $signal->fresh()->review_status);
        $enricher->analyzeWebsiteContent($lead, $lead->website_url, '<title>Centro</title><a href="mailto:two@example.test">Email</a>');
        $this->assertSame('pending', $signal->fresh()->review_status);
        $this->assertSame('one@example.test', $signal->fresh()->metadata['observation_history'][0]['value']);
    }

    public function test_osm_is_disabled_by_default_and_never_contacted(): void
    {
        $lead = $this->lead(['website_url' => null]);
        $this->coordinates($lead);
        Http::fake();
        app(LeadEnricher::class)->enrich($lead);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('signals', ['source' => 'osm']);
    }

    public function test_osm_strong_geographic_match_adds_attributed_evidence_without_replacing_identity(): void
    {
        $lead = $this->lead();
        $this->coordinates($lead);
        $this->enableOsm();
        $this->site('<title>Centro</title>', extra: fn ($request) => str_starts_with($request->url(), 'https://overpass.test/') ? Http::response($this->osmData($lead), 200, ['Content-Type' => 'application/json']) : null);
        app(LeadEnricher::class)->enrich($lead);
        $signal = $lead->signalRows()->where('type', 'osm_identity_verified')->firstOrFail();
        $this->assertSame('https://www.openstreetmap.org/node/10', $signal->source_reference);
        $this->assertSame('ODbL', $signal->metadata['license']);
        $this->assertSame('© OpenStreetMap contributors', $signal->metadata['attribution']);
        $this->assertSame('Centro Norte', $lead->fresh()->name);
        $this->assertSame('Av Colón 100', $lead->fresh()->location);
        Http::assertSent(fn ($request) => str_contains(rawurldecode($request->url()), 'around:100,-31.416,-64.18'));
        app(LeadEnricher::class)->enrich($lead);
        Http::assertSentCount(3);
    }

    public function test_osm_ambiguous_matches_and_source_failures_do_not_interrupt_the_official_site(): void
    {
        $lead = $this->lead();
        $this->coordinates($lead);
        $this->enableOsm();
        $data = $this->osmData($lead);
        $data['elements'][] = $data['elements'][0] + ['id' => 11];
        $this->site('<title>Centro</title>', extra: fn ($request) => str_starts_with($request->url(), 'https://overpass.test/') ? Http::response($data, 200, ['Content-Type' => 'application/json']) : null);
        app(LeadEnricher::class)->enrich($lead);
        $this->assertDatabaseMissing('signals', ['type' => 'osm_identity_verified', 'is_current' => true]);
        $this->assertDatabaseHas('signals', ['type' => 'osm_unknown', 'evidence_kind' => 'unknown']);
        $this->assertDatabaseHas('signals', ['type' => 'website_verified', 'is_current' => true]);
    }

    public function test_osm_timeout_is_independent_of_the_official_website(): void
    {
        $lead = $this->lead();
        $this->coordinates($lead);
        $this->enableOsm();
        $this->site('<title>Centro</title>', extra: function ($request) {
            if (str_starts_with($request->url(), 'https://overpass.test/')) {
                throw new \RuntimeException('Synthetic timeout with INTERNAL_SECRET');
            }

            return null;
        });
        app(LeadEnricher::class)->enrich($lead);
        $this->assertDatabaseHas('signals', ['type' => 'website_verified', 'is_current' => true]);
        $this->assertDatabaseHas('signals', ['type' => 'osm_unknown', 'evidence_kind' => 'unknown']);
        $this->assertStringNotContainsString('INTERNAL_SECRET', $lead->signalRows()->where('type', 'osm_unknown')->value('evidence'));
    }

    private function lead(array $attributes = []): Lead
    {
        return Lead::factory()->create($attributes + ['name' => 'Centro Norte', 'city' => 'Córdoba', 'location' => 'Av Colón 100', 'website_url' => 'https://business.test/', 'domain' => 'business.test', 'phone' => '+543516000001', 'email' => 'registered@example.test']);
    }

    private function site(string $home, array $pages = [], string $robots = "User-agent: *\nAllow: /", ?callable $extra = null): void
    {
        Http::fake(function ($request) use ($home, $pages, $robots, $extra) {
            if ($extra && ($response = $extra($request))) {
                return $response;
            }
            if ($request->url() === 'https://business.test/robots.txt') {
                return Http::response($robots, 200, ['Content-Type' => 'text/plain']);
            }
            if ($request->url() === 'https://business.test/') {
                return Http::response($home, 200, ['Content-Type' => 'text/html']);
            }

            return isset($pages[$request->url()]) ? Http::response($pages[$request->url()], 200, ['Content-Type' => 'text/html']) : null;
        });
    }

    private function coordinates(Lead $lead): void
    {
        $lead->sources()->create(['source' => 'google_maps', 'source_identifier' => 'maps-'.$lead->id, 'metadata' => ['coordinates' => ['latitude' => -31.416, 'longitude' => -64.18]]]);
    }

    private function enableOsm(): void
    {
        config(['enrichment.osm.enabled' => true, 'enrichment.osm.terms_acknowledged' => true, 'enrichment.osm.endpoint' => 'https://overpass.test/api/interpreter', 'enrichment.osm.user_agent' => 'CRTWebEvidenceBot/1.0 (https://agency.example.test/contact)']);
    }

    private function osmData(Lead $lead): array
    {
        return ['elements' => [['type' => 'node', 'id' => 10, 'lat' => -31.416, 'lon' => -64.18,
            'tags' => ['name' => $lead->name, 'phone' => $lead->phone, 'website' => $lead->website_url, 'addr:street' => 'Av Colón', 'addr:housenumber' => '100', 'addr:city' => 'Córdoba']]]];
    }
}
