<?php

namespace Tests\Feature;

use App\Models\ClientService;
use App\Models\Lead;
use App\Models\LeadContact;
use App\Services\BackupService;
use App\Services\MetricsService;
use App\Services\NotionImporter;
use App\Services\NotionNotesSanitizer;
use App\Services\NotionValueParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class NotionImportTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('moneyValues')]
    public function test_converts_spanish_amounts_without_floating_point(?string $value, ?int $expected): void
    {
        $this->assertSame($expected, app(NotionValueParser::class)->money($value));
    }

    public static function moneyValues(): array
    {
        return ['ARS' => ['240.000,00 ARS', 24000000], 'Unicode spaces' => ["240.000,25\u{00a0}ARS", 24000025], 'zero' => ['0,00 ARS', 0], 'empty' => ['', null], 'large' => ['9.999.999.999.999,99 ARS', 999999999999999]];
    }

    #[DataProvider('invalidMoney')]
    public function test_rejects_invalid_amounts_instead_of_changing_their_meaning(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(NotionValueParser::class)->money($value);
    }

    public static function invalidMoney(): array
    {
        return ['mixed separators' => ['240,000.00 ARS'], 'negative' => ['-10,00 ARS'], 'too precise' => ['1,234 ARS'], 'unknown currency' => ['10 USD'], 'invalid grouping' => ['1.00,00 ARS']];
    }

    public function test_spanish_dates_are_validated_and_converted_from_the_configured_timezone(): void
    {
        $parser = app(NotionValueParser::class);

        $this->assertSame('2025-10-31 03:20:00', $parser->date('31 de octubre de 2025 0:20', 'America/Argentina/Buenos_Aires'));
        $this->assertNull($parser->date('', 'UTC'));
        $this->expectException(\InvalidArgumentException::class);
        $parser->date('31 de febrero de 2025 0:20', 'UTC');
    }

    public function test_preview_uses_only_the_complete_csv_and_does_not_change_commercial_records(): void
    {
        $this->withZip([$this->row()], ['a' => $this->markdown('Ana', 'Diseño comercial seguro.')], function (string $path): void {
            $plan = app(NotionImporter::class)->preview($path);

            $this->assertSame(1, $plan['report']['read']);
            $this->assertSame(1, $plan['report']['new']);
            $this->assertSame(1, $plan['report']['ready']);
            $this->assertSame('notion:'.str_repeat('a', 32), $plan['report']['rows'][0]['key']);
            $this->assertDatabaseCount('leads', 0);
            $this->assertDatabaseCount('import_runs', 0);
        });
    }

    public function test_chooses_the_csv_with_more_fields_even_if_an_all_view_is_incomplete(): void
    {
        $this->withZip([$this->row()], ['a' => $this->markdown('Ana', '')], function (string $path): void {
            $zip = new ZipArchive;
            $zip->open($path);
            $zip->addFromString('CRM_all.csv', "Nombre,Estado\nAna,Socio\n");
            $zip->close();

            $plan = app(NotionImporter::class)->preview($path);

            $this->assertSame(24000000, $plan['records'][0]['data']['estimated_value_minor']);
            $this->assertSame(1, $plan['report']['read']);
        });
    }

    public function test_import_is_idempotent_for_identity_contacts_notes_and_services(): void
    {
        $this->withZip([$this->row()], ['a' => $this->markdown('Ana', 'Diseño comercial seguro.')], function (string $path): void {
            $importer = app(NotionImporter::class);
            $result = $importer->apply($importer->preview($path));
            $lead = Lead::firstOrFail();
            $notes = $lead->notes;

            $second = $importer->apply($importer->preview($path));

            $this->assertSame(1, $result['created']);
            $this->assertSame(0, $second['created']);
            $this->assertDatabaseCount('leads', 1);
            $this->assertDatabaseCount('lead_contacts', 1);
            $this->assertDatabaseCount('lead_sources', 1);
            $this->assertDatabaseCount('client_services', 0);
            $this->assertDatabaseCount('campaign_lead', 0);
            $this->assertDatabaseCount('contact_attempts', 0);
            $this->assertDatabaseCount('lead_status_histories', 0);
            $this->assertSame($notes, $lead->fresh()->notes);
            $this->assertDatabaseHas('leads', ['is_collaborator' => true, 'is_customer' => false, 'status' => 'NEW', 'manual_priority' => 'high', 'estimated_value_minor' => 24000000]);
            $this->assertSame('351 123', $lead->phone_original);
            $this->assertNull($lead->phone);
        });
    }

    public function test_closed_and_development_states_require_review_and_never_create_services_or_wins(): void
    {
        $rows = [array_replace($this->row(), ['Estado' => 'Cerrado 💪']), array_replace($this->row(), ['Nombre' => 'Pedro', 'Empresa' => 'Otra empresa', 'Teléfono' => '', 'Estado' => 'En desarrollo'])];
        $this->withZip($rows, ['a' => $this->markdown('Ana', ''), 'b' => $this->markdown('Pedro', '', 'Otra empresa', '')], function (string $path): void {
            $importer = app(NotionImporter::class);
            $preview = $importer->preview($path);
            $this->assertSame(2, $preview['report']['ambiguous']);
            $importer->apply($preview);
            $this->assertDatabaseCount('leads', 0);

            $key = $preview['report']['rows'][0]['key'];
            $importer->apply($importer->preview($path, [$key => ['action' => 'new', 'is_customer' => true, 'is_collaborator' => false]]));

            $this->assertDatabaseCount('leads', 1);
            $this->assertDatabaseHas('leads', ['is_customer' => true, 'status' => 'NEW']);
            $this->assertDatabaseCount('client_services', 0);
            $this->assertDatabaseCount('lead_status_histories', 0);
        });
    }

    public function test_one_shared_phone_is_reviewed_and_explicit_matching_preserves_existing_commercial_data(): void
    {
        $existing = Lead::factory()->create(['name' => 'Cliente existente', 'phone' => '+543516001111', 'phone_original' => '+54 351 600 1111', 'is_customer' => true,
            'is_collaborator' => true, 'manual_priority' => 'low', 'status' => 'WON', 'notes' => 'Nota manual', 'estimated_value_minor' => 700]);
        $service = ClientService::factory()->for($existing, 'lead')->create();
        $row = array_replace($this->row(), ['Teléfono' => '+54 351 600 1111']);
        $this->withZip([$row], ['a' => $this->markdown('Ana', 'Nota importada.', 'Empresa de prueba', '+54 351 600 1111')], function (string $path) use ($existing, $service): void {
            $importer = app(NotionImporter::class);
            $preview = $importer->preview($path);
            $this->assertSame(1, $preview['report']['possible_duplicates']);
            $this->assertSame(0, $preview['report']['ready']);
            $key = $preview['report']['rows'][0]['key'];

            $result = $importer->apply($importer->preview($path, [$key => ['action' => 'match', 'lead_id' => $existing->id]]));

            $this->assertSame(1, $result['matched']);
            $this->assertDatabaseCount('leads', 1);
            $this->assertDatabaseHas('leads', ['id' => $existing->id, 'status' => 'WON', 'is_customer' => true, 'is_collaborator' => true, 'manual_priority' => 'low', 'estimated_value_minor' => 700]);
            $this->assertStringContainsString('Nota manual', $existing->fresh()->notes);
            $this->assertStringContainsString('Nota importada.', $existing->fresh()->notes);
            $this->assertModelExists($service);
        });
    }

    public function test_matching_notion_import_projects_filled_main_fields_to_the_existing_primary_contact(): void
    {
        $existing = Lead::factory()->create(['name' => 'Empresa de prueba', 'entity_type' => 'organization', 'contact_person' => 'Ana',
            'contact_role' => 'Directora', 'email' => null, 'phone' => null, 'phone_original' => null]);
        $primary = LeadContact::factory()->for($existing)->create(['name' => 'Ana', 'role' => 'Directora', 'is_primary' => true,
            'email' => null, 'phone' => null, 'phone_original' => null]);
        $row = array_replace($this->row(), ['Email' => 'ana@example.test', 'Teléfono' => '+54 351 600 1111']);
        $this->withZip([$row], ['a' => $this->markdown('Ana', 'Nota adicional.', 'Empresa de prueba', '+54 351 600 1111')], function (string $path) use ($existing, $primary): void {
            $importer = app(NotionImporter::class);
            $preview = $importer->preview($path);
            $key = $preview['report']['rows'][0]['key'];
            $result = $importer->apply($importer->preview($path, [$key => ['action' => 'match', 'lead_id' => $existing->id]]));
            $existing->refresh();
            $primary->refresh();

            $this->assertSame(1, $result['matched']);
            $this->assertSame('ana@example.test', $existing->email);
            $this->assertSame($existing->email, $primary->email);
            $this->assertSame($existing->phone, $primary->phone);
            $this->assertSame($existing->phone_original, $primary->phone_original);
            $this->assertSame('Directora', $primary->role);
            $this->assertSame(1, $existing->contacts()->where('is_primary', true)->count());
            $count = $existing->contacts()->count();
            $notes = $existing->notes;
            $second = $importer->apply($importer->preview($path));
            $this->assertSame(0, $second['matched']);
            $this->assertSame($count, $existing->contacts()->count());
            $this->assertSame($notes, $existing->fresh()->notes);
            $this->assertSame($existing->email, $primary->fresh()->email);
        });
    }

    public function test_same_name_markdown_with_distinct_dates_is_not_assigned_arbitrarily(): void
    {
        $row = array_replace($this->row(), ['Nombre' => 'Nuevo cliente', 'Empresa' => '', 'Teléfono' => '', 'Añadido' => '']);
        $this->withZip([$row], ['a' => $this->markdown('Nuevo cliente', 'Primera nota.', '', ''), 'b' => $this->markdown('Nuevo cliente', 'Segunda nota.', '', '')], function (string $path): void {
            $preview = app(NotionImporter::class)->preview($path);

            $this->assertContains('markdown_ambiguous', $preview['report']['rows'][0]['review']);
            $this->assertSame(0, $preview['report']['ready']);
            app(NotionImporter::class)->apply($preview);
            $this->assertDatabaseCount('leads', 0);
        });
    }

    #[DataProvider('invalidSharedPhones')]
    public function test_unusable_shared_phone_values_do_not_link_different_organizations(string $phone): void
    {
        $existing = Lead::factory()->create(['name' => 'Cliente previo', 'phone' => null, 'phone_original' => $phone]);
        $rows = [array_replace($this->row(), ['Teléfono' => $phone]), array_replace($this->row(), ['Nombre' => 'Pedro', 'Empresa' => 'Otra empresa', 'Teléfono' => $phone])];
        $this->withZip($rows, ['a' => $this->markdown('Ana', '', 'Empresa de prueba', $phone), 'b' => $this->markdown('Pedro', '', 'Otra empresa', $phone)], function (string $path) use ($existing): void {
            $plan = app(NotionImporter::class)->preview($path);

            $this->assertSame(2, $plan['report']['ready']);
            $this->assertSame(0, $plan['report']['possible_duplicates']);
            $this->assertSame([], $plan['report']['rows'][0]['duplicate_export_keys']);
            $this->assertSame([], $plan['report']['rows'][1]['candidate_lead_ids']);
            $this->assertModelExists($existing);
        });
    }

    public static function invalidSharedPhones(): array
    {
        return ['text marker' => ['FALSE'], 'zero marker' => ['0000000000'], 'incomplete number' => ['12345']];
    }

    public function test_shared_organization_in_the_export_is_deferred_until_an_identity_decision(): void
    {
        $rows = [$this->row(), array_replace($this->row(), ['Nombre' => 'Pedro', 'Teléfono' => ''])];
        $this->withZip($rows, ['a' => $this->markdown('Ana', ''), 'b' => $this->markdown('Pedro', '', 'Empresa de prueba', '')], function (string $path): void {
            $preview = app(NotionImporter::class)->preview($path);

            $this->assertSame(2, $preview['report']['ambiguous']);
            $this->assertContains('shared_identity_in_export', $preview['report']['rows'][0]['review']);
            $this->assertSame(0, $preview['report']['ready']);
        });
    }

    public function test_credentials_are_excluded_from_records_reports_and_command_output(): void
    {
        $synthetic = 'SyntheticCredentialForTestOnly';
        $md = $this->markdown('Ana', "Servicio web.\n\nWordpress\n- Usuario Administrador: demo\n- Contraseña Administrador: ".$synthetic);
        $this->withZip([$this->row()], ['a' => $md], function (string $path) use ($synthetic): void {
            $importer = app(NotionImporter::class);
            $preview = $importer->preview($path);
            $this->assertSame(1, $preview['report']['discarded_notes']);
            $this->assertStringNotContainsString($synthetic, json_encode($preview['report']));
            $importer->apply($preview);

            $this->assertNull(Lead::firstOrFail()->notes);
            $this->assertStringNotContainsString($synthetic, json_encode(DB::table('lead_sources')->get()));
            $report = tempnam(sys_get_temp_dir(), 'notion-report-');
            try {
                $this->artisan('crm:import-notion', ['zip' => $path, '--report' => $report])->assertSuccessful();
                $this->assertStringNotContainsString($synthetic, File::get($report));
            } finally {
                File::delete($report);
            }
        });
    }

    public function test_invalid_dates_and_amounts_are_reported_by_field_and_omitted_only_after_review(): void
    {
        $row = array_replace($this->row(), ['Valor estimado' => 'importe inválido', 'Último contacto' => '31 de febrero de 2025 0:20']);
        $this->withZip([$row], ['a' => $this->markdown('Ana', '')], function (string $path): void {
            $importer = app(NotionImporter::class);
            $preview = $importer->preview($path);
            $this->assertSame(['estimated_value_minor_invalid', 'imported_last_contact_at_invalid'], $preview['report']['rows'][0]['errors']);
            $this->assertSame(0, $preview['report']['ready']);
            $key = $preview['report']['rows'][0]['key'];

            $importer->apply($importer->preview($path, [$key => ['action' => 'new', 'omit_invalid' => true]]));

            $lead = Lead::firstOrFail();
            $this->assertNull($lead->estimated_value_minor);
            $this->assertNull($lead->imported_last_contact_at);
        });
    }

    #[DataProvider('unsafeNames')]
    public function test_rejects_unsafe_archive_entries_without_writing_records(string $name): void
    {
        $this->withZip([$this->row()], [], function (string $path) use ($name): void {
            $zip = new ZipArchive;
            $zip->open($path);
            $zip->addFromString($name, 'synthetic');
            $zip->close();
            $report = tempnam(sys_get_temp_dir(), 'notion-report-');
            try {
                $this->artisan('crm:import-notion', ['zip' => $path, '--report' => $report])->assertFailed();
                $this->assertDatabaseCount('leads', 0);
                $this->assertSame('', File::get($report));
            } finally {
                File::delete($report);
            }
        });
    }

    public static function unsafeNames(): array
    {
        return ['parent path' => ['../unsafe.md'], 'encoded parent' => ['%2e%2e/unsafe.md'], 'absolute' => ['/unsafe.md'], 'unsupported' => ['attachment.exe'], 'backslash' => ['folder\\unsafe.md']];
    }

    public function test_command_requires_a_current_reviewed_report_before_applying(): void
    {
        $this->withZip([$this->row()], ['a' => $this->markdown('Ana', '')], function (string $path): void {
            $report = tempnam(sys_get_temp_dir(), 'notion-report-');
            try {
                $this->artisan('crm:import-notion', ['zip' => $path, '--apply' => true])->expectsOutput('La aplicación requiere --approved-report con la vista previa revisada.')->assertFailed();
                $this->artisan('crm:import-notion', ['zip' => $path, '--report' => $report])->assertSuccessful();
                Lead::factory()->create();
                $this->artisan('crm:import-notion', ['zip' => $path, '--apply' => true, '--approved-report' => $report])->expectsOutput('El ZIP, las decisiones o la cartera cambiaron. Genera y revisa otra vista previa.')->assertFailed();

                $this->assertDatabaseCount('lead_sources', 0);
                $this->assertDatabaseCount('import_runs', 0);
            } finally {
                File::delete($report);
            }
        });
    }

    public function test_command_applies_reviewed_data_and_verifies_a_real_sqlite_backup(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'notion-database-');
        $previous = DB::getDefaultConnection();
        config(['database.connections.notion_test' => array_merge(config('database.connections.sqlite'), ['database' => $database])]);
        DB::setDefaultConnection('notion_test');
        $report = tempnam(sys_get_temp_dir(), 'notion-report-');
        $applied = tempnam(sys_get_temp_dir(), 'notion-applied-');
        try {
            Artisan::call('migrate', ['--database' => 'notion_test', '--force' => true, '--no-interaction' => true]);
            $this->withZip([$this->row()], ['a' => $this->markdown('Ana', 'Nota segura.')], function (string $path) use ($report, $applied): void {
                $this->artisan('crm:import-notion', ['zip' => $path, '--report' => $report])->assertSuccessful();
                $this->artisan('crm:import-notion', ['zip' => $path, '--apply' => true, '--approved-report' => $report, '--report' => $applied])->assertSuccessful();

                $result = json_decode(File::get($applied), true);
                $this->assertSame(1, $result['result']['created']);
                $this->assertSame(0, app(BackupService::class)->verify($result['backup'])['leads']);
                $this->assertDatabaseCount('leads', 1);
                File::delete($result['backup']);
            });
        } finally {
            DB::purge('notion_test');
            DB::setDefaultConnection($previous);
            File::delete([$database, $report, $applied]);
        }
    }

    public function test_records_from_the_same_organization_can_share_one_lead_by_an_explicit_export_key(): void
    {
        $rows = [$this->row(), array_replace($this->row(), ['Nombre' => 'Pedro', 'Teléfono' => ''])];
        $this->withZip($rows, ['a' => $this->markdown('Ana', 'Nota de Ana.'), 'b' => $this->markdown('Pedro', 'Nota de Pedro.', 'Empresa de prueba', '')], function (string $path): void {
            $importer = app(NotionImporter::class);
            $preview = $importer->preview($path);
            $first = $preview['report']['rows'][0]['key'];
            $second = $preview['report']['rows'][1]['key'];

            $result = $importer->apply($importer->preview($path, [$first => ['action' => 'new'], $second => ['action' => 'match', 'match_key' => $first]]));

            $this->assertSame(1, $result['created']);
            $this->assertSame(1, $result['matched']);
            $this->assertDatabaseCount('leads', 1);
            $this->assertDatabaseCount('lead_contacts', 2);
            $this->assertDatabaseCount('lead_sources', 2);
            $this->assertStringContainsString('Nota de Pedro.', Lead::firstOrFail()->notes);
            $this->assertSame(0, app(MetricsService::class)->funnel()['counts']['acquired']);
        });
    }

    public function test_matching_another_contact_does_not_put_their_phone_or_email_on_the_primary_contact(): void
    {
        $rows = [array_replace($this->row(), ['Teléfono' => '']), array_replace($this->row(), ['Nombre' => 'Pedro', 'Teléfono' => '+54 351 600 2222', 'Email' => 'pedro@example.test'])];
        $markdowns = ['a' => $this->markdown('Ana', '', 'Empresa de prueba', ''), 'b' => $this->markdown('Pedro', '', 'Empresa de prueba', '+54 351 600 2222')."\nEmail: pedro@example.test"];
        $this->withZip($rows, $markdowns, function (string $path): void {
            $importer = app(NotionImporter::class);
            $preview = $importer->preview($path);
            $first = $preview['report']['rows'][0]['key'];
            $second = $preview['report']['rows'][1]['key'];

            $importer->apply($importer->preview($path, [$first => ['action' => 'new'], $second => ['action' => 'match', 'match_key' => $first]]));

            $lead = Lead::firstOrFail();
            $this->assertSame('Ana', $lead->contact_person);
            $this->assertNull($lead->phone);
            $this->assertNull($lead->email);
            $this->assertDatabaseHas('lead_contacts', ['lead_id' => $lead->id, 'name' => 'Pedro', 'phone' => '+543516002222', 'email' => 'pedro@example.test', 'is_primary' => false]);
            $this->assertDatabaseCount('leads', 1);
        });
    }

    public function test_reference_to_a_pending_principal_is_deferred_and_unknown_decisions_are_rejected(): void
    {
        $rows = [$this->row(), array_replace($this->row(), ['Nombre' => 'Pedro', 'Teléfono' => ''])];
        $this->withZip($rows, ['a' => $this->markdown('Ana', ''), 'b' => $this->markdown('Pedro', '', 'Empresa de prueba', '')], function (string $path): void {
            $importer = app(NotionImporter::class);
            $preview = $importer->preview($path);
            $first = $preview['report']['rows'][0]['key'];
            $second = $preview['report']['rows'][1]['key'];

            $plan = $importer->preview($path, [$first => ['action' => 'skip'], $second => ['action' => 'match', 'match_key' => $first]]);

            $this->assertSame(0, $plan['report']['ready']);
            $this->assertContains('target_requires_review', $plan['report']['rows'][1]['review']);
            $this->expectException(\RuntimeException::class);
            $importer->preview($path, ['unknown-key' => ['action' => 'new']]);
        });
    }

    public function test_import_rolls_back_when_the_cartera_changes_after_preview(): void
    {
        $this->withZip([$this->row()], ['a' => $this->markdown('Ana', '')], function (string $path): void {
            $importer = app(NotionImporter::class);
            $plan = $importer->preview($path);
            Lead::factory()->create();

            try {
                $importer->apply($plan);
                $this->fail('Una revisión desactualizada no debe modificar registros.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('La cartera cambió antes de iniciar la transacción. Genera otra vista previa.', $exception->getMessage());
            }

            $this->assertDatabaseCount('leads', 1);
            $this->assertDatabaseCount('import_runs', 0);
            $this->assertDatabaseCount('lead_sources', 0);
        });
    }

    public function test_notes_with_credentials_in_properties_are_excluded_even_if_values_span_lines(): void
    {
        $notes = app(NotionNotesSanitizer::class)->sanitize("# Ficha\nPassword:\nSyntheticCredentialForTestOnly\n\nServicio.");

        $this->assertNull($notes['notes']);
        $this->assertSame(1, $notes['discarded']);
    }

    private function row(): array
    {
        return ['Nombre' => 'Ana', 'Empresa' => 'Empresa de prueba', 'Estado' => 'Socio', 'Prioridad' => 'Alta', 'Valor estimado' => '240.000,00 ARS',
            'Teléfono' => '351 123', 'Email' => '', 'Añadido' => '31 de octubre de 2025 0:20', 'Último contacto' => '', 'Proxima Entrega' => ''];
    }

    private function markdown(string $name, string $body, string $company = 'Empresa de prueba', string $phone = '351 123'): string
    {
        return '# '.$name."\n\nAñadido: 31 de octubre de 2025 0:20\nEmpresa: ".$company."\nTeléfono: ".$phone."\n\n".$body;
    }

    private function withZip(array $rows, array $markdowns, callable $test): void
    {
        $path = tempnam(sys_get_temp_dir(), 'notion-export-');
        $stream = fopen('php://memory', 'r+');
        fputcsv($stream, array_keys($rows[0]), escape: '');
        foreach ($rows as $row) {
            fputcsv($stream, $row, escape: '');
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('CRM_all.csv', $content);
        $zip->addFromString('CRM.csv', $content);
        foreach ($markdowns as $letter => $markdown) {
            $zip->addFromString('CRM/Ficha '.str_repeat($letter, 32).'.md', $markdown);
        }
        $zip->close();
        try {
            $test($path);
        } finally {
            File::delete($path);
        }
    }
}
