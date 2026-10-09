<div class="grid">
<label>Componente<select name="component">@if ($editingRule)<option value="{{ $editingRule->component }}" selected>{{ $editingRule->description }}</option>@endif
@foreach (App\Services\LeadScorer::DEFAULTS as $key => $definition)<option value="{{ $key }}" @selected($editingRule?->component === $key)>{{ $definition[2] }}</option>@endforeach
@foreach (['signal_manual_booking_dependency' => 'Reserva manual confirmada', 'signal_no_visible_booking' => 'Sin reserva visible confirmada', 'signal_weak_website_content' => 'Contenido débil confirmado'] as $key => $label)<option value="{{ $key }}" @selected($editingRule?->component === $key)>{{ $label }}</option>@endforeach</select></label>
<label>Oferta<select name="offer_id"><option value="">General</option>@foreach ($offers as $offer)<option value="{{ $offer->id }}" @selected($editingRule?->offer_id === $offer->id)>{{ $offer->name }}</option>@endforeach</select></label>
<label>Aspecto<select name="dimension">@foreach (['data'=>'Calidad de datos','contact'=>'Posibilidad de contacto','fit'=>'Encaje comercial'] as $key=>$label)<option value="{{ $key }}" @selected(($editingRule?->dimension ?? 'fit') === $key)>{{ $label }}</option>@endforeach</select></label>
<label>Puntos<input type="number" name="points" value="{{ $editingRule?->points ?? 10 }}" min="-100" max="100" required></label>
<label>Peso<input type="number" name="weight" value="{{ $editingRule?->weight ?? 1 }}" min="1" max="10" required></label>
<label>Descripción<input name="description" value="{{ $editingRule?->description }}" required></label></div>
<input type="hidden" name="active" value="0"><label><input type="checkbox" name="active" value="1" @checked($editingRule?->active ?? true)> Activa</label>
