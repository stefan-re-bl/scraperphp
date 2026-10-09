<?php

namespace App\Models;

use Database\Factories\SavedLeadViewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SavedLeadView extends Model
{
    /** @use HasFactory<SavedLeadViewFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['filters' => 'array'];
    }
}
