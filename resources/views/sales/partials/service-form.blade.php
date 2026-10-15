<label>Nombre del servicio<input name="name" value="{{ old('name', $editingService?->name) }}" required maxlength="255"></label>
<div class="grid">
<label>Tipo<select name="type">@foreach(App\Models\ClientService::TYPES as $value => $label)<option value="{{ $value }}" @selected(old('type', $editingService?->type) === $value)>{{ $label }}</option>@endforeach</select></label>
<label>Estado de ejecución<select name="status">@foreach(App\Models\ClientService::STATUSES as $value => $label)<option value="{{ $value }}" @selected(old('status', $editingService?->status) === $value)>{{ $label }}</option>@endforeach</select></label>
<label>Importe del servicio<input type="number" min="0" step="0.01" name="amount" value="{{ old('amount', $editingService?->amount) }}"></label>
<label>Moneda<input name="currency" value="{{ old('currency', $editingService?->currency) }}" maxlength="3" placeholder="ARS"></label>
<label>Inicio<input type="date" name="starts_on" value="{{ old('starts_on', $editingService?->starts_on?->format('Y-m-d')) }}"></label>
<label>Finalización<input type="date" name="ends_on" value="{{ old('ends_on', $editingService?->ends_on?->format('Y-m-d')) }}"></label>
<label>Próxima renovación<input type="date" name="renews_on" value="{{ old('renews_on', $editingService?->renews_on?->format('Y-m-d')) }}"></label>
<label>Propuesta vinculada<select name="proposal_id"><option value="">Sin propuesta</option>@foreach($lead->proposals as $proposal)<option value="{{ $proposal->id }}" @selected(old('proposal_id', $editingService?->proposal_id) == $proposal->id)>{{ $proposal->service }} · versión {{ $proposal->version }}</option>@endforeach</select></label>
</div>
<label>URL de referencia<input type="url" name="reference_url" value="{{ old('reference_url', $editingService?->reference_url) }}"></label>
<label>Notas<textarea name="notes">{{ old('notes', $editingService?->notes) }}</textarea></label>
<p class="form-help">El importe registra el valor del servicio. Los pagos se gestionan por separado.</p>
