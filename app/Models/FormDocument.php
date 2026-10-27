<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormDocument extends Model
{
    protected $guarded = [];

    public function fields()
    {
        return $this->hasMany(ExtractedField::class);
    }

    public function inquiry()
    {
        return $this->hasOne(StudentInquiry::class);
    }
}
