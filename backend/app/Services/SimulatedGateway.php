<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Exceptions\PaymentException;
use App\Models\Donation;
use App\Models\PaymentSession;
use App\Notifications\DonationStatusChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A payment gateway that moves no money.
 *
 * Every state transition a real provider performs is reproduced here — session
 * creation, instrument authorisation, an OTP challenge, settlement, expiry —
 * against our own database. What it does not do is talk to a bank, because the
 * shelter has no merchant account to talk with one through.
 *
 * The outcome of a payment is decided by config('payments.triggers'): a handful
 * of reserved account numbers force a decline so the failure path can be shown
 * on demand. Everything else succeeds.
 */
class SimulatedGateway implements PaymentGateway
{
    public function createSession(Donation $donation, string $rail): PaymentSession
    {
        if (! in_array($rail, config('payments.gateway_rails', []), true)) {
            throw new PaymentException("The {$rail} method cannot be paid online.", 422);
        }

        return DB::transaction(function () use ($donation, $rail) {
            // Only one link may be payable at a time, otherwise a donor who clicks
            // "Try again" twice ends up with two sessions that could both settle.
            PaymentSession::where('donation_id', $donation->id)
                ->whereIn('status', PaymentSession::LIVE_STATUSES)
                ->update(['status' => 'cancelled', 'failure_code' => 'superseded']);

            return PaymentSession::create([
                'donation_id' => $donation->id,
                'token'       => Str::random(48),
                'rail'        => $rail,
                'amount'      => $donation->amount,
                'status'      => 'open',
                'expires_at'  => now()->addMinutes((int) config('payments.session_ttl_minutes', 15)),
            ]);
        });
    }

    public function authorize(PaymentSession $session, array $credentials): PaymentSession
    {
        $this->assertPayable($session);

        if ($session->status !== 'open') {
            throw PaymentException::wrongStep();
        }

        // Digits only, so 0900-000-0001 and 09000000001 are the same account.
        $account = preg_replace('/\D+/', '', (string) ($credentials['account'] ?? ''));
        $trigger = config('payments.triggers')[$account] ?? null;

        if ($trigger) {
            return $this->fail($session, $trigger);
        }

        $session->update(['status' => 'awaiting_otp']);

        return $session->refresh();
    }

    public function confirm(PaymentSession $session, string $otp): PaymentSession
    {
        $this->assertPayable($session);

        if ($session->status !== 'awaiting_otp') {
            throw PaymentException::wrongStep();
        }

        if (! hash_equals((string) config('payments.otp'), $otp)) {
            $session->increment('attempts');
            $session->refresh();

            return $session->attemptsLeft() < 1
                ? $this->fail($session, 'invalid_otp')
                : $session;
        }

        return $this->settle($session);
    }

    public function cancel(PaymentSession $session): PaymentSession
    {
        if ($session->status === 'succeeded') {
            throw PaymentException::notPayable();
        }

        DB::transaction(function () use ($session) {
            $session->update(['status' => 'cancelled', 'failure_code' => null]);
            // The donation is kept, not deleted: the donor can resume it from their
            // history, and a cancelled row is honest history rather than a gap.
            $session->donation()->update(['status' => 'cancelled']);
        });

        return $session->refresh();
    }

    public function expire(PaymentSession $session): PaymentSession
    {
        if (! in_array($session->status, PaymentSession::LIVE_STATUSES, true)) {
            return $session;
        }

        DB::transaction(function () use ($session) {
            $session->update(['status' => 'expired', 'failure_code' => 'expired']);
            $session->donation()->update(['status' => 'cancelled']);
        });

        return $session->refresh();
    }

    /**
     * The only place a donation becomes money in the bank.
     *
     * Locked and guarded: a donor who double-taps Confirm, or refreshes the tab
     * mid-request, must not settle twice or fire two "donation verified"
     * notifications. The lock also means the notification is sent exactly once,
     * outside the transaction, only by the request that actually did the work.
     */
    protected function settle(PaymentSession $session): PaymentSession
    {
        $justSettled = DB::transaction(function () use ($session) {
            $locked = PaymentSession::whereKey($session->id)->lockForUpdate()->first();

            if ($locked->status === 'succeeded') {
                return false;
            }

            $locked->update([
                'status'       => 'succeeded',
                'failure_code' => null,
                'completed_at' => now(),
            ]);

            $locked->donation()->update([
                'status'     => 'verified',
                'settlement' => 'gateway',
                // Dated at settlement, not at form-fill: this is when the shelter
                // actually received it, which is what the monthly totals measure.
                'donated_at' => now(),
            ]);

            return true;
        });

        $session->refresh();

        if ($justSettled) {
            $donation = $session->donation()->with('user')->first();
            if ($donation?->user) {
                try {
                    (new DonationStatusChanged($donation))->sendTo($donation->user);
                } catch (\Throwable $e) {
                    // The payment is already committed. Notifications send synchronously
                    // (see AppNotification), so an SMTP outage would otherwise surface to
                    // the donor as a failed payment for money we have taken — log it and
                    // let the receipt speak for itself.
                    Log::error('Donation settled but the confirmation notification failed', [
                        'donation_id' => $donation->id,
                        'session_id'  => $session->id,
                        'exception'   => $e,
                    ]);
                }
            }
        }

        return $session;
    }

    protected function fail(PaymentSession $session, string $code): PaymentSession
    {
        $session->update(['status' => 'failed', 'failure_code' => $code]);

        // The donation stays awaiting_payment on purpose — a declined card is not a
        // cancelled gift, and the donor should be able to retry with another account.

        return $session->refresh();
    }

    protected function assertPayable(PaymentSession $session): void
    {
        if ($session->hasExpired()) {
            $this->expire($session);
            throw PaymentException::expired();
        }

        if (! in_array($session->status, PaymentSession::LIVE_STATUSES, true)) {
            throw PaymentException::notPayable();
        }
    }
}
