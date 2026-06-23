<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CareGuide extends Model
{
    protected $fillable = [
        'title',
        'species',
        'breed_keywords',
        'age_range',
        'behavioral_keywords',
        'category',
        'content',
    ];

    protected $casts = [
        'breed_keywords' => 'array',
        'behavioral_keywords' => 'array',
    ];
}
