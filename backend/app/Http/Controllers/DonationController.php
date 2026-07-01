<?php

namespace App\Http\Controllers;

use App\Models\Donation;
use App\Notifications\DonationStatusChanged;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class DonationController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount'         => ['required', 'numeric', 'min:1'],
            'payment_method' => ['required', 'in:gcash,cash,bank'],
            'proof_image'    => ['required_if:payment_method,gcash', 'image', 'max:5120'],
            'is_anonymous'   => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $referenceNo = 'DON-' . strtoupper(Str::random(10));

        try {
            $proofPath = $request->hasFile('proof_image')
                ? $request->file('proof_image')->store('donations', 'local')
                : null;

            $donation = Donation::create([
                'user_id'        => $user->id,
                'reference_no'   => $referenceNo,
                'amount'         => $request->input('amount'),
                'payment_method' => $request->input('payment_method'),
                'proof_image'    => $proofPath,
                'status'         => 'pending',
                // Default anonymous unless the donor explicitly opts in to be named.
                'is_anonymous'   => $request->boolean('is_anonymous', true),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to record donation', [
                'user_id'        => $user->id,
                'reference_no'   => $referenceNo,
                'payment_method' => $request->input('payment_method'),
                'exception'      => $e,
            ]);

            return response()->json(['message' => 'Failed to record donation. Please try again.'], 500);
        }

        return response()->json([
            'donation' => [
                'id'             => $donation->id,
                'reference_no'   => $donation->reference_no,
                'amount'         => $donation->amount,
                'payment_method' => $donation->payment_method,
                'status'         => $donation->status,
            ],
        ], 201);
    }

    public function index(Request $request)
    {
        $donations = Donation::where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->paginate(12);

        return response()->json($donations);
    }

    public function show(Request $request, Donation $donation)
    {
        if ($donation->user_id !== $request->user()->id && $request->user()->role !== 'admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($donation->proof_image) {
            $donation->proof_image = Storage::disk('local')->temporaryUrl($donation->proof_image, now()->addMinutes(30));
        }

        return response()->json(['donation' => $donation]);
    }

    public function verify(Request $request, Donation $donation)
    {
        $validator = Validator::make($request->all(), [
            'status' => ['required', 'in:verified,rejected'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        try {
            $donation->update(['status' => $request->input('status')]);
        } catch (\Throwable $e) {
            Log::error('Failed to update donation status', [
                'donation_id' => $donation->id,
                'status'      => $request->input('status'),
                'exception'   => $e,
            ]);

            return response()->json(['message' => 'Failed to update donation. Please try again.'], 500);
        }

        $donation->load('user');
        if ($donation->user) {
            (new DonationStatusChanged($donation))->sendTo($donation->user);
        }

        if ($donation->proof_image) {
            $donation->proof_image = Storage::disk('local')->temporaryUrl($donation->proof_image, now()->addMinutes(30));
        }

        return response()->json(['donation' => $donation]);
    }

    public function adminIndex(Request $request)
    {
        $query = Donation::query()->with('user');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $donations = $query->orderByDesc('id')->paginate(20)->withQueryString();

        $donations->getCollection()->transform(fn (Donation $d) => [
            'id' => $d->id,
            'reference_no' => $d->reference_no,
            'amount' => $d->amount,
            'payment_method' => $d->payment_method,
            'proof_image' => $d->proof_image ? Storage::disk('local')->temporaryUrl($d->proof_image, now()->addMinutes(30)) : null,
            'status' => $d->status,
            'donated_at' => $d->donated_at,
            'donor' => $d->user ? [
                'id' => $d->user->id,
                'full_name' => $d->user->full_name,
                'email' => $d->user->email,
            ] : null,
        ]);

        return response()->json($donations);
    }

    public function adminStats()
    {
        $totals = Donation::query()
            ->selectRaw("status, COUNT(*) as count, COALESCE(SUM(amount), 0) as total")
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $byMethod = Donation::query()
            ->where('status', 'verified')
            ->selectRaw('payment_method, COALESCE(SUM(amount), 0) as total')
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');

        return response()->json([
            'counts' => [
                'pending' => (int) ($totals['pending']->count ?? 0),
                'verified' => (int) ($totals['verified']->count ?? 0),
                'rejected' => (int) ($totals['rejected']->count ?? 0),
            ],
            'verified_total' => (float) ($totals['verified']->total ?? 0),
            'pending_total' => (float) ($totals['pending']->total ?? 0),
            'by_method' => $byMethod,
        ]);
    }
}
