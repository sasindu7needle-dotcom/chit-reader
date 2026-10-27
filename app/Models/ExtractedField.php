<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExtractedField extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'review_required' => 'boolean'];
    }
}
