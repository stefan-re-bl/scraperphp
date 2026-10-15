<?php

namespace Tests\Feature;

use App\Jobs\EnrichLead;
use App\Jobs\ExecuteScrapeRun;
use App\Jobs\ImportLeadFile;
use App\Models\Campaign;
use App\Models\ImportRowIssue;
use App\Models\ImportRun;
use App\Models\Lead;
use App\Models\ScrapeRun;
use App\Models\SystemLog;
use App\Services\BackupService;
use App\Services\ExclusionService;
use App\Services\GoogleMapsScraperService;
use App\Services\LeadIdentityMatcher;
use App\Services\LeadImporter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Mockery;
use PDO;
use Tests\TestCase;

class AcquisitionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([EnrichLead::class, ImportLeadFile::class, ExecuteScrapeRun::class]);
    }

    private function csv(string $content): string
    {
        $path = storage_path('app/private/'.uniqid('acquisition-', true).'.csv');
        File::put($path, $content);

        return $path;
    }

    public function test_shared_domain_email_and_conflicting_external_ids_create_separate_branches(): void
    {
        $campaign = Campaign::factory()->create(['source' => 'google_maps']);
        $path = $this->csv("name,location,website,email,place_id\nSucursal Centro,Centro,https://shared.example.test,info@shared.example.test,map-centro\nSucursal Norte,Norte,https://shared.example.test,info@shared.example.test,map-norte\n");
        $run = app(LeadImporter::class)->importCsv($path, $campaign);
        $this->assertSame(2, $run->imported_count);
        $this->assertSame(1, $run->possible_duplicate_count);
        $this->assertDatabaseCount('leads', 2);
        $second = Lead::where('source_identifier', 'map-norte')->firstOrFail();
        $this->assertSame('possible_duplicate', $second->duplicate_status);
        $this->assertNotSame('QUEUED', $second->status);
        $again = app(LeadImporter::class)->importCsv($path, $campaign);
        $this->assertSame(2, $again->duplicate_count);
        $this->assertDatabaseCount('leads', 2);
        $this->assertDatabaseCount('lead_sources', 4);
    }

    public function test_reimporting_shared_inbox_branches_without_external_ids_remains_idempotent(): void
    {
        $campaign = Campaign::factory()->create();
        $path = $this->csv("name,location,email,website\nSucursal Centro,Centro,info@shared.test,https://shared.test\nSucursal Norte,Norte,info@shared.test,https://shared.test\n");
        app(LeadImporter::class)->importCsv($path, $campaign);
        $second = app(LeadImporter::class)->importCsv($path, $campaign);
        $this->assertDatabaseCount('leads', 2);
        $this->assertSame(0, $second->imported_count);
        $this->assertSame(2, $second->duplicate_count);
        $this->assertDatabaseHas('leads', ['name' => 'Sucursal Norte', 'duplicate_status' => 'possible_duplicate']);
    }

    public function test_incompatible_names_and_locations_do_not_use_shared_inbox_as_identity(): void
    {
        $lead = Lead::factory()->create(['name' => 'Sucursal A', 'location' => 'Centro', 'domain' => 'shared.test', 'email' => 'info@shared.test', 'source_identifier' => null]);
        $match = app(LeadIdentityMatcher::class)->match(['source' => 'csv_manual', 'name' => 'Sucursal B', 'location' => 'Norte', 'domain' => 'shared.test', 'email' => 'info@shared.test']);
        $this->assertSame('possible', $match['status']);
        $this->assertSame($lead->id, $match['lead']->id);
    }

    public function test_one_strong_candidate_does_not_override_another_compatible_ambiguous_identity(): void
    {
        Lead::factory()->create(['name' => 'Empresa', 'location' => 'Centro', 'city' => 'Córdoba', 'domain' => 'one.test', 'email' => 'info@one.test']);
        Lead::factory()->create(['name' => 'Empresa', 'location' => null, 'city' => 'Córdoba', 'domain' => 'two.test', 'email' => 'info@two.test']);
        $match = app(LeadIdentityMatcher::class)->match(['source' => 'csv_manual', 'name' => 'Empresa', 'location' => 'Centro',
            'city' => 'Córdoba', 'domain' => 'one.test', 'email' => 'info@one.test']);
        $this->assertSame('possible', $match['status']);
        $this->assertCount(2, $match['candidates']);
    }

    public function test_exact_identity_remains_strong_and_possible_duplicate_cannot_contact(): void
    {
        $lead = Lead::factory()->create(['name' => 'Sucursal A', 'location' => 'Centro', 'domain' => 'shared.test', 'email' => 'info@shared.test', 'duplicate_status' => 'possible_duplicate']);
        $match = app(LeadIdentityMatcher::class)->match(['source' => 'csv_manual', 'name' => 'Sucursal A', 'location' => 'Centro', 'domain' => 'shared.test', 'email' => 'info@shared.test']);
        $this->assertSame('strong', $match['status']);
        $this->expectException(ValidationException::class);
        app(ExclusionService::class)->assertContactable($lead);
    }

    public function test_busy_job_redispatches_with_delay_instead_of_consuming_tries(): void
    {
        $this->travelTo(now()->startOfSecond());
        $campaign = Campaign::factory()->create();
        $run = ImportRun::create(['campaign_id' => $campaign->id, 'source' => 'csv_manual', 'status' => 'processing', 'claim_token' => 'other-worker', 'claimed_at' => now()->subMinutes(10), 'file_path' => '/not/read']);
        (new ImportLeadFile($run->id))->handle(app(LeadImporter::class));
        Queue::assertPushed(ImportLeadFile::class, fn ($job) => $job->runId === $run->id && $job->queue === 'sales' && $job->delay === 301 && $job->tries === 3);
        $this->assertSame('other-worker', $run->fresh()->claim_token);
        $this->assertSame('processing', $run->fresh()->status);
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_expired_heartbeat_recovers_checkpoint_and_counters_once(): void
    {
        $campaign = Campaign::factory()->create();
        $path = $this->csv("name,email\nPrimero,one@example.test\nSegundo,two@example.test\n");
        $run = ImportRun::create(['campaign_id' => $campaign->id, 'source' => 'csv_manual', 'status' => 'processing', 'claim_token' => 'dead-worker', 'claimed_at' => now()->subSeconds(901), 'file_path' => $path, 'processed_rows' => 1, 'raw_count' => 1, 'imported_count' => 1, 'file_hash' => hash_file('sha256', $path)]);
        $first = Lead::factory()->create(['name' => 'Primero', 'email' => 'one@example.test']);
        $first->sources()->create(['source' => 'csv_manual', 'import_run_id' => $run->id]);
        (new ImportLeadFile($run->id))->handle(app(LeadImporter::class));
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(2, $run->fresh()->processed_rows);
        $this->assertSame(2, $run->fresh()->imported_count);
        $this->assertNull($run->fresh()->claim_token);
        $this->assertDatabaseCount('leads', 2);
        (new ImportLeadFile($run->id))->handle(app(LeadImporter::class));
        $this->assertDatabaseCount('leads', 2);
    }

    public function test_changed_csv_is_rejected_without_losing_checkpoint(): void
    {
        $campaign = Campaign::factory()->create();
        $path = $this->csv("name\nDifferent\n");
        $run = ImportRun::create(['campaign_id' => $campaign->id, 'source' => 'csv_manual', 'file_path' => $path, 'file_hash' => hash('sha256', 'old'), 'status' => 'failed', 'processed_rows' => 1, 'raw_count' => 1]);
        try {
            app(LeadImporter::class)->importCsv($path, $campaign, ['run_id' => $run->id]);
            $this->fail('Debe rechazar el CSV modificado.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('archivo cambió', $exception->getMessage());
        }
        $this->assertSame(1, $run->fresh()->processed_rows);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertNull($run->fresh()->claim_token);
    }

    public function test_failed_hook_cannot_overwrite_another_workers_claim_or_terminal_state(): void
    {
        $campaign = Campaign::factory()->create();
        $run = ImportRun::create(['campaign_id' => $campaign->id, 'source' => 'csv_manual', 'status' => 'processing', 'claim_token' => 'new-worker', 'claimed_at' => now()]);
        (new ImportLeadFile($run->id))->failed(new \RuntimeException('stale'));
        $this->assertSame('processing', $run->fresh()->status);
        $this->assertSame('new-worker', $run->fresh()->claim_token);
        $run->update(['status' => 'completed', 'claim_token' => null, 'claimed_at' => null]);
        (new ImportLeadFile($run->id))->failed(new \RuntimeException('late'));
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_scraper_waits_for_busy_import_and_reuses_existing_csv_after_expiry(): void
    {
        $this->travelTo(now()->startOfSecond());
        $campaign = Campaign::factory()->create();
        $path = $this->csv("name,email,place_id\nNegocio,one@example.test,map-id\n");
        $scrape = ScrapeRun::create(['campaign_id' => $campaign->id, 'query' => 'synthetic', 'dry_run' => false, 'status' => 'running', 'output_file' => $path, 'exit_code' => 0, 'result_limit' => 10]);
        $import = ImportRun::create(['campaign_id' => $campaign->id, 'scrape_run_id' => $scrape->id, 'source' => 'google_maps', 'status' => 'processing', 'claim_token' => 'dead-worker', 'claimed_at' => now()->subMinutes(10), 'file_path' => $path]);
        (new ExecuteScrapeRun($scrape->id))->handle(app(GoogleMapsScraperService::class));
        $this->assertSame('running', $scrape->fresh()->status);
        $this->assertSame('waiting_import', $scrape->fresh()->phase);
        $this->assertNull($scrape->fresh()->finished_at);
        Queue::assertPushed(ExecuteScrapeRun::class, fn ($job) => $job->delay === 301);
        $this->travel(302)->seconds();
        (new ExecuteScrapeRun($scrape->id))->handle(app(GoogleMapsScraperService::class));
        $this->assertSame('completed', $scrape->fresh()->status);
        $this->assertSame('completed', $import->fresh()->status);
        $this->assertSame(1, $scrape->fresh()->imported_count);
        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseCount('import_runs', 1);
    }

    public function test_retrying_cancelled_scraper_resumes_the_cancelled_import_checkpoint(): void
    {
        $campaign = Campaign::factory()->create();
        $path = $this->csv("name,email,place_id\nPrimero,one@example.test,first\nSegundo,two@example.test,second\n");
        $scrape = ScrapeRun::create(['campaign_id' => $campaign->id, 'query' => 'synthetic', 'dry_run' => false, 'status' => 'cancelled',
            'cancel_requested_at' => now(), 'output_file' => $path, 'exit_code' => 0, 'result_limit' => 10]);
        $import = ImportRun::create(['campaign_id' => $campaign->id, 'scrape_run_id' => $scrape->id, 'source' => 'google_maps',
            'status' => 'cancelled', 'file_path' => $path, 'processed_rows' => 1, 'raw_count' => 1, 'imported_count' => 1,
            'file_hash' => hash_file('sha256', $path)]);
        $first = Lead::factory()->create(['name' => 'Primero', 'email' => 'one@example.test', 'source' => 'google_maps', 'source_identifier' => 'first']);
        $first->sources()->create(['source' => 'google_maps', 'source_identifier' => 'first', 'import_run_id' => $import->id]);
        $scraper = app(GoogleMapsScraperService::class);
        $scraper->retry($scrape);
        $this->assertSame('queued', $import->fresh()->status);
        $result = $scraper->executeRun($scrape->fresh());
        $this->assertSame('completed', $result->status);
        $this->assertSame('completed', $import->fresh()->status);
        $this->assertSame(2, $import->fresh()->processed_rows);
        $this->assertSame(2, $import->fresh()->imported_count);
        $this->assertDatabaseCount('leads', 2);
        $this->assertDatabaseCount('import_runs', 1);
    }

    public function test_retrying_cancelled_scraper_does_not_clear_an_active_import_claim(): void
    {
        $campaign = Campaign::factory()->create();
        $scrape = ScrapeRun::create(['campaign_id' => $campaign->id, 'query' => 'synthetic', 'dry_run' => false, 'status' => 'cancelled', 'cancel_requested_at' => now()]);
        $import = ImportRun::create(['campaign_id' => $campaign->id, 'scrape_run_id' => $scrape->id, 'source' => 'google_maps',
            'status' => 'cancelled', 'claim_token' => 'stopping-worker', 'claimed_at' => now()]);
        try {
            app(GoogleMapsScraperService::class)->retry($scrape);
            $this->fail('La reserva activa debe conservarse hasta que su propietario la libere.');
        } catch (ValidationException) {
            $this->assertSame('stopping-worker', $import->fresh()->claim_token);
            $this->assertSame('cancelled', $scrape->fresh()->status);
            $this->assertNotNull($scrape->fresh()->cancel_requested_at);
        }
        Queue::assertNothingPushed();
    }

    public function test_scraper_old_worker_cannot_finalize_another_claim(): void
    {
        $campaign = Campaign::factory()->create();
        $path = $this->csv("name\nExample\n");
        $run = ScrapeRun::create(['campaign_id' => $campaign->id, 'query' => 'synthetic', 'dry_run' => false, 'status' => 'running', 'output_file' => $path, 'exit_code' => 0, 'result_limit' => 10]);
        $importer = Mockery::mock(LeadImporter::class);
        $importer->shouldReceive('importCsv')->once()->andReturnUsing(function () use ($run, $campaign): ImportRun {
            $run->update(['claim_token' => 'replacement', 'claimed_at' => now()]);

            return ImportRun::create(['campaign_id' => $campaign->id, 'source' => 'google_maps', 'status' => 'completed']);
        });
        $result = (new GoogleMapsScraperService($importer))->executeRun($run);
        $this->assertSame('replacement', $result->claim_token);
        $this->assertSame('running', $result->status);
    }

    public function test_full_analysis_includes_late_errors_mapping_and_duplicates_without_mutation(): void
    {
        $campaign = Campaign::factory()->create();
        Lead::factory()->create(['name' => 'Existing', 'domain' => 'shared.test', 'email' => 'info@shared.test']);
        $path = $this->csv("negocio;correo;sitio\nUno;one@example.test;https://one.test\nDos;two@example.test;https://two.test\nTres;three@example.test;https://three.test\nCuatro;four@example.test;https://four.test\nCinco;five@example.test;https://five.test\n;missing-name@example.test;\nOtra sucursal;info@shared.test;https://shared.test\nMal correo;invalid;\n");
        $analysis = app(LeadImporter::class)->analyze($path, $campaign, ['name' => 'negocio', 'email' => 'correo', 'website' => 'sitio']);
        $this->assertSame(8, $analysis['rows']);
        $this->assertSame(6, $analysis['valid']);
        $this->assertSame(2, $analysis['invalid']);
        $this->assertSame(1, $analysis['possible_duplicates']);
        $this->assertSame([6, 8], array_column($analysis['issues'], 'row'));
        $this->assertContains('name', $analysis['recognized']);
        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseCount('import_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_preview_mapping_analyze_confirm_and_rejected_download_are_integrated(): void
    {
        $campaign = Campaign::factory()->create();
        $file = UploadedFile::fake()->createWithContent('staged.csv', "negocio;correo\nBueno;ok@example.test\n=1+1;invalid\n");
        $preview = $this->post(route('imports.preview'), ['campaign_id' => $campaign->id, 'source' => 'csv_manual', 'csv' => $file])->assertOk();
        $token = $preview->viewData('token');
        $mapping = ['name' => 'negocio', 'email' => 'correo'];
        $analyzed = $this->post(route('imports.analyze'), ['token' => $token, 'mapping' => $mapping])->assertOk()->assertSee('Rechazadas');
        $analysis = $analyzed->viewData('analysis');
        $this->assertSame(1, $analysis['invalid']);
        $this->post(route('imports.confirm'), ['token' => $token, 'mapping' => $mapping, 'analysis_hash' => $analysis['analysis_hash']])->assertRedirect()->assertSessionHasNoErrors();
        $run = ImportRun::firstOrFail();
        (new ImportLeadFile($run->id))->handle(app(LeadImporter::class));
        $this->assertSame(1, $run->fresh()->imported_count);
        $this->assertSame(1, $run->fresh()->error_count);
        $this->assertDatabaseCount('import_row_issues', 1);
        $context = json_encode(SystemLog::where('type', 'import_error')->firstOrFail()->context);
        $this->assertStringNotContainsString('=1+1', $context);
        $csv = $this->get(route('imports.rejections', $run))->assertOk()->streamedContent();
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringContainsString('dirección válida', $csv);
    }

    public function test_confirm_rejects_changed_mapping_and_hash_and_is_single_use(): void
    {
        $campaign = Campaign::factory()->create();
        $response = $this->post(route('imports.preview'), ['campaign_id' => $campaign->id, 'csv' => UploadedFile::fake()->createWithContent('staged.csv', "name,email\nExample,ok@example.test\n")])->assertOk();
        $token = $response->viewData('token');
        $hash = $response->viewData('analysis')['analysis_hash'];
        $this->post(route('imports.confirm'), ['token' => $token, 'mapping' => ['name' => 'email'], 'analysis_hash' => $hash])->assertSessionHasErrors('mapping');
        $this->assertDatabaseCount('import_runs', 0);
        $this->post(route('imports.confirm'), ['token' => $token, 'analysis_hash' => $hash])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('imports.confirm'), ['token' => $token])->assertNotFound();
    }

    public function test_rejections_and_checkpoint_are_not_duplicated_after_retry(): void
    {
        $campaign = Campaign::factory()->create();
        $path = $this->csv("name,email\nInvalid,broken\nValid,ok@example.test\n");
        $run = app(LeadImporter::class)->importCsv($path, $campaign);
        $run->update(['status' => 'failed']);
        app(LeadImporter::class)->importCsv($path, $campaign, ['run_id' => $run->id]);
        $this->assertSame(1, $run->fresh()->error_count);
        $this->assertSame(1, $run->fresh()->imported_count);
        $this->assertSame(1, ImportRowIssue::where('import_run_id', $run->id)->count());
    }

    public function test_paused_campaign_cancels_unclaimed_import_without_reading_a_file(): void
    {
        $campaign = Campaign::factory()->create(['status' => 'paused']);
        $run = ImportRun::create(['campaign_id' => $campaign->id, 'source' => 'csv_manual', 'status' => 'queued', 'file_path' => '/never/read']);
        (new ImportLeadFile($run->id))->handle(app(LeadImporter::class));
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertNotNull($run->fresh()->finished_at);
        $this->assertNull($run->fresh()->claim_token);
        Queue::assertNothingPushed();
    }

    public function test_malformed_rows_are_reported_and_checkpointed_with_the_same_analysis_rules(): void
    {
        $campaign = Campaign::factory()->create();
        $path = $this->csv("name,email\nInvalid,ok@example.test,extra\nValid,ok@example.test\n");
        $analysis = app(LeadImporter::class)->analyze($path, $campaign);
        $run = app(LeadImporter::class)->importCsv($path, $campaign);
        $this->assertSame(1, $analysis['invalid']);
        $this->assertSame(1, $run->error_count);
        $this->assertSame(2, $run->processed_rows);
        $this->assertSame(1, $run->imported_count);
        $rows = iterator_to_array(app(LeadImporter::class)->rejectedRows($run));
        $this->assertCount(2, $rows);
        $this->assertSame(1, $rows[1][0]);
        $this->assertSame('extra', $rows[1][4]);
    }

    public function test_unknown_mapping_does_not_silently_import_other_columns(): void
    {
        $campaign = Campaign::factory()->create();
        $path = $this->csv("name,email\nExample,ok@example.test\n");
        $this->expectException(ValidationException::class);
        app(LeadImporter::class)->analyze($path, $campaign, ['name' => 'missing']);
    }

    public function test_stale_scraper_retry_cannot_erase_a_new_owner_claim(): void
    {
        $campaign = Campaign::factory()->create();
        $run = ScrapeRun::create(['campaign_id' => $campaign->id, 'query' => 'synthetic', 'dry_run' => false, 'status' => 'failed', 'claimed_at' => now()->subSeconds(1201), 'claim_token' => 'expired']);
        ScrapeRun::whereKey($run->id)->update(['status' => 'running', 'claimed_at' => now(), 'claim_token' => 'new-worker']);
        try {
            app(GoogleMapsScraperService::class)->retry($run);
            $this->fail('La reserva actual debe estar protegida.');
        } catch (ValidationException) {
            $this->assertSame('new-worker', $run->fresh()->claim_token);
            $this->assertSame('running', $run->fresh()->status);
        }
        Queue::assertNothingPushed();
    }

    public function test_database_failure_hides_bound_contact_details_and_keeps_the_checkpoint(): void
    {
        $campaign = Campaign::factory()->create();
        $path = $this->csv("name,email\nPrimero,one@example.test\nSegundo,secret@example.test\n");
        $run = ImportRun::create(['campaign_id' => $campaign->id, 'source' => 'csv_manual', 'status' => 'failed', 'file_path' => $path,
            'processed_rows' => 1, 'raw_count' => 1, 'imported_count' => 1]);
        $original = new QueryException('sqlite', 'insert into leads (email, phone) values (?, ?)',
            ['secret@example.test', '+543516001111'], new \PDOException('Synthetic database failure'));
        $matcher = Mockery::mock(LeadIdentityMatcher::class);
        $matcher->shouldReceive('match')->once()->andThrow($original);
        $this->app->instance(LeadIdentityMatcher::class, $matcher);
        try {
            app(LeadImporter::class)->importCsv($path, $campaign, ['run_id' => $run->id]);
            $this->fail('El fallo de base de datos debe propagarse sin datos de la fila.');
        } catch (\RuntimeException $exception) {
            $this->assertNull($exception->getPrevious());
            $this->assertStringContainsString('base de datos', $exception->getMessage());
            $this->assertStringNotContainsString('secret@example.test', (string) $exception);
            $this->assertStringNotContainsString('+543516001111', (string) $exception);
        }
        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame(1, $run->processed_rows);
        $this->assertSame(1, $run->imported_count);
        $this->assertStringNotContainsString('secret@example.test', $run->error_message);
        $this->assertStringNotContainsString('+543516001111', $run->error_message);
        $this->assertNull($run->claim_token);
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_sql_failure_before_acquiring_an_import_claim_is_also_redacted(): void
    {
        $campaign = Campaign::factory()->create();
        $path = $this->csv("name\nExample\n");
        ImportRun::creating(function (): void {
            throw new \PDOException('SQL contains init-secret@example.test +543516003333');
        });
        try {
            app(LeadImporter::class)->importCsv($path, $campaign);
            $this->fail('La inicialización debe proteger los detalles SQL.');
        } catch (\RuntimeException $exception) {
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('init-secret@example.test', (string) $exception);
            $this->assertStringNotContainsString('+543516003333', (string) $exception);
        }
        $this->assertDatabaseCount('import_runs', 0);
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_scraper_and_failed_hooks_redact_sql_errors_including_wrapped_causes(): void
    {
        $campaign = Campaign::factory()->create();
        $path = $this->csv("name\nExample\n");
        $scrape = ScrapeRun::create(['campaign_id' => $campaign->id, 'query' => 'synthetic', 'dry_run' => false,
            'status' => 'queued', 'output_file' => $path, 'exit_code' => 0, 'result_limit' => 10]);
        $original = new \RuntimeException('Wrapper: private@example.test', 0, new \PDOException('SQL INSERT private@example.test +543516009999'));
        $importer = Mockery::mock(LeadImporter::class);
        $importer->shouldReceive('importCsv')->once()->andThrow($original);
        try {
            (new GoogleMapsScraperService($importer))->executeRun($scrape);
            $this->fail('La excepción original no debe difundirse.');
        } catch (\RuntimeException $exception) {
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('private@example.test', (string) $exception);
            $this->assertStringNotContainsString('+543516009999', (string) $exception);
        }
        (new ExecuteScrapeRun($scrape->id))->failed($original);
        $this->assertStringNotContainsString('private@example.test', $scrape->fresh()->stderr);
        $this->assertStringNotContainsString('+543516009999', $scrape->fresh()->stderr);
        $run = ImportRun::create(['campaign_id' => $campaign->id, 'source' => 'csv_manual', 'status' => 'queued']);
        (new ImportLeadFile($run->id))->failed($original);
        $this->assertStringNotContainsString('private@example.test', $run->fresh()->error_message);
        $this->assertStringNotContainsString('+543516009999', $run->fresh()->error_message);
    }

    public function test_analysis_warns_about_branches_and_repeated_rows_inside_an_unimported_csv(): void
    {
        $campaign = Campaign::factory()->create(['source' => 'google_maps']);
        $path = $this->csv("name,location,email,website,place_id\nSucursal Centro,Centro,info@shared.test,https://shared.test,centro\nSucursal Norte,Norte,info@shared.test,https://shared.test,norte\nSucursal Norte,Norte,info@shared.test,https://shared.test,norte\n");
        $analysis = app(LeadImporter::class)->analyze($path, $campaign);
        $this->assertSame(1, $analysis['possible_duplicates']);
        $this->assertSame(1, $analysis['duplicates']);
        $this->assertSame(3, $analysis['valid']);
        $this->assertNotEmpty($analysis['warnings']);
        $this->assertStringNotContainsString('info@shared.test', json_encode($analysis));
        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseCount('import_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_foreign_key_check_rejects_structurally_valid_orphan_backup(): void
    {
        $path = storage_path('app/private/orphan.sqlite');
        $pdo = new PDO('sqlite:'.$path);
        foreach (['CREATE TABLE migrations (migration TEXT)', 'CREATE TABLE leads (id INTEGER PRIMARY KEY)', 'CREATE TABLE campaigns (id INTEGER PRIMARY KEY)', 'CREATE TABLE contact_attempts (id INTEGER PRIMARY KEY, lead_id INTEGER REFERENCES leads(id))', 'CREATE TABLE campaign_lead (lead_id INTEGER REFERENCES leads(id), campaign_id INTEGER REFERENCES campaigns(id))'] as $sql) {
            $pdo->exec($sql);
        }
        $insert = $pdo->prepare('INSERT INTO migrations (migration) VALUES (?)');
        foreach (DB::table('migrations')->pluck('migration') as $migration) {
            $insert->execute([$migration]);
        }
        $pdo->exec('INSERT INTO contact_attempts (id,lead_id) VALUES (1,999)');
        $this->assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
        $this->expectExceptionMessage('relaciones rotas');
        app(BackupService::class)->verify($path);
    }
}
