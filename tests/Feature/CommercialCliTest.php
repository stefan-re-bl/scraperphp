<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\ContactAttempt;
use App\Models\Lead;
use App\Models\Reply;
use App\Services\CommercialWorkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CommercialCliTest extends TestCase
{
    use RefreshDatabase;

    public function test_consultation_resources_emit_json_and_leave_business_data_unchanged(): void
    {
        $lead = Lead::factory()->create(['name' => 'Negocio sintético']);
        $campaign = Campaign::factory()->create();
        $lead->campaigns()->attach($campaign, ['status' => 'INTERESTED']);
        Reply::create(['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'category' => 'interested', 'received_at' => now(), 'requires_human' => true]);
        $before = [$lead->fresh()->getAttributes(), $campaign->fresh()->getAttributes(), ContactAttempt::count()];
        foreach (['leads', 'campaigns', 'opportunities', 'runs', 'actions', 'metrics', 'export'] as $resource) {
            $this->assertSame(0, Artisan::call('sales:inspect', ['resource' => $resource, '--campaign' => $campaign->id, '--limit' => 10]));
            $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertTrue($output['ok']);
            $this->assertSame($resource, $output['resource']);
        }
        $this->assertSame($before, [$lead->fresh()->getAttributes(), $campaign->fresh()->getAttributes(), ContactAttempt::count()]);
        Artisan::call('sales:inspect', ['resource' => 'actions', '--campaign' => $campaign->id]);
        $this->assertSame(app(CommercialWorkService::class)->actions($campaign->id), json_decode(Artisan::output(), true)['data']);
    }

    public function test_actions_use_the_same_lead_selection_before_limiting_results(): void
    {
        $a = Lead::factory()->create(['name' => 'Elegido']);
        $b = Lead::factory()->create(['name' => 'Ajeno']);
        $campaign = Campaign::factory()->create();
        foreach ([$a, $b] as $lead) {
            $lead->campaigns()->attach($campaign, ['status' => 'INTERESTED']);
            Reply::create(['lead_id' => $lead->id, 'campaign_id' => $campaign->id, 'category' => 'interested', 'requires_human' => true, 'received_at' => now()]);
        }
        $this->assertSame(0, Artisan::call('sales:inspect', ['resource' => 'actions', '--ids' => (string) $a->id]));
        $actions = json_decode(Artisan::output(), true)['data'];
        $this->assertNotEmpty($actions);
        $this->assertSame([$a->id], array_values(array_unique(array_column($actions, 'lead_id'))));
        Artisan::call('sales:inspect', ['resource' => 'actions', '--filters' => json_encode(['q' => 'Ajeno'])]);
        $this->assertSame([$b->id], array_values(array_unique(array_column(json_decode(Artisan::output(), true)['data'], 'lead_id'))));
        Artisan::call('sales:inspect', ['resource' => 'actions', '--ids' => '']);
        $this->assertSame([], json_decode(Artisan::output(), true)['data']);
        $this->assertSame(0, ContactAttempt::count());
    }

    public function test_invalid_input_has_structured_errors_and_consistent_exit_codes(): void
    {
        foreach ([['resource' => 'unknown'], ['resource' => 'leads', '--filters' => 'not JSON'], ['resource' => 'leads', '--limit' => 2001], ['resource' => 'metrics', '--dimension' => 'bad'], ['resource' => 'leads', '--campaign' => 999], ['resource' => 'leads', '--output' => '/tmp/unexpected.csv']] as $arguments) {
            $this->assertSame(2, Artisan::call('sales:inspect', $arguments));
            $this->assertFalse(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['ok']);
        }
    }

    public function test_cli_selection_matches_web_and_does_not_expand_empty_ids(): void
    {
        $selected = Lead::factory()->create(['name' => 'Seleccionado']);
        Lead::factory()->create(['name' => 'Fuera']);
        $this->assertSame(0, Artisan::call('sales:inspect', ['resource' => 'leads', '--ids' => (string) $selected->id]));
        $rows = json_decode(Artisan::output(), true)['data'];
        $this->assertSame([$selected->id], array_column($rows, 'id'));
        $this->get('/leads?ids='.$selected->id)->assertOk()->assertSee('Seleccionado')->assertDontSee('Fuera');
        $this->assertSame(0, Artisan::call('sales:inspect', ['resource' => 'leads', '--ids' => '']));
        $this->assertSame([], json_decode(Artisan::output(), true)['data']);
    }

    public function test_explicit_csv_file_creation_is_safe_limited_and_refuses_overwrite(): void
    {
        Lead::factory()->create(['name' => '=Formula']);
        Lead::factory()->count(2)->create();
        $path = storage_path('app/private/cli.csv');
        $this->assertSame(0, Artisan::call('sales:inspect', ['resource' => 'export', '--output' => $path, '--limit' => 1]));
        $this->assertSame(1, json_decode(Artisan::output(), true)['data']['rows']);
        $before = file_get_contents($path);
        $this->assertSame(2, Artisan::call('sales:inspect', ['resource' => 'export', '--output' => $path]));
        $this->assertSame($before, file_get_contents($path));
    }
}
