<?php
namespace App\Models;

use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
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

    /** The account for a login name: an email when it has an @, otherwise a username. Case doesn't matter. */
    public static function findForLogin(string $login): ?self
    {
        $login = mb_strtolower(trim($login));
        if ($login === '') {
            return null;
        }

        return static::whereRaw(str_contains($login, '@') ? 'LOWER(email) = ?' : 'LOWER(username) = ?', [$login])->first();
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
     * Whether this account's role holds $ability (see App\Support\Permissions). There is no
     * role hierarchy: each admin type is limited to its own area, so no role passes every check.
     */
    public function hasPermission(string $ability): bool
    {
        return Permissions::allows($this->role, $ability);
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return Permissions::forRole($this->role);
    }

    /** Desk, Data or System Admin. */
    public function isAdmin(): bool
    {
        return in_array($this->role, Permissions::ADMIN_ROLES, true);
    }

    public function roleLabel(): string
    {
        return Permissions::label($this->role);
    }

    /** Active accounts whose role holds $ability — e.g. who to notify about a new message. */
    public function scopeWithPermission(Builder $query, string $ability): Builder
    {
        return $query->whereIn('role', Permissions::rolesFor($ability))->where('status', 'active');
    }
}