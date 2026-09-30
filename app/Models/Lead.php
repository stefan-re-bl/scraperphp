<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
