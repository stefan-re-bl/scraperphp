<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LlmBridgeRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['lead_ids' => 'array'];
    }
}
