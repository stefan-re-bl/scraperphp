<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Exclusion;
use App\Models\Lead;
use App\Services\CampaignMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class InfiniteScrollTest extends TestCase
{
    use RefreshDatabase;

    public function test_leads_load_in_separate_batches_while_preserving_filters_and_order(): void
    {
        $leads = Lead::factory()->count(26)->create(['name' => 'Coincidencia', 'score_total' => 50]);
        Lead::factory()->create(['name' => 'Fuera del filtro', 'score_total' => 50]);
        $first = $this->get('/leads?q=Coincidencia&sort=name');

        $first->assertOk()->assertSee('data-infinite-list="leads"', false)->assertSee('data-infinite-next', false)->assertDontSee('aria-label="Paginación"', false);
        $first->assertViewHas('leads', fn (LengthAwarePaginator $rows): bool => $rows->count() === 25 && $rows->total() === 26 && $rows->first()->id === $leads->last()->id && ! $rows->contains('id', $leads->first()->id));

        $this->get($first->viewData('leads')->nextPageUrl())->assertOk()
            ->assertViewHas('leads', fn (LengthAwarePaginator $rows): bool => $rows->modelKeys() === [$leads->first()->id])
            ->assertSee('Fin de la lista')->assertDontSee('data-infinite-next', false);
    }

    public function test_campaign_lead_batches_keep_the_campaign_scope_and_active_tab(): void
    {
        $campaign = Campaign::factory()->create();
        $leads = Lead::factory()->count(26)->create(['name' => 'Prospecto de campaña']);
        $leads->each(fn (Lead $lead) => app(CampaignMembership::class)->attach($lead, $campaign));
        Lead::factory()->create(['name' => 'Otra campaña']);

        $first = $this->get('/campaigns/'.$campaign->id.'?tab=leads&city=Córdoba');
        $first->assertOk()->assertSee('data-infinite-list="leads"', false)->assertSee('data-infinite-next', false);

        $this->get($first->viewData('leads')->nextPageUrl())->assertOk()
            ->assertViewHas('tab', 'leads')->assertViewHas('filters', fn (array $filters): bool => $filters['city'] === 'Córdoba' && $filters['campaign'] === $campaign->id)
            ->assertViewHas('leads', fn (LengthAwarePaginator $rows): bool => $rows->modelKeys() === [$leads->first()->id]);
    }

    public function test_queue_batches_keep_the_campaign_and_skipped_contacts(): void
    {
        $campaign = Campaign::factory()->create();
        $leads = Lead::factory()->count(3)->create(['score_total' => 50]);
        $leads->each(fn (Lead $lead) => app(CampaignMembership::class)->attach($lead, $campaign));
        $first = $this->get('/contact-queue?campaign='.$campaign->id.'&skip='.$leads->last()->id);

        $first->assertOk()->assertSee('data-infinite-list="queue"', false)->assertSee('data-infinite-next', false);
        $first->assertViewHas('leads', fn (LengthAwarePaginator $rows): bool => $rows->total() === 2 && $rows->first()->id === $leads[1]->id);

        $this->get($first->viewData('leads')->nextPageUrl())->assertOk()
            ->assertViewHas('campaign', fn (Campaign $context): bool => $context->id === $campaign->id)
            ->assertViewHas('leads', fn (LengthAwarePaginator $rows): bool => $rows->modelKeys() === [$leads->first()->id])
            ->assertSee('data-infinite-item="'.$leads->first()->id.'"', false)->assertDontSee('data-infinite-next', false);
    }

    public function test_reply_batches_preserve_the_pending_filter(): void
    {
        $this->freezeTime();
        $lead = Lead::factory()->create();
        $remaining = $lead->replies()->create(['category' => 'interested', 'received_at' => now(), 'requires_human' => true]);
        for ($index = 0; $index < 50; $index++) {
            $lead->replies()->create(['category' => 'other', 'received_at' => now(), 'requires_human' => true]);
        }
        $lead->replies()->create(['category' => 'other', 'received_at' => now(), 'requires_human' => false]);

        $first = $this->get('/replies?pending=1');
        $first->assertOk()->assertSee('data-infinite-list="replies"', false)->assertSee('data-infinite-next', false);
        $first->assertViewHas('replies', fn (LengthAwarePaginator $rows): bool => $rows->count() === 50 && $rows->total() === 51);

        $this->get($first->viewData('replies')->nextPageUrl())->assertOk()
            ->assertSee('Pendientes de atención')->assertViewHas('replies', fn (LengthAwarePaginator $rows): bool => $rows->modelKeys() === [$remaining->id]);
    }

    public function test_proposal_batches_include_the_edit_form_for_each_loaded_record(): void
    {
        $this->freezeTime();
        $lead = Lead::factory()->create();
        $remaining = $lead->proposals()->create(['service' => 'Propuesta anterior', 'currency' => 'ARS']);
        for ($index = 0; $index < 50; $index++) {
            $lead->proposals()->create(['service' => 'Propuesta reciente', 'currency' => 'ARS']);
        }

        $first = $this->get('/proposals');
        $first->assertOk()->assertSee('data-infinite-list="proposals"', false)->assertSee('data-infinite-next', false);

        $this->get($first->viewData('proposals')->nextPageUrl())->assertOk()
            ->assertViewHas('proposals', fn (LengthAwarePaginator $rows): bool => $rows->modelKeys() === [$remaining->id])
            ->assertSee('data-infinite-item="'.$remaining->id.'"', false)->assertSee('id="proposal-edit-'.$remaining->id.'"', false)
            ->assertSee('action="'.route('proposals.update', $remaining).'"', false)->assertDontSee('data-infinite-next', false);
    }

    public function test_exclusion_batches_keep_the_removal_action_and_stop_at_the_end(): void
    {
        $this->freezeTime();
        $remaining = Exclusion::create(['type' => 'email', 'value' => 'anterior@example.test']);
        for ($index = 0; $index < 100; $index++) {
            Exclusion::create(['type' => 'email', 'value' => 'exclusion'.$index.'@example.test']);
        }

        $first = $this->get('/exclusions');
        $first->assertOk()->assertSee('data-infinite-list="exclusions"', false)->assertSee('data-infinite-next', false);

        $this->get($first->viewData('exclusions')->nextPageUrl())->assertOk()
            ->assertViewHas('exclusions', fn (LengthAwarePaginator $rows): bool => $rows->modelKeys() === [$remaining->id])
            ->assertSee('action="/exclusions/'.$remaining->id.'"', false)->assertSee('Fin de la lista')->assertDontSee('data-infinite-next', false);
    }

    public function test_empty_lists_do_not_offer_another_batch(): void
    {
        $this->get('/leads')->assertOk()->assertSee('Tu base de contactos empieza acá')->assertDontSee('data-infinite-next', false);
    }
}
