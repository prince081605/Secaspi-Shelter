<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens;

    public $timestamps = false;

    // Only user-settable fields are mass-assignable. Privileged/system fields (role, status,
    // email_verified) are intentionally excluded so a stray update($request->all()) can never
    // escalate privileges; admin changes to role/status go through an explicit forceFill in
    // UserController::adminUpdate.
    protected $fillable = [
        'full_name',
        'email',
        'password',
        'phone',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }
}