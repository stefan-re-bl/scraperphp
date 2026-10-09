<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScrapeRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'heartbeat_at' => 'datetime',
            'claimed_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'dry_run' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }
}
