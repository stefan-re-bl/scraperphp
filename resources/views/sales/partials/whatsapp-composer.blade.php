<div>
    @component('sales.partials.drawer', ['drawerId' => 'whatsapp-compose', 'drawerTitle' => 'Preparar mensaje de WhatsApp', 'drawerEyebrow' => $lead->name])
        <div data-whatsapp-composer data-prepare-url="{{ route('leads.whatsapp', $lead) }}">
            <label>Campaña del mensaje
                <select data-whatsapp-campaign>
                    <option value="">{{ $lead->campaigns->isEmpty() ? 'Sin campaña' : 'Seleccionar campaña' }}</option>
                    @foreach ($lead->campaigns as $messageCampaign)
                        <option value="{{ $messageCampaign->id }}" @selected($lead->campaigns->count() === 1)>{{ $messageCampaign->name }}</option>
                    @endforeach
                </select>
            </label>
            <p class="form-help">Oferta: <strong data-whatsapp-offer>Preparando…</strong> · <a href="{{ route('offers', ['create' => 1]) }}">Crear una oferta</a></p>
            <label>Mensaje para WhatsApp<textarea data-message-body rows="10" placeholder="Prepará el mensaje para este contacto…"></textarea></label>
            <p class="form-help" data-whatsapp-status role="status" aria-live="polite">Podés revisar y editar el mensaje antes de abrir WhatsApp.</p>
            <div class="form-footer">
                <button type="button" class="secondary" data-whatsapp-prepare>Preparar de nuevo</button>
                @include('sales.partials.whatsapp-button', ['whatsappPhone' => null, 'whatsappUrl' => null])
            </div>
            <p class="form-help">Después de enviarlo, usá “Registrar contacto” para guardar lo realizado.</p>
        </div>
    @endcomponent
</div>
