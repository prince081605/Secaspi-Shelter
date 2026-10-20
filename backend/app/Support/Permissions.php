<?php

namespace App\Support;

/**
 * Who may do what — the single source of truth for authorization.
 *
 * There is no all-powerful admin. The admin side is split into three types, each limited to
 * its own area (the panel's recommendation, docs/ADMIN_ROLES.md):
 *   - Desk Admin   — day-to-day front-desk work: requests, records, helping people. Uses the
 *                    dropdowns but can't change them.
 *   - Data Admin   — the dropdowns and master data, bulk data loading, reports.
 *   - System Admin — users and roles, site settings, security.
 * Staff keep exactly the access they had before the split.
 *
 * Routes check an ability with `->middleware('perm:desk.operate')` (App\Http\Middleware\
 * EnsurePermission); code checks `$user->hasPermission('...')`. GET /api/user sends the
 * caller's abilities to the frontend, so the map is never duplicated there.
 */
final class Permissions
{
    public const USER = 'user';

    public const VOLUNTEER = 'volunteer';

    public const STAFF = 'staff';

    public const DESK_ADMIN = 'desk_admin';

    public const DATA_ADMIN = 'data_admin';

    public const SYSTEM_ADMIN = 'system_admin';

    /** Every role an account can have, with the name people see. */
    public const LABELS = [
        self::USER => 'User',
        self::VOLUNTEER => 'Volunteer',
        self::STAFF => 'Staff',
        self::DESK_ADMIN => 'Desk Admin',
        self::DATA_ADMIN => 'Data Admin',
        self::SYSTEM_ADMIN => 'System Admin',
    ];

    public const ADMIN_ROLES = [self::DESK_ADMIN, self::DATA_ADMIN, self::SYSTEM_ADMIN];

    /** Ability => the roles that hold it. */
    public const MAP = [
        // Day-to-day operations: animals, requests, rescues, visits, messages, donations and
        // expenses (read), personnel, teams, intakes, the operations dashboard.
        'desk.operate' => [self::STAFF, self::DESK_ADMIN],
        // The desk decisions staff don't make: verifying donations, writing the expense ledger,
        // correcting attendance, signing off tasks and hours, bulk photo updates.
        'desk.supervise' => [self::DESK_ADMIN],
        // Operational reports, their CSV/PDF exports, and the analytics charts.
        'reports.view' => [self::STAFF, self::DATA_ADMIN],
        // The donations and expenses reports.
        'reports.financial' => [self::DATA_ADMIN],
        // The dropdown lists (category_options), shelter areas, and animal import from Excel.
        'master_data.manage' => [self::DATA_ADMIN],
        // Accounts: listing users, changing roles, suspending and reactivating.
        'users.manage' => [self::SYSTEM_ADMIN],
        // Settings → Shelter info (branding, contact, page content, images, goals).
        'settings.manage' => [self::SYSTEM_ADMIN],
        // Blocking reporters who send fake rescue reports.
        'security.manage' => [self::SYSTEM_ADMIN],
    ];

    public static function rolesFor(string $ability): array
    {
        return self::MAP[$ability] ?? []; // an unknown ability fails closed
    }

    public static function allows(?string $role, string $ability): bool
    {
        return $role !== null && in_array($role, self::rolesFor($ability), true);
    }

    /** The abilities a role holds, in MAP order. */
    public static function forRole(?string $role): array
    {
        return array_keys(array_filter(self::MAP, fn (array $roles) => in_array($role, $roles, true)));
    }

    public static function label(?string $role): string
    {
        return self::LABELS[$role] ?? ucfirst((string) $role);
    }
}
