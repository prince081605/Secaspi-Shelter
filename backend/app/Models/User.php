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

    /**
     * Whether this account is currently suspended (blocked from logging in or
     * using the API).
     */
    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /**
     * Human-readable explanation shown to a suspended user, including the admin's
     * reason when one was recorded. Shared by login() and the EnsureActive middleware
     * so the wording stays identical everywhere.
     */
    public function suspensionMessage(): string
    {
        $reason = trim((string) $this->suspension_reason);

        return $reason !== ''
            ? "Your account has been suspended. Reason: {$reason}"
            : 'Your account has been suspended. Please contact the shelter administrator.';
    }

    /**
     * Role hierarchy, lowest to highest. Access is granted by *minimum* rank, so a
     * higher role automatically clears every gate a lower role can (admin passes all).
     */
    public const ROLE_RANKS = ['user' => 1, 'volunteer' => 2, 'staff' => 3, 'admin' => 4];

    public function roleRank(): int
    {
        return self::ROLE_RANKS[$this->role] ?? 0; // unknown/null role ranks below everything
    }

    /**
     * True if this user's role is at least $role in the hierarchy. An unknown
     * threshold fails closed (no one clears it) rather than open.
     */
    public function hasRoleAtLeast(string $role): bool
    {
        return $this->roleRank() >= (self::ROLE_RANKS[$role] ?? PHP_INT_MAX);
    }
}