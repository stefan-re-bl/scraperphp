<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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

    protected function casts(): array
    {
        return [
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

    public function contactAttempts()
    {
        return $this->hasMany(ContactAttempt::class);
    }

    public function campaigns(): BelongsToMany
    {
        return $this->belongsToMany(Campaign::class)->withPivot(['id', 'offer_id', 'status', 'next_action_at', 'interested_at', 'won_at', 'lost_at'])->withTimestamps();
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
