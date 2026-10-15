@php($defaultTemplateBody = 'Hola '.chr(123).chr(123).'nombre'.chr(125).chr(125).".\n\n".chr(123).chr(123).'oferta_detallada'.chr(125).chr(125))
<div class="grid">
<label>Nombre<input name="name" value="{{ $editingTemplate?->name }}" required></label>
<label>Idioma<input name="language" value="{{ $editingTemplate?->language ?? $preferences['language'] }}" required></label>
<label>Canal<select name="channel">@foreach (['email' => 'Email', 'phone' => 'Teléfono', 'whatsapp' => 'WhatsApp', 'manual' => 'Otro'] as $key => $label)<option value="{{ $key }}" @selected(($editingTemplate?->channel ?? 'email') === $key)>{{ $label }}</option>@endforeach</select></label>
<label>Segmento<input name="segment" value="{{ $editingTemplate?->segment }}"></label>
<label>Etapa<select name="stage">@foreach (['initial' => 'Contacto inicial', 'follow_up_1' => 'Seguimiento 1', 'follow_up_2' => 'Seguimiento 2'] as $key => $label)<option value="{{ $key }}" @selected(($editingTemplate?->stage ?? 'initial') === $key)>{{ $label }}</option>@endforeach</select></label>
<label>Oferta<select name="offer_id"><option value="">General</option>@foreach ($offers as $offer)<option value="{{ $offer->id }}" @selected($editingTemplate?->offer_id === $offer->id)>{{ $offer->name }}</option>@endforeach</select></label>
</div>
<label>Asunto<input name="subject" value="{{ $editingTemplate?->subject }}"></label>
<label>Cuerpo<textarea name="body" required>{{ $editingTemplate?->body ?? $defaultTemplateBody }}</textarea></label>
<input type="hidden" name="active" value="0"><label><input type="checkbox" name="active" value="1" @checked($editingTemplate?->active ?? true)> Disponible</label>
