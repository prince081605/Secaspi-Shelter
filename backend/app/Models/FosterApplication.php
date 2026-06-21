<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FosterApplication extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'animal_id',
        'status',
        'start_date',
        'end_date',
        'notes',
    ];

    public function animal()
    {
        return $this->belongsTo(Animal::class, 'animal_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
