<?php

namespace App\Contracts;

use App\Models\Donation;
use App\Models\PaymentSession;

/**
 * The checkout lifecycle, in the shape every hosted payment provider uses:
 * create a session, send the payer to it, authorise their instrument, confirm
 * the one-time code, settle. App\Services\SimulatedGateway is the only
 * implementation today (the shelter is not a registered merchant), but a real
 * provider slots in behind this interface without touching controllers.
 */
interface PaymentGateway
{
    /** Open a fresh payable session for a donation, voiding any earlier live one. */
    public function createSession(Donation $donation, string $rail): PaymentSession;

    /** Check the payer's account + PIN. Moves the session to awaiting_otp, or fails it. */
    public function authorize(PaymentSession $session, array $credentials): PaymentSession;

    /** Check the one-time code. On success the donation is settled. */
    public function confirm(PaymentSession $session, string $otp): PaymentSession;

    /** Donor backed out. Session and donation are both marked cancelled. */
    public function cancel(PaymentSession $session): PaymentSession;

    /** Sweep a session whose window has closed. Safe to call on any session. */
    public function expire(PaymentSession $session): PaymentSession;
}
