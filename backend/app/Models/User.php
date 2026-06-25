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
        'username',
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

    /**
     * Build a unique, system-assigned username from a person's full name in the
     * "First L." style (first name + the surname's leading initial) — e.g.
     * "Juan Dela Cruz" -> "Juan D.". Single-word names get just the first name.
     * Collisions get a numeric suffix ("Juan D. 2", "Juan D. 3", …), so the result
     * is always unique against existing rows.
     */
    public static function generateUsername(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $first = $parts[0] ?? 'user';

        $base = ucfirst(mb_strtolower($first));
        if (isset($parts[1]) && $parts[1] !== '') {
            $base .= ' ' . mb_strtoupper(mb_substr($parts[1], 0, 1)) . '.';
        }

        $candidate = $base;
        $n = 1;
        while (static::where('username', $candidate)->exists()) {
            $n++;
            $candidate = $base . ' ' . $n;
        }

        return $candidate;
    }
}