@php
    $tone = match ($status) {
        'WON', 'won', 'completed', 'confirmed', 'available' => 'good',
        'QUEUED', 'CONTACTED', 'INTERESTED', 'PROPOSAL', 'NEGOTIATION', 'active', 'running', 'processing' => 'blue',
        'PAUSED', 'paused', 'planned', 'pending', 'queued', 'dry_run', 'cancelled' => 'warn',
        'excluded', 'failed' => 'bad',
        default => 'neutral',
    };
    $statusText = in_array($status, App\Models\Lead::STATUSES) ? App\Models\Lead::statusLabel($status) : (['rejected'=>'Descartada','available'=>'Disponible','archived'=>'Archivada','active'=>'Activa','paused'=>'Pausada','planned'=>'Pendiente','pending'=>'Pendiente','queued'=>'En cola','running'=>'En curso','processing'=>'Importando','completed'=>'Completada','confirmed'=>'Confirmada','failed'=>'Error','cancelled'=>'Cancelada','dry_run'=>'Simulación','won'=>'Ganada','lost'=>'Perdida','excluded'=>'Excluido'][$status] ?? $status);
@endphp
<span class="badge {{ $tone }}"><span class="status-dot"></span>{{ $statusText }}</span>
