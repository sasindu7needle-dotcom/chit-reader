<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentInquiry extends Model
{
    protected $guarded = [];

    public function document()
    {
        return $this->belongsTo(FormDocument::class, 'form_document_id');
    }
}
