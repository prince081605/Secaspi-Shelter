<?php

namespace App\Models;

use App\Support\DonationCategories;
use Illuminate\Database\Eloquent\Model;

/**
 * A real outlay by the shelter, categorised against the same keys donors give to.
 *
 * The category vocabulary is not redeclared here on purpose — DonationCategories is the single
 * source of truth for keys, labels and goals, and a second list would drift the first time a
 * category is added.
 */
class Expense extends Model
{
    protected $fillable = [
        'category',
        'amount',
        'description',
        'spent_at',
        'recorded_by',
        'receipt_path',
    ];

    protected $casts = [
        'amount'   => 'decimal:2',
        'spent_at' => 'date',
    ];

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** Human label for this expense's category, or the raw key if it is no longer a known one. */
    public function categoryLabel(): string
    {
        return DonationCategories::labels()[$this->category] ?? $this->category;
    }

    /**
     * Total spent per category over a date range, as `[categoryKey => amount]`.
     *
     * Every known category is present in the result even when nothing was spent against it, so
     * callers can pair it with the donation allocation without null-checking each key.
     *
     * @return array<string, float>
     */
    public static function totalsByCategory(?string $from = null, ?string $to = null): array
    {
        $query = static::query();

        if ($from !== null) {
            $query->whereDate('spent_at', '>=', $from);
        }
        if ($to !== null) {
            $query->whereDate('spent_at', '<=', $to);
        }

        $spent = $query->selectRaw('category, COALESCE(SUM(amount), 0) as total')
            ->groupBy('category')
            ->pluck('total', 'category');

        $totals = [];
        foreach (DonationCategories::keys() as $key) {
            $totals[$key] = (float) ($spent[$key] ?? 0);
        }

        return $totals;
    }
}
