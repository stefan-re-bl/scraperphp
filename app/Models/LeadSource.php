<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadSource extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['observed_at' => 'datetime'];
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function importRun()
    {
        return $this->belongsTo(ImportRun::class);
    }
}
