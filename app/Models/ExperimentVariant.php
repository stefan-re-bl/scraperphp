<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExperimentVariant extends Model
{
    protected $guarded = [];

    public function assignments(): BelongsToMany
    {
        return $this->belongsToMany(Lead::class, 'lead_experiment_variant');
    }

    public function contactAttempts(): HasMany
    {
        return $this->hasMany(ContactAttempt::class);
    }

    public function getRealAttemptsCountAttribute(): int
    {
        if (isset($this->attributes['real_attempts_count'])) {
            return (int) $this->attributes['real_attempts_count'];
        }

        return $this->contactAttempts()->where('status', 'sent')->where('dry_run', false)->whereNotNull('performed_at')->count();
    }

    public function experiment()
    {
        return $this->belongsTo(Experiment::class);
    }
}
