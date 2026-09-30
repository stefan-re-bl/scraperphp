<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactAttempt extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'planned_at' => 'datetime',
            'performed_at' => 'datetime',
            'due_at' => 'datetime',
            'manual_intervention' => 'boolean',
            'dry_run' => 'boolean',
        ];
    }

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }
}
