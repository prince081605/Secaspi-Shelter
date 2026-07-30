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
        'settlement',
        'category',
        'proof_image',
        'status',
        'is_anonymous',
        'donated_at',
    ];

    protected $casts = [
        'is_anonymous' => 'boolean',
        'donated_at'   => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function paymentSessions()
    {
        return $this->hasMany(PaymentSession::class, 'donation_id');
    }

    /** Paid through the checkout rather than transferred by hand. */
    public function isGatewaySettled(): bool
    {
        return $this->settlement === 'gateway';
    }
}
