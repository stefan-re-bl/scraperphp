<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadStatusHistory extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }
}
