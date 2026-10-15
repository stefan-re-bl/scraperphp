<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportRowIssue extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['errors' => 'array'];
    }
}
