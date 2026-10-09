<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Campaign extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean',
            'kill_switch' => 'boolean',
            'starts_at' => 'date',
            'ends_at' => 'date',
        ];
    }

    public function offer()
    {
        return $this->belongsTo(Offer::class);
    }

    public function leads(): BelongsToMany
    {
        return $this->belongsToMany(Lead::class)->withPivot(['id', 'offer_id', 'status', 'next_action_at', 'interested_at', 'won_at', 'lost_at'])->withTimestamps();
    }

    public function queries()
    {
        return $this->hasMany(CampaignQuery::class);
    }

    public function contactAttempts()
    {
        return $this->hasMany(ContactAttempt::class);
    }

    public function experiments()
    {
        return $this->hasMany(Experiment::class);
    }

    public function importRuns()
    {
        return $this->hasMany(ImportRun::class);
    }

    public function scrapeRuns()
    {
        return $this->hasMany(ScrapeRun::class);
    }
}
