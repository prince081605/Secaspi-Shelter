<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A contact number (stored only as a hash) or an account whose rescue reports are refused, after
 * an admin found them sending fake reports. See App\Services\ReportScreening.
 */
class BlockedReporter extends Model
{
    protected $fillable = ['contact_hash', 'user_id', 'label', 'reason', 'report_id', 'blocked_by'];

    public function blocker()
    {
        return $this->belongsTo(User::class, 'blocked_by');
    }
}
