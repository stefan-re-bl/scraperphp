<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
