<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Donation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'reference_no',
        'amount',
        'payment_method',
        'proof_image',
        'status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
