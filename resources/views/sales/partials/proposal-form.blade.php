@php
    $proposalLead = $editingProposal?->lead ?? $leads->first();
    $offerContext = $proposalLead ? app(App\Services\CampaignMembership::class)->offerContext($proposalLead) : [];
    $defaultOfferId = $offerContext['default'] ?? null;
@endphp
<div class="grid" data-proposal-fields data-existing-proposal="{{ $editingProposal ? '1' : '0' }}">
@if($editingProposal)
<label>Prospecto<input value="{{ $editingProposal->lead?->name }}" readonly><input type="hidden" name="lead_id" value="{{ $editingProposal->lead_id }}" data-offer-context="{{ json_encode($offerContext) }}"></label>
<label>Campaña<input value="{{ $campaigns->firstWhere('id',$editingProposal->campaign_id)?->name ?? 'Sin campaña' }}" readonly><input type="hidden" name="campaign_id" value="{{ $editingProposal->campaign_id }}"></label>
@else
<label>Prospecto<select name="lead_id" required>@foreach($leads as $leadOption)<option value="{{ $leadOption->id }}" data-offer-context="{{ json_encode(app(App\Services\CampaignMembership::class)->offerContext($leadOption)) }}">{{ $leadOption->name }}</option>@endforeach</select></label>
@include('sales.partials.campaign-select',['selectedCampaignId'=>null])
@endif
@include('sales.partials.proposal-offer')
<label>Servicio<input name="service" value="{{ $editingProposal?->service ?? $offers->firstWhere('id', $defaultOfferId)?->name }}" data-proposal-service required></label>
<label>Importe<input type="number" step="0.01" name="amount" value="{{ $editingProposal?->amount }}" min="0"></label>
<label>Moneda<input name="currency" value="{{ $editingProposal?->currency ?? $preferences['currency'] }}" maxlength="3" required></label>
<label>Versión<input type="number" name="version" value="{{ $editingProposal?->version ?? 1 }}" min="1" required></label>
<label>Fecha enviada<input type="datetime-local" name="sent_at" value="{{ $editingProposal?->sent_at?->copy()->timezone($preferences['timezone'])->format('Y-m-d\TH:i') }}"></label>
<label>Resultado<select name="result">@foreach (['pending'=>'Pendiente','won'=>'Ganada','lost'=>'Perdida'] as $key=>$label)<option value="{{ $key }}" @selected(($editingProposal?->result ?? 'pending') === $key)>{{ $label }}</option>@endforeach</select></label>
</div>
<p class="form-help">La oferta completa el servicio; podés adaptar la descripción para este presupuesto. <a href="{{ route('offers', ['create' => 1]) }}">Crear una oferta</a></p>
@foreach (['response'=>'Respuesta','objections'=>'Objeciones','modifications'=>'Modificaciones'] as $field=>$label)<label>{{ $label }}<textarea name="{{ $field }}">{{ $editingProposal?->$field }}</textarea></label>@endforeach
