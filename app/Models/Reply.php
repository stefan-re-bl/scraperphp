<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reply extends Model
{
    public const CATEGORIES = [
        'negative',
        'not_now',
        'existing_provider',
        'no_budget',
        'price_question',
        'information_request',
        'interested',
        'meeting',
        'proposal',
        'won',
        'lost',
        'other',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'requires_human' => 'boolean',
        ];
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function contactAttempt()
    {
        return $this->belongsTo(ContactAttempt::class);
    }
}
