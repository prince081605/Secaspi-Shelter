<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shared helpers for the public-facing analytics surfaces (home page + impact leaderboards).
 *
 * Centralizes two things that were previously copy-pasted between `ImpactController` and the
 * `routes/api_public.php` home/* closures (audit §0.2):
 *  - donor/volunteer name masking ("Juan D." — never the full legal name on a public page);
 *  - the "top verified, non-anonymous donors" query.
 */
class PublicStats
{
    /** "Juan D." — never the full legal name on public surfaces. */
    public static function maskName(?string $fullName): string
    {
        $parts = preg_split('/\s+/', trim((string) $fullName)) ?: [];
        if (empty($parts[0])) {
            return 'Anonymous';
        }
        $name = $parts[0];
        if (count($parts) > 1) {
            $name .= ' '.mb_substr(end($parts), 0, 1).'.';
        }

        return $name;
    }

    /**
     * Top verified, non-anonymous donors as `[{name, total}]`, names masked.
     * Shared by the impact leaderboard and the public home "impact" panel.
     *
     * @return Collection<int, array{name: string, total: float}>
     */
    public static function topDonors(int $limit = 5): Collection
    {
        return DB::table('donations')
            ->join('users', 'users.id', '=', 'donations.user_id')
            ->where('donations.status', 'verified')
            ->where('donations.is_anonymous', false)
            ->select('users.full_name', DB::raw('SUM(donations.amount) as total'))
            ->groupBy('users.id', 'users.full_name')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => ['name' => self::maskName($row->full_name), 'total' => (float) $row->total])
            ->values();
    }
}
