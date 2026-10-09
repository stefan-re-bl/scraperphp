<?php

namespace Tests\Feature;

use App\Models\Campaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MigrationUpgradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_preserves_provenance_without_assigning_simulations_or_inferences_as_real_outcomes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sales-upgrade-');
        $previousConnection = DB::getDefaultConnection();
        config(['database.connections.upgrade_test' => array_merge(config('database.connections.sqlite'), ['database' => $path])]);
        DB::setDefaultConnection('upgrade_test');
        try {
            $oldMigrations = collect(File::files(database_path('migrations')))->reject(fn ($file) => str_contains($file->getFilename(), 'improve_general_sales_workflow'))->map(fn ($file) => 'database/migrations/'.$file->getFilename())->all();
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'upgrade_test', '--path' => $oldMigrations, '--force' => true, '--no-interaction' => true]));
            $first = Campaign::factory()->create();
            $second = Campaign::factory()->create();
            $unrelated = Campaign::factory()->create();
            $leadId = DB::table('leads')->insertGetId(['name' => 'Negocio anterior', 'segment' => 'servicios', 'country' => 'Argentina', 'status' => 'CONTACTED', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('contact_attempts')->insert([
                ['lead_id' => $leadId, 'campaign_id' => $first->id, 'channel' => 'email', 'status' => 'sent', 'manual_intervention' => true, 'dry_run' => true, 'performed_at' => now()],
                ['lead_id' => $leadId, 'campaign_id' => $second->id, 'channel' => 'email', 'status' => 'dry_run', 'manual_intervention' => false, 'dry_run' => true, 'performed_at' => null],
            ]);
            DB::table('signals')->insert(['lead_id' => $leadId, 'type' => 'manual_contact_dependency', 'value' => 'true', 'source' => 'system', 'detected_at' => now()]);

            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'upgrade_test', '--force' => true, '--no-interaction' => true]));

            $this->assertDatabaseHas('leads', ['id' => $leadId, 'name' => 'Negocio anterior']);
            $this->assertDatabaseHas('contact_attempts', ['campaign_id' => $first->id, 'status' => 'sent', 'dry_run' => false]);
            $this->assertDatabaseHas('contact_attempts', ['campaign_id' => $second->id, 'status' => 'dry_run', 'dry_run' => true]);
            $this->assertDatabaseHas('campaign_lead', ['lead_id' => $leadId, 'campaign_id' => $first->id, 'status' => 'CONTACTED']);
            $this->assertDatabaseHas('campaign_lead', ['lead_id' => $leadId, 'campaign_id' => $second->id, 'status' => 'ENRICHED']);
            $this->assertDatabaseMissing('campaign_lead', ['campaign_id' => $unrelated->id]);
            $this->assertDatabaseHas('signals', ['lead_id' => $leadId, 'evidence_kind' => 'inferred', 'review_status' => 'pending']);
        } finally {
            DB::purge('upgrade_test');
            DB::setDefaultConnection($previousConnection);
            File::delete($path);
        }
    }
}
