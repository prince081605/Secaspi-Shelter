<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FaqEntry extends Model
{
    protected $fillable = [
        'question',
        'answer',
        'tags',
        'enabled',
        'hits',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];
}
