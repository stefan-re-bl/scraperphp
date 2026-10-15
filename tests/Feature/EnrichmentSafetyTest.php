<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Services\LeadEnricher;
use App\Services\LimitedWebsiteStream;
use App\Services\LlmBridgeService;
use App\Services\PublicDnsResolver;
use App\Services\PublicWebsiteFetcher;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EnrichmentSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['enrichment.domain_min_interval_seconds' => 0]);
    }

    public static function forbiddenUrls(): array
    {
        return array_map(fn ($url) => [$url], [
            'http://localhost/', 'http://localhost./', 'http://sub.localhost/', 'http://127.0.0.1/',
            'http://[::1]/', 'http://[::ffff:127.0.0.1]/', 'http://[fe80::1]/', 'http://[fc00::1]/',
            'http://10.0.0.1/', 'http://172.16.0.1/', 'http://192.168.0.1/', 'http://169.254.169.254/',
            'http://100.64.0.1/', 'http://0177.0.0.1/', 'http://0x7f.0.0.0x1/', 'http://2130706433/', 'http://192.0.2.1/', 'http://224.0.0.1/', 'http://[2001:db8::1]/',
            'http://[2002:7f00:1::]/', 'ftp://business.test/', 'https://user:password@business.test/',
            'https://business.test:8080/',
        ]);
    }

    #[DataProvider('forbiddenUrls')]
    public function test_non_public_urls_are_rejected_without_http(string $url): void
    {
        Http::fake();
        $lead = Lead::factory()->create(['website_url' => $url]);
        app(LeadEnricher::class)->enrich($lead);
        Http::assertNothingSent();
        $this->assertDatabaseHas('signals', ['lead_id' => $lead->id, 'type' => 'website_unreachable', 'review_status' => 'pending']);
    }

    public function test_mixed_a_and_aaaa_records_are_rejected(): void
    {
        Http::fake();
        $this->dns(['93.184.216.34', '::1']);
        $lead = Lead::factory()->create(['website_url' => 'https://business.test']);
        app(LeadEnricher::class)->enrich($lead);
        Http::assertNothingSent();
    }

    public function test_empty_dns_answer_is_rejected(): void
    {
        Http::fake();
        $this->dns([]);
        $lead = Lead::factory()->create(['website_url' => 'https://business.test']);
        app(LeadEnricher::class)->enrich($lead);
        Http::assertNothingSent();
    }

    public function test_public_destination_is_pinned_without_second_dns_lookup_or_redirects(): void
    {
        $resolver = Mockery::mock(PublicDnsResolver::class);
        $resolver->shouldReceive('resolve')->twice()->with('business.test')->andReturn(['93.184.216.34', '2606:4700:4700::1111']);
        $this->app->instance(PublicDnsResolver::class, $resolver);
        Http::fake(function ($request, $options) {
            $this->assertSame(['business.test:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame('', $options['proxy']);
            $this->assertFalse($options['decode_content']);

            if (str_ends_with($request->url(), '/robots.txt')) {
                return Http::response("User-agent: *\nAllow: /", 200, ['Content-Type' => 'text/plain']);
            }

            return Http::response('<title>Centro</title><a href="https://calendly.com/centro">Reservar online</a>', 200);
        });
        $lead = Lead::factory()->create(['website_url' => 'HTTPS://BUSINESS.TEST.']);
        app(LeadEnricher::class)->enrich($lead);
        $this->assertTrue($lead->fresh()->booking_visible);
        Http::assertSentCount(2);
    }

    public function test_public_request_options_are_accepted_by_the_installed_curl_transport_without_network(): void
    {
        Http::fake(function ($request, $options) {
            $factory = new CurlFactory(1);
            $handle = $factory->create($request->toPsrRequest(), $options);
            try {
                $this->assertSame(['business.test:443:93.184.216.34'], $handle->options['curl'][CURLOPT_RESOLVE]);
                $this->assertSame('', $handle->options['proxy']);
                $this->assertFalse($handle->options['allow_redirects']);
            } finally {
                $factory->release($handle);
            }

            return Http::response('<title>Centro</title>', 200);
        });

        $response = app(PublicWebsiteFetcher::class)->fetch('https://business.test/');
        $this->assertSame(200, $response['status']);
        Http::assertSentCount(1);
    }

    public function test_public_ipv6_literal_is_supported(): void
    {
        Http::fake(function ($request, $options) {
            $this->assertSame([], $options['curl'][CURLOPT_RESOLVE]);
            $this->assertSame('https://[2606:4700:4700::1111]/', $request->url());

            return Http::response('<title>Centro</title> Contactar', 200);
        });
        $result = app(PublicWebsiteFetcher::class)->fetch('https://[2606:4700:4700::1111]/');
        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('Centro', $result['body']);
    }

    public function test_redirect_does_not_fetch_its_destination(): void
    {
        $this->fakeWebsite('', 302, ['Location' => 'http://127.0.0.1/']);
        $lead = Lead::factory()->create(['website_url' => 'https://business.test']);
        app(LeadEnricher::class)->enrich($lead);
        Http::assertSentCount(2);
        $this->assertDatabaseHas('signals', ['lead_id' => $lead->id, 'type' => 'website_unreachable', 'value' => '302']);
    }

    public function test_bounded_sink_stops_before_oversized_chunk_is_written(): void
    {
        $stream = new LimitedWebsiteStream(8);
        $stream->write('12345');
        try {
            $stream->write('6789');
            $this->fail('Expected body limit to stop the stream.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(5, $stream->getSize());
            $this->assertStringContainsString('límite', $exception->getMessage());
        } finally {
            $stream->close();
        }
    }

    public function test_oversized_response_does_not_produce_commercial_claims(): void
    {
        config(['services.enrichment.max_response_bytes' => 20]);
        $this->fakeWebsite(str_repeat('reservar online ', 20));
        $lead = Lead::factory()->create(['website_url' => 'https://business.test', 'notes' => 'Nota humana']);
        app(LeadEnricher::class)->enrich($lead);
        $this->assertDatabaseMissing('signals', ['lead_id' => $lead->id, 'type' => 'booking_business']);
        $this->assertSame('Nota humana', $lead->fresh()->notes);
        $this->assertDatabaseHas('signals', ['lead_id' => $lead->id, 'type' => 'website_unreachable', 'review_status' => 'pending']);
    }

    public function test_header_budget_rejects_response_before_reading_body(): void
    {
        config(['services.enrichment.max_response_bytes' => 20]);
        Http::fake(function ($request, $options) {
            $options['on_headers'](new Response(200, ['Content-Length' => '21']));
            $this->fail('Expected oversized headers to abort download.');
        });
        $lead = Lead::factory()->create(['website_url' => 'https://business.test']);
        app(LeadEnricher::class)->enrich($lead);
        $this->assertDatabaseHas('signals', ['lead_id' => $lead->id, 'type' => 'website_unreachable', 'review_status' => 'pending']);
    }

    public function test_changed_phone_retires_old_import_signals(): void
    {
        $lead = Lead::factory()->create(['website_url' => null, 'phone' => 'abc']);
        app(LeadEnricher::class)->enrich($lead);
        $this->assertDatabaseHas('signals', ['lead_id' => $lead->id, 'type' => 'phone_format_invalid']);
        $lead->update(['phone' => '+5493516000001']);
        app(LeadEnricher::class)->enrich($lead);
        $this->assertDatabaseMissing('signals', ['lead_id' => $lead->id, 'type' => 'phone_format_invalid', 'is_current' => true]);
        $this->assertDatabaseHas('signals', ['lead_id' => $lead->id, 'type' => 'phone_likely_mobile']);
        $lead->update(['phone' => null]);
        app(LeadEnricher::class)->enrich($lead);
        $this->assertDatabaseMissing('signals', ['lead_id' => $lead->id, 'type' => 'phone_likely_mobile', 'is_current' => true]);
        $this->assertDatabaseMissing('signals', ['lead_id' => $lead->id, 'type' => 'phone_format_valid', 'is_current' => true]);
    }

    public function test_http_error_evidence_omits_sensitive_transport_details(): void
    {
        Http::fake(function () {
            throw new \RuntimeException('INTERNAL_HOST credential=SECRET');
        });
        $lead = Lead::factory()->create(['website_url' => 'https://business.test']);
        app(LeadEnricher::class)->enrich($lead);
        $evidence = $lead->signalRows()->where('type', 'website_unreachable')->value('evidence');
        $this->assertStringNotContainsString('SECRET', $evidence);
        $this->assertStringNotContainsString('INTERNAL_HOST', $evidence);
    }

    public function test_stale_import_signals_are_removed_after_contact_data_changes(): void
    {
        $this->fakeWebsite('<title>Centro</title><a href="mailto:contacto@business.test">Contactar</a>');
        $lead = Lead::factory()->create(['email' => null, 'phone' => null, 'website_url' => null]);
        app(LeadEnricher::class)->enrich($lead);
        $this->assertDatabaseHas('signals', ['lead_id' => $lead->id, 'type' => 'no_website']);
        $lead->update(['website_url' => 'https://business.test', 'email' => 'contacto@business.test', 'phone' => '+5493516000001']);
        app(LeadEnricher::class)->enrich($lead);
        $this->assertDatabaseMissing('signals', ['lead_id' => $lead->id, 'type' => 'no_website', 'is_current' => true]);
        $this->assertDatabaseMissing('signals', ['lead_id' => $lead->id, 'type' => 'hard_to_find_contact', 'is_current' => true]);
    }

    public function test_unchanged_review_survives_and_changed_evidence_needs_review(): void
    {
        $lead = Lead::factory()->create(['website_url' => 'https://business.test']);
        $enricher = app(LeadEnricher::class);
        $html = '<title>Centro</title> Turnos por WhatsApp';
        $enricher->analyzeWebsiteContent($lead, $lead->website_url, $html);
        $signal = $lead->signalRows()->where('type', 'manual_contact_dependency')->firstOrFail();
        $signal->update(['review_status' => 'rejected']);
        $enricher->analyzeWebsiteContent($lead, $lead->website_url, $html);
        $this->assertSame('rejected', $signal->fresh()->review_status);
        $this->assertNotContains('manual_contact_dependency', $lead->fresh()->signals);
        $enricher->analyzeWebsiteContent($lead, $lead->website_url, '<title>Centro</title> Llamar para turnos');
        $this->assertSame('pending', $signal->fresh()->review_status);
        $this->assertSame($signal->id, $lead->signalRows()->where('type', 'manual_contact_dependency')->value('id'));
    }

    public function test_manual_signal_and_notes_survive_reconciliation_and_automatic_collision(): void
    {
        $lead = Lead::factory()->create(['website_url' => null, 'notes' => 'Anotación humana']);
        $manual = $lead->signalRows()->create(['type' => 'no_website', 'source' => 'manual', 'value' => 'reviewed', 'evidence' => 'Texto humano', 'detected_at' => now(), 'review_status' => 'confirmed', 'evidence_kind' => 'observed']);
        app(LeadEnricher::class)->enrich($lead);
        $this->assertSame('Texto humano', $manual->fresh()->evidence);
        $this->assertSame('Anotación humana', $lead->fresh()->notes);
        $this->assertSame(1, $lead->signalRows()->where('type', 'no_website')->where('source', 'manual')->count());
    }

    public function test_conflicting_automatic_rows_are_reconciled_and_need_review(): void
    {
        $lead = Lead::factory()->create(['website_url' => null]);
        $enricher = app(LeadEnricher::class);
        $enricher->enrich($lead);
        $current = $lead->signalRows()->where('type', 'phone_format_valid')->firstOrFail();
        $duplicate = $current->replicate();
        $duplicate->value = '5491123456789';
        $duplicate->save();

        $enricher->enrich($lead);

        $this->assertSame(1, $lead->signalRows()->where('type', 'phone_format_valid')->current()->count());
        $this->assertSame('pending', $current->fresh()->review_status);
        $this->assertDatabaseHas('signals', ['id' => $duplicate->id, 'is_current' => false]);
        $enricher->enrich($lead);
        $this->assertSame('pending', $current->fresh()->review_status);
    }

    public function test_identical_automatic_duplicates_preserve_coherent_human_review(): void
    {
        $lead = Lead::factory()->create(['website_url' => null]);
        $enricher = app(LeadEnricher::class);
        $enricher->enrich($lead);
        $current = $lead->signalRows()->where('type', 'phone_format_valid')->firstOrFail();
        $current->update(['review_status' => 'rejected']);
        $current->replicate()->save();

        $enricher->enrich($lead);

        $this->assertSame(1, $lead->signalRows()->where('type', 'phone_format_valid')->current()->count());
        $this->assertSame('rejected', $current->fresh()->review_status);
        $this->assertNotContains('phone_format_valid', $lead->fresh()->signals);
    }

    public function test_disagreeing_reviews_on_identical_automatic_evidence_need_review(): void
    {
        $lead = Lead::factory()->create(['website_url' => null]);
        $enricher = app(LeadEnricher::class);
        $enricher->enrich($lead);
        $current = $lead->signalRows()->where('type', 'phone_format_valid')->firstOrFail();
        $duplicate = $current->replicate();
        $duplicate->review_status = 'rejected';
        $duplicate->save();

        $enricher->enrich($lead);

        $this->assertSame(1, $lead->signalRows()->where('type', 'phone_format_valid')->current()->count());
        $this->assertSame('pending', $current->fresh()->review_status);
    }

    public function test_reconciliation_and_lead_update_roll_back_together(): void
    {
        $lead = Lead::factory()->create(['website_url' => null, 'notes' => 'Conservar']);
        app(LeadEnricher::class)->enrich($lead);
        $id = $lead->signalRows()->where('type', 'no_website')->value('id');
        $lead->update(['email' => null, 'phone' => null]);
        $lead->getConnection()->statement("CREATE TRIGGER fail_lead_update BEFORE UPDATE ON leads BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        try {
            app(LeadEnricher::class)->enrich($lead);
            $this->fail('Expected synthetic database failure.');
        } catch (QueryException) {
            $this->assertSame($id, $lead->signalRows()->where('type', 'no_website')->value('id'));
            $this->assertDatabaseMissing('signals', ['lead_id' => $lead->id, 'type' => 'hard_to_find_contact', 'is_current' => true]);
        }
    }

    public function test_llm_prompt_uses_confirmed_clean_evidence(): void
    {
        $lead = Lead::factory()->create(['website_url' => 'https://business.test/?token=secret', 'signals' => ['obsolete_cached_signal']]);
        foreach (['confirmed', 'pending', 'rejected'] as $status) {
            $lead->signalRows()->create(['type' => 'signal_'.$status, 'source' => 'manual', 'value' => 'true', 'evidence' => '<script>SECRET_SCRIPT</script><b>Confirmado</b>', 'detected_at' => now(), 'review_status' => $status, 'evidence_kind' => 'observed', 'evidence_url' => 'https://user:password@business.test/path?token=secret']);
        }
        $prompt = app(LlmBridgeService::class)->createPrompt('diagnóstico', [$lead->id])->prompt;
        $this->assertStringContainsString('signal_confirmed', $prompt);
        $this->assertStringContainsString('Confirmado', $prompt);
        foreach (['signal_pending', 'signal_rejected', 'obsolete_cached_signal', 'SECRET_SCRIPT', 'token=secret', 'password'] as $omitted) {
            $this->assertStringNotContainsString($omitted, $prompt);
        }
    }

    private function fakeWebsite(string $body, int $status = 200, array $headers = []): void
    {
        Http::fake(fn ($request) => str_ends_with($request->url(), '/robots.txt')
            ? Http::response('', 404)
            : Http::response($body, $status, $headers));
    }

    private function dns(array $addresses): void
    {
        $resolver = Mockery::mock(PublicDnsResolver::class);
        $resolver->shouldReceive('resolve')->andReturn($addresses);
        $this->app->instance(PublicDnsResolver::class, $resolver);
    }
}
