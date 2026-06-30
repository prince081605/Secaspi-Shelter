<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $fillable = [
        'user_id',
        'subject',
        'status',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    public function messages()
    {
        return $this->hasMany(Message::class)->orderBy('created_at');
    }

    /** The single most recent message — eager-loaded by the conversation lists to avoid an N+1. */
    public function latestMessage()
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
