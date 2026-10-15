<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class ContactAttempt extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (ContactAttempt $attempt): void {
            if ($attempt->isDirty('score_snapshot') && ($attempt->getRawOriginal('score_snapshot') !== null
                || $attempt->newQuery()->whereKey($attempt->id)->value('score_snapshot') !== null)) {
                throw ValidationException::withMessages(['score_snapshot' => 'La puntuación histórica del contacto es inmutable.']);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'score_snapshot' => 'array',
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
