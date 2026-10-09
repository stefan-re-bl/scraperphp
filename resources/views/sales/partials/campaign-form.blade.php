<div class="grid">
<label>Nombre<input name="name" value="{{ $editingCampaign->name }}" required></label>
<label>Segmento<input name="segment" value="{{ $editingCampaign->segment }}"></label>
<label>País<input name="country" value="{{ $editingCampaign->country }}"></label>
<label>Ciudad<input name="city" value="{{ $editingCampaign->city }}"></label>
<label>Oferta<select name="offer_id"><option value="">Sin oferta</option>@foreach ($offers as $offer)<option value="{{ $offer->id }}" @selected($editingCampaign->offer_id === $offer->id)>{{ $offer->name }}</option>@endforeach</select></label>
<label>Fuente<select name="source">@foreach (['google_maps'=>'Google Maps','csv_manual'=>'CSV','referral'=>'Referido','web'=>'Web'] as $key=>$label)<option value="{{ $key }}" @selected($editingCampaign->source === $key)>{{ $label }}</option>@endforeach</select></label>
<label>Idioma<input name="language" value="{{ $editingCampaign->language }}" required></label>
<label>Canal<select name="channel">@foreach (['email'=>'Email','phone'=>'Teléfono','whatsapp'=>'WhatsApp','manual'=>'Otro'] as $key=>$label)<option value="{{ $key }}" @selected($editingCampaign->channel === $key)>{{ $label }}</option>@endforeach</select></label>
<label>Límite diario<input type="number" name="daily_limit" value="{{ $editingCampaign->daily_limit }}" min="1" max="500" required></label>
<label>Límite de adquisición<input type="number" name="scrape_limit" value="{{ $editingCampaign->scrape_limit }}" min="1" max="500" required></label>
<label>Días al primer seguimiento<input type="number" name="follow_up_1_delay_days" value="{{ $editingCampaign->follow_up_1_delay_days }}" min="1" required></label>
<label>Días del primer al segundo seguimiento<input type="number" name="follow_up_2_delay_days" value="{{ $editingCampaign->follow_up_2_delay_days }}" min="1" required></label>
</div>
