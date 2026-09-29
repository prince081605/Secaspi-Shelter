<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Exceptions\PaymentException;
use App\Models\Donation;
use App\Models\PaymentSession;
use App\Services\Concerns\SettlesPaymentSessions;
use Illuminate\Support\Facades\DB;
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
    use SettlesPaymentSessions;

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
                'provider'    => 'simulated',
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

        return $this->close($session, 'cancelled', null);
    }

    public function expire(PaymentSession $session): PaymentSession
    {
        if (! in_array($session->status, PaymentSession::LIVE_STATUSES, true)) {
            return $session;
        }

        return $this->close($session, 'expired', 'expired');
    }

    /** This gateway is its own provider, so there is never anything newer to fetch. */
    public function sync(PaymentSession $session): PaymentSession
    {
        return $session;
    }
}
