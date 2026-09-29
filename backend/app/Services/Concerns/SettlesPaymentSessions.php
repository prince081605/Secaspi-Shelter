<?php

namespace App\Services\Concerns;

use App\Exceptions\PaymentException;
use App\Models\PaymentSession;
use App\Notifications\DonationStatusChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The half of a gateway that is about our own books rather than the provider: settling a
 * donation, failing or closing a session. Shared so the simulated and PayMongo gateways
 * cannot drift apart on the one thing that must behave identically — a donation settles
 * exactly once, however its confirmation arrives.
 */
trait SettlesPaymentSessions
{
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

    /**
     * End a session the donor never paid, and release its donation.
     *
     * The donation is kept, not deleted: the donor can resume it from their history,
     * and a cancelled row is honest history rather than a gap.
     */
    protected function close(PaymentSession $session, string $status, ?string $failureCode): PaymentSession
    {
        DB::transaction(function () use ($session, $status, $failureCode) {
            $session->update(['status' => $status, 'failure_code' => $failureCode]);
            $session->donation()->update(['status' => 'cancelled']);
        });

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
