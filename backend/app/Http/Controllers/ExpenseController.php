<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Support\DonationCategories;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The shelter's expense ledger.
 *
 * Gating mirrors donations: staff can *see* the money (adminIndex, adminStats) because they need
 * it to do their jobs, but only an admin may write to the ledger — the same line
 * DonationController draws at verify() and ReportController draws at the financial report.
 */
class ExpenseController extends Controller
{
    public function adminIndex(Request $request)
    {
        $query = Expense::query()->with('recorder');

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }
        if ($from = $request->query('from')) {
            $query->whereDate('spent_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('spent_at', '<=', $to);
        }

        // Most recent outlay first, id as the tiebreak so same-day rows have a stable order
        // across pages (spent_at alone is a date, so a day's rows would otherwise shuffle).
        $expenses = $query->orderByDesc('spent_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $expenses->getCollection()->transform(fn (Expense $e) => $this->present($e));

        return response()->json($expenses);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), $this->rules());

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        try {
            $receiptPath = $request->hasFile('receipt')
                ? $request->file('receipt')->store('expenses')
                : null;

            $expense = Expense::create([
                'category'     => $request->input('category'),
                'amount'       => $request->input('amount'),
                'description'  => $request->input('description'),
                'spent_at'     => $request->input('spent_at'),
                'receipt_path' => $receiptPath,
                'recorded_by'  => $request->user()->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to record expense', [
                'user_id'   => $request->user()->id,
                'category'  => $request->input('category'),
                'exception' => $e,
            ]);

            return response()->json(['message' => 'Failed to record expense. Please try again.'], 500);
        }

        return response()->json(['expense' => $this->present($expense->load('recorder'))], 201);
    }

    public function update(Request $request, Expense $expense)
    {
        $validator = Validator::make($request->all(), $this->rules());

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        try {
            $attributes = [
                'category'    => $request->input('category'),
                'amount'      => $request->input('amount'),
                'description' => $request->input('description'),
                'spent_at'    => $request->input('spent_at'),
            ];

            // A new receipt replaces the old one; the old file goes with it rather than
            // lingering on the disk with nothing pointing at it.
            if ($request->hasFile('receipt')) {
                $previous = $expense->receipt_path;
                $attributes['receipt_path'] = $request->file('receipt')->store('expenses');

                if ($previous) {
                    Storage::delete($previous);
                }
            }

            $expense->update($attributes);
        } catch (\Throwable $e) {
            Log::error('Failed to update expense', [
                'expense_id' => $expense->id,
                'exception'  => $e,
            ]);

            return response()->json(['message' => 'Failed to update expense. Please try again.'], 500);
        }

        return response()->json(['expense' => $this->present($expense->load('recorder'))]);
    }

    public function destroy(Expense $expense)
    {
        if ($expense->receipt_path) {
            Storage::delete($expense->receipt_path);
        }

        $expense->delete();

        return response()->json(['message' => 'Expense deleted']);
    }

    /**
     * Ledger totals for the strip above the table: overall, this month, and a per-category
     * breakdown. One request rather than one per tile — the same reason the dashboard's seven
     * pending-count polls were collapsed into a single endpoint.
     */
    public function adminStats(Request $request)
    {
        $from = $request->query('from');
        $to = $request->query('to');

        $scoped = Expense::query();
        if ($from) {
            $scoped->whereDate('spent_at', '>=', $from);
        }
        if ($to) {
            $scoped->whereDate('spent_at', '<=', $to);
        }

        $byCategory = Expense::totalsByCategory($from, $to);
        $labels = DonationCategories::labels();

        return response()->json([
            'total'      => (float) $scoped->sum('amount'),
            'count'      => (int) $scoped->count(),
            'this_month' => (float) Expense::query()
                ->whereDate('spent_at', '>=', now()->startOfMonth()->toDateString())
                ->sum('amount'),
            'by_category' => array_map(fn ($key) => [
                'key'   => $key,
                'label' => $labels[$key],
                'total' => $byCategory[$key],
            ], DonationCategories::keys()),
        ]);
    }

    /** @return array<string, mixed> Shared create/update validation. */
    private function rules(): array
    {
        return [
            'category'    => ['required', Rule::in(DonationCategories::keys())],
            'amount'      => ['required', 'numeric', 'min:0.01'],
            'description' => ['required', 'string', 'max:255'],
            'spent_at'    => ['required', 'date'],
            'receipt'     => ['nullable', 'image', 'max:5120'],
        ];
    }

    /** @return array<string, mixed> */
    private function present(Expense $expense): array
    {
        return [
            'id'             => $expense->id,
            'category'       => $expense->category,
            'category_label' => $expense->categoryLabel(),
            'amount'         => (float) $expense->amount,
            'description'    => $expense->description,
            'spent_at'       => $expense->spent_at?->toDateString(),
            'receipt_url'    => $expense->receipt_path ? Storage::url($expense->receipt_path) : null,
            'recorded_by'    => $expense->recorder?->full_name,
        ];
    }
}
