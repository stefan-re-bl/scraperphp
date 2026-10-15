<?php

namespace App\Models;

use App\Services\NotionValueParser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    use HasFactory;

    public const STATUSES = [
        'NEW',
        'ENRICHED',
        'QUALIFIED',
        'QUEUED',
        'CONTACTED',
        'REPLIED',
        'INTERESTED',
        'PROPOSAL',
        'NEGOTIATION',
        'WON',
        'LOST',
        'PAUSED',
    ];

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (Lead $lead): void {
            if ($lead->isDirty('relationship_type') && ! $lead->isDirty('is_customer') && ! $lead->isDirty('is_collaborator')) {
                $lead->is_customer = $lead->relationship_type === 'customer';
                $lead->is_collaborator = $lead->relationship_type === 'collaborator';
            }
            if ($lead->isDirty('is_customer') || $lead->isDirty('is_collaborator')) {
                $lead->relationship_type = $lead->is_customer ? 'customer' : ($lead->is_collaborator ? 'collaborator' : 'prospect');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_customer' => 'boolean',
            'is_collaborator' => 'boolean',
            'classification_pending' => 'boolean',
            'estimated_value_minor' => 'integer',
            'origin_created_at' => 'datetime',
            'imported_last_contact_at' => 'datetime',
            'delivery_due_at' => 'datetime',
            'tags' => 'array',
            'next_action_at' => 'datetime',
            'booking_visible' => 'boolean',
            'signals' => 'array',
            'social_urls' => 'array',
            'contact_methods' => 'array',
            'acquired_at' => 'datetime',
            'enriched_at' => 'datetime',
            'excluded_at' => 'datetime',
        ];
    }

    protected function estimatedValue(): Attribute
    {
        return Attribute::get(fn (): ?string => NotionValueParser::decimalFromMinor($this->estimated_value_minor));
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(LeadContact::class)->orderByDesc('is_primary')->orderBy('name');
    }

    public function services(): HasMany
    {
        return $this->hasMany(ClientService::class);
    }

    public function relationshipLabel(): string
    {
        return $this->is_customer && $this->is_collaborator ? 'Cliente y colaborador' : ($this->is_customer ? 'Cliente' : ($this->is_collaborator ? 'Colaborador' : 'Prospecto'));
    }

    public function contactAttempts()
    {
        return $this->hasMany(ContactAttempt::class);
    }

    public function campaigns(): BelongsToMany
    {
        return $this->belongsToMany(Campaign::class)->withPivot(['id', 'offer_id', 'status', 'contact_enabled', 'next_action_at', 'interested_at', 'won_at', 'lost_at'])->withTimestamps();
    }

    public function scopeInCampaign(Builder $query, int $campaignId): void
    {
        $query->where(function ($nested) use ($campaignId) {
            $nested->whereHas('campaigns', fn ($campaign) => $campaign->where('campaigns.id', $campaignId))
                ->orWhereHas('sources.importRun', fn ($source) => $source->where('campaign_id', $campaignId))
                ->orWhereHas('contactAttempts', fn ($attempt) => $attempt->where('campaign_id', $campaignId));
        });
    }

    public static function statusLabel(string $status): string
    {
        return ['NEW' => 'Nuevo', 'ENRICHED' => 'Analizado', 'QUALIFIED' => 'Calificado', 'QUEUED' => 'En cola', 'CONTACTED' => 'Contactado', 'REPLIED' => 'Respondió', 'INTERESTED' => 'Interesado', 'PROPOSAL' => 'Propuesta', 'NEGOTIATION' => 'Negociación', 'WON' => 'Ganado', 'LOST' => 'Perdido', 'PAUSED' => 'Pausado'][$status] ?? $status;
    }

    public function replies()
    {
        return $this->hasMany(Reply::class);
    }

    public function proposals()
    {
        return $this->hasMany(Proposal::class);
    }

    public function offerScores(): HasMany
    {
        return $this->hasMany(LeadOfferScore::class);
    }

    public function scoreComponents()
    {
        return $this->hasMany(ScoreComponent::class);
    }

    public function signalRows()
    {
        return $this->hasMany(Signal::class);
    }

    public function suggestedOffer()
    {
        return $this->belongsTo(Offer::class, 'suggested_offer_id');
    }

    public function sources()
    {
        return $this->hasMany(LeadSource::class);
    }

    public function statusHistories()
    {
        return $this->hasMany(LeadStatusHistory::class);
    }

    public function possibleDuplicateOf()
    {
        return $this->belongsTo(self::class, 'possible_duplicate_of_lead_id');
    }

    public function possibleDuplicates()
    {
        return $this->hasMany(self::class, 'possible_duplicate_of_lead_id');
    }
}
