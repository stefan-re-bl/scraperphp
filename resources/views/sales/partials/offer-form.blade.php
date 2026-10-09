<div class="grid">
@foreach (['name' => 'Nombre', 'target_customer' => 'Cliente objetivo', 'segment' => 'Segmento', 'demo_url' => 'Demo', 'portfolio_url' => 'Portfolio', 'suggested_cta' => 'Invitación al siguiente paso'] as $field => $label)
<label>{{ $label }}<input name="{{ $field }}" value="{{ $editingOffer?->$field }}" @required($field === 'name')></label>@endforeach
<label>Palabras de encaje, separadas por coma<input name="keywords" value="{{ implode(', ', $editingOffer?->rules['keywords'] ?? []) }}"></label>
<label>Señales de encaje, separadas por coma<input name="match_signals" value="{{ implode(', ', $editingOffer?->rules['signals'] ?? []) }}" placeholder="ads_active, observed_problem"></label>
<label>Encaje mínimo<input type="number" name="minimum_match" value="{{ $editingOffer?->rules['minimum'] ?? 20 }}" min="1"></label>
</div>
@foreach (['pains' => 'Problemas que resuelve', 'benefits' => 'Beneficios', 'compatibility_signals' => 'Descripción del encaje', 'assets' => 'Activos disponibles'] as $field => $label)<label>{{ $label }}<textarea name="{{ $field }}">{{ $editingOffer?->$field }}</textarea></label>@endforeach
<input type="hidden" name="active" value="0"><label><input type="checkbox" name="active" value="1" @checked($editingOffer?->active ?? true)> Disponible</label>
