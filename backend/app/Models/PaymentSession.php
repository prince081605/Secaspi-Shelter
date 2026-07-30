<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentSession extends Model
{
    /** Statuses a donor can still act on — anything else is a finished session. */
    public const LIVE_STATUSES = ['open', 'awaiting_otp'];

    protected $fillable = [
        'donation_id',
        'token',
        'rail',
        'amount',
        'status',
        'failure_code',
        'attempts',
        'expires_at',
        'completed_at',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'attempts'     => 'integer',
        'expires_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function donation()
    {
        return $this->belongsTo(Donation::class, 'donation_id');
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE_STATUSES, true) && ! $this->hasExpired();
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function attemptsLeft(): int
    {
        return max(0, (int) config('payments.max_otp_attempts', 3) - $this->attempts);
    }
}
