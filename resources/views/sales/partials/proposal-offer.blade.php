@php
    $selectedOfferId = $editingProposal ? $editingProposal->offer_id : ($defaultOfferId ?? null);
@endphp
<label>Oferta
    <select name="offer_id" data-proposal-offer>
        <option value="">Servicio personalizado</option>
        @foreach ($offers as $offerOption)
            <option value="{{ $offerOption->id }}" data-service="{{ $offerOption->name }}" @selected((string) $selectedOfferId === (string) $offerOption->id)>{{ $offerOption->name }}{{ $offerOption->active ? '' : ' · Archivada' }}</option>
        @endforeach
    </select>
</label>
