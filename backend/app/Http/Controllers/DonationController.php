<?php

namespace App\Http\Controllers;

use App\Contracts\PaymentGateway;
use App\Exceptions\PaymentException;
use App\Models\Donation;
use App\Notifications\DonationStatusChanged;
use App\Support\DonationCategories;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DonationController extends Controller
{
    public function __construct(private readonly PaymentGateway $gateway)
    {
    }

    public function store(Request $request)
    {
        $rails = config('payments.gateway_rails', []);

        $validator = Validator::make($request->all(), [
            'amount'         => ['required', 'numeric', 'min:1'],
            'payment_method' => ['required', 'in:gcash,cash,bank'],
            // How the donor intends to settle: pay through the checkout now, or send the
            // money themselves and upload a screenshot for staff to verify. Optional, and
            // absent means manual — the behaviour every caller had before checkout existed.
            'settlement'     => ['sometimes', 'in:gateway,manual'],
            'category'       => ['nullable', Rule::in(DonationCategories::keys())],
            // Proof is the manual path's substitute for a gateway confirmation, so it is
            // only ever required there — and only for GCash, as before.
            'proof_image'    => [
                Rule::requiredIf(fn () => $request->input('settlement', 'manual') === 'manual'
                    && $request->input('payment_method') === 'gcash'),
                'image',
                'max:5120',
            ],
            'is_anonymous'   => ['sometimes', 'boolean'],
        ]);

        $validator->after(function ($validator) use ($request, $rails) {
            if ($request->input('settlement') === 'gateway'
                && ! in_array($request->input('payment_method'), $rails, true)) {
                $validator->errors()->add('settlement', 'That payment method cannot be paid online.');
            }
        });

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $referenceNo = 'DON-' . strtoupper(Str::random(10));
        $settlement = $request->input('settlement', 'manual');
        $viaGateway = $settlement === 'gateway';

        try {
            $proofPath = $request->hasFile('proof_image')
                ? $request->file('proof_image')->store('donations')
                : null;

            // The donation and its checkout link are one unit of work: a donation stuck in
            // awaiting_payment with no session to pay it would be unreachable.
            [$donation, $session] = DB::transaction(function () use ($request, $user, $referenceNo, $settlement, $viaGateway, $proofPath) {
                $donation = Donation::create([
                    'user_id'        => $user->id,
                    'reference_no'   => $referenceNo,
                    'amount'         => $request->input('amount'),
                    'payment_method' => $request->input('payment_method'),
                    'settlement'     => $settlement,
                    'category'       => $request->input('category'),
                    'proof_image'    => $proofPath,
                    // Gateway gifts are not pending anyone's attention — they are waiting on
                    // the donor. Keeping them out of 'pending' keeps abandoned checkouts out
                    // of the admin queue and its badge counts.
                    'status'         => $viaGateway ? 'awaiting_payment' : 'pending',
                    // Default anonymous unless the donor explicitly opts in to be named.
                    'is_anonymous'   => $request->boolean('is_anonymous', true),
                ]);

                $session = $viaGateway
                    ? $this->gateway->createSession($donation, $donation->payment_method)
                    : null;

                return [$donation, $session];
            });
        } catch (\Throwable $e) {
            Log::error('Failed to record donation', [
                'user_id'        => $user->id,
                'reference_no'   => $referenceNo,
                'payment_method' => $request->input('payment_method'),
                'exception'      => $e,
            ]);

            return response()->json(['message' => 'Failed to record donation. Please try again.'], 500);
        }

        return response()->json(array_filter([
            'donation' => [
                'id'             => $donation->id,
                'reference_no'   => $donation->reference_no,
                'amount'         => $donation->amount,
                'payment_method' => $donation->payment_method,
                'settlement'     => $donation->settlement,
                'category'       => $donation->category,
                'status'         => $donation->status,
            ],
            // Present only on the gateway path; the frontend redirects when it sees it.
            'checkout_url'   => $session ? "/pay/{$session->token}" : null,
            'checkout_token' => $session?->token,
        ]), 201);
    }

    /**
     * Issue a fresh checkout link for a donation that was never paid — the donor
     * abandoned it, cancelled, or the payment was declined and they want another go.
     */
    public function checkout(Request $request, Donation $donation)
    {
        if ($donation->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (! in_array($donation->status, ['awaiting_payment', 'cancelled'], true)) {
            return response()->json(['message' => 'This donation is not awaiting payment.'], 409);
        }

        try {
            $session = $this->gateway->createSession($donation, $donation->payment_method);
        } catch (PaymentException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        // Reopening a cancelled donation puts it back in play.
        $donation->update(['status' => 'awaiting_payment']);

        return response()->json([
            'checkout_url'   => "/pay/{$session->token}",
            'checkout_token' => $session->token,
        ]);
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
            $donation->proof_image = Storage::url($donation->proof_image);
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
            $donation->proof_image = Storage::url($donation->proof_image);
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
            'settlement' => $d->settlement,
            'category' => $d->category,
            'proof_image' => $d->proof_image ? Storage::url($d->proof_image) : null,
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
                // Gateway donations that never settled. Counted separately so the status
                // tallies still add up to the number of rows in the table.
                'awaiting_payment' => (int) ($totals['awaiting_payment']->count ?? 0),
                'cancelled' => (int) ($totals['cancelled']->count ?? 0),
            ],
            'verified_total' => (float) ($totals['verified']->total ?? 0),
            'pending_total' => (float) ($totals['pending']->total ?? 0),
            'by_method' => $byMethod,
        ]);
    }
}
