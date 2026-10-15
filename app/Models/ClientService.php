<?php

namespace App\Models;

use App\Services\NotionValueParser;
use Database\Factories\ClientServiceFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientService extends Model
{
    /** @use HasFactory<ClientServiceFactory> */
    use HasFactory;

    public const TYPES = ['web' => 'Desarrollo web', 'hosting' => 'Hosting', 'maintenance' => 'Mantenimiento', 'ecommerce' => 'Ecommerce', 'automation' => 'Automatización', 'other' => 'Otro'];

    public const STATUSES = ['planned' => 'Planificado', 'active' => 'Activo', 'paused' => 'Pausado', 'completed' => 'Finalizado', 'cancelled' => 'Cancelado'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'starts_on' => 'date', 'ends_on' => 'date', 'renews_on' => 'date'];
    }

    protected function amount(): Attribute
    {
        return Attribute::get(fn (): ?string => NotionValueParser::decimalFromMinor($this->amount_minor));
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }
}
