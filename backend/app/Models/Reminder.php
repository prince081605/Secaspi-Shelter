<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reminder extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'animal_id',
        'remindable_type',
        'remindable_id',
        'title',
        'reminder_date',
        'status',
    ];

    protected $casts = [
        'reminder_date' => 'date',
    ];

    public function animal()
    {
        return $this->belongsTo(Animal::class, 'animal_id');
    }

    public function remindable()
    {
        return $this->morphTo();
    }
}
