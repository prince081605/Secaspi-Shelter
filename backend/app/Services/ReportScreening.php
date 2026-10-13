<?php

namespace App\Services;

use App\Models\BlockedReporter;
use App\Models\RescueReport;
use App\Models\Setting;

/**
 * Keeps rescue reports open to anyone while handling people who send many or fake ones:
 * recognising a reporter again (by contact number, account or connection), a daily limit,
 * blocking, and spotting a report about the same place that's already open.
 *
 * Contact numbers and IPs are only kept as keyed hashes: enough to match a reporter again,
 * not enough to read the number or address back.
 */
class ReportScreening
{
    /** Reports one contact number or account may send in a day. */
    public const DAILY_LIMIT = 3;

    /**
     * Reports one connection may send in a day. Higher than DAILY_LIMIT because mobile networks
     * put many people behind one address, and a real emergency must not be refused for that.
     */
    public const DAILY_LIMIT_PER_CONNECTION = 10;

    /** An open report this close (metres), sent this recently, is probably about the same animal. */
    public const DUPLICATE_RADIUS_M = 200;

    public const DUPLICATE_WINDOW_HOURS = 24;

    public const INVALID_REASONS = [
        'fake' => 'Fake report',
        'duplicate' => 'Duplicate',
        'not_found' => 'Animal not found',
    ];

    private const OPEN_STATUSES = ['pending', 'assigned', 'in_progress'];

    /** "09171234567", "0917 123 4567" and "+63 917 123 4567" all hash the same. */
    public static function contactHash(?string $contact): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $contact);
        if (str_starts_with($digits, '63')) {
            $digits = '0'.substr($digits, 2);
        }

        return $digits === '' ? null : self::hash('contact:'.$digits);
    }

    public static function ipHash(?string $ip): ?string
    {
        return $ip ? self::hash('ip:'.$ip) : null;
    }

    /** "0917 •••• 567", for the blocked list. */
    public static function maskContact(?string $contact): string
    {
        $digits = preg_replace('/\D/', '', (string) $contact);
        if (strlen($digits) < 7) {
            return 'Hidden number';
        }

        return substr($digits, 0, 4).' •••• '.substr($digits, -3);
    }

    public static function blockFor(?string $contactHash, ?int $userId): ?BlockedReporter
    {
        if (! $contactHash && ! $userId) {
            return null;
        }

        return BlockedReporter::query()
            ->where(function ($q) use ($contactHash, $userId) {
                if ($contactHash) {
                    $q->orWhere('contact_hash', $contactHash);
                }
                if ($userId) {
                    $q->orWhere('user_id', $userId);
                }
            })
            ->first();
    }

    /**
     * Why a new report from this reporter can't be taken today, or null when it can. Counts every
     * report they sent in the last 24 hours, whatever happened to it.
     */
    public static function overDailyLimit(?string $contactHash, ?int $userId, ?string $ipHash): ?string
    {
        $since = now()->subDay();
        $sent = fn (string $column, $value) => RescueReport::where($column, $value)->where('created_at', '>=', $since)->count();

        if (($contactHash && $sent('contact_hash', $contactHash) >= self::DAILY_LIMIT)
            || ($userId && $sent('user_id', $userId) >= self::DAILY_LIMIT)) {
            return 'You have already sent '.self::DAILY_LIMIT.' rescue reports today.';
        }
        if ($ipHash && $sent('ip_hash', $ipHash) >= self::DAILY_LIMIT_PER_CONNECTION) {
            return 'Too many rescue reports have been sent from this connection today.';
        }

        return null;
    }

    /** "Please call the shelter at 0917 123 4567." — the way out when a report is refused. */
    public static function callTheShelter(): string
    {
        $phone = trim((string) (Setting::getAll()['contact_phone'] ?? ''));

        return $phone !== '' ? "If an animal needs help now, please call the shelter at {$phone}." : 'If an animal needs help now, please call the shelter.';
    }

    /**
     * An open report from the last day about the same spot: within DUPLICATE_RADIUS_M when both
     * have a map pin, otherwise the same typed location.
     */
    public static function findPossibleDuplicate(?float $lat, ?float $lng, ?string $location): ?RescueReport
    {
        $recent = RescueReport::query()
            ->whereIn('status', self::OPEN_STATUSES)
            ->where('created_at', '>=', now()->subHours(self::DUPLICATE_WINDOW_HOURS))
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'location', 'latitude', 'longitude', 'status', 'created_at']);

        $place = self::normalisePlace($location);
        foreach ($recent as $report) {
            if ($lat !== null && $lng !== null && $report->latitude !== null && $report->longitude !== null) {
                if (self::distanceMetres($lat, $lng, $report->latitude, $report->longitude) <= self::DUPLICATE_RADIUS_M) {
                    return $report;
                }
            } elseif ($place !== '' && self::normalisePlace($report->location) === $place) {
                return $report;
            }
        }

        return null;
    }

    /**
     * What staff should know about whoever sent this report: how many reports came before from
     * the same contact number, account or (with neither) connection, and how many of those staff
     * closed as invalid or fake.
     *
     * @return array{basis: ?string, earlier: int, invalid: int, fake: int}
     */
    public static function history(RescueReport $report): array
    {
        [$column, $value, $basis] = match (true) {
            (bool) $report->contact_hash => ['contact_hash', $report->contact_hash, 'contact'],
            (bool) $report->user_id => ['user_id', $report->user_id, 'account'],
            (bool) $report->ip_hash => ['ip_hash', $report->ip_hash, 'connection'],
            default => [null, null, null],
        };

        if (! $column) {
            return ['basis' => null, 'earlier' => 0, 'invalid' => 0, 'fake' => 0];
        }

        $earlier = RescueReport::where($column, $value)->whereKeyNot($report->id);

        return [
            'basis' => $basis,
            'earlier' => (clone $earlier)->count(),
            'invalid' => (clone $earlier)->where('status', 'invalid')->count(),
            'fake' => (clone $earlier)->where('status', 'invalid')->where('invalid_reason', 'fake')->count(),
        ];
    }

    private static function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private static function normalisePlace(?string $location): string
    {
        return trim((string) preg_replace('/[\s,.]+/u', ' ', mb_strtolower((string) $location)));
    }

    private static function distanceMetres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
