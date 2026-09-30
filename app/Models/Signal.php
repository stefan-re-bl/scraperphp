<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Signal extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['detected_at' => 'datetime'];
    }
}
