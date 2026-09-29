<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Exceptions\PaymentException;
use App\Models\Donation;
use App\Models\PaymentSession;
use App\Services\Concerns\SettlesPaymentSessions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * PayMongo hosted checkout (Checkout Sessions API).
 *
 * createSession() opens a checkout session on PayMongo and records its id and URL. The
 * donor pays on PayMongo's own page — the GCash login, card entry and OTP are all theirs,
 * which is why authorize() and confirm() have nothing to do here — and is then sent back
 * to /pay/{token}. We learn that the money arrived in one of two ways, whichever lands
 * first, and both end in the same locked settle():
 *
 *   - PayMongo calls /api/webhooks/paymongo with checkout_session.payment.paid, or
 *   - the return page polls /api/payments/{token}, and sync() asks PayMongo directly.
 *
 * The second path is not optional: a webhook cannot reach localhost, and a sleeping
 * Render instance can miss the first delivery. Neither path trusts the browser — landing
 * on the success URL proves nothing; only PayMongo's own answer settles a donation.
 */
class PaymongoGateway implements PaymentGateway
{
    use SettlesPaymentSessions;

    public function createSession(Donation $donation, string $rail): PaymentSession
    {
        $methods = config("payments.paymongo.methods.{$rail}");

        if (! in_array($rail, config('payments.gateway_rails', []), true) || empty($methods)) {
            throw new PaymentException("The {$rail} method cannot be paid online.", 422);
        }

        $min = (int) config('payments.paymongo.min_amount', 20);
        if ((float) $donation->amount < $min) {
            throw new PaymentException("Online payments must be at least ₱{$min}. Choose the manual option for smaller gifts.", 422);
        }

        $token = Str::random(48);
        $returnUrl = rtrim(config('app.frontend_url'), '/')."/pay/{$token}";

        // PayMongo first, our database second: if their API is down, nothing is left
        // half-made on our side.
        $checkout = $this->request('post', 'checkout_sessions', ['data' => ['attributes' => [
            'line_items' => [[
                'name'     => 'Donation to Second Chance Aspin Shelter',
                'amount'   => $this->centavos($donation->amount),
                'currency' => 'PHP',
                'quantity' => 1,
            ]],
            'payment_method_types' => array_values($methods),
            'description'          => "Donation {$donation->reference_no}",
            'reference_number'     => $donation->reference_no,
            'success_url'          => $returnUrl,
            'cancel_url'           => "{$returnUrl}?cancelled=1",
            'show_description'     => true,
            'show_line_items'      => true,
            'send_email_receipt'   => false,
            'metadata'             => [
                'donation_id'  => (string) $donation->id,
                'reference_no' => $donation->reference_no,
            ],
        ]]]);

        [$session, $superseded] = DB::transaction(function () use ($donation, $rail, $token, $checkout) {
            // Only one link may be payable at a time — same rule as the simulated gateway.
            $superseded = PaymentSession::where('donation_id', $donation->id)
                ->whereIn('status', PaymentSession::LIVE_STATUSES)
                ->get();

            PaymentSession::whereKey($superseded->modelKeys())
                ->update(['status' => 'cancelled', 'failure_code' => 'superseded']);

            $session = PaymentSession::create([
                'donation_id'  => $donation->id,
                'token'        => $token,
                'rail'         => $rail,
                'provider'     => 'paymongo',
                'provider_ref' => $checkout['id'],
                'checkout_url' => $checkout['attributes']['checkout_url'],
                'amount'       => $donation->amount,
                'status'       => 'open',
                'expires_at'   => now()->addMinutes((int) config('payments.session_ttl_minutes', 15)),
            ]);

            return [$session, $superseded];
        });

        // The old links must stop working on PayMongo's side too, or a donor with two tabs
        // open could pay the same donation twice.
        $superseded->each(fn (PaymentSession $old) => $this->expireRemote($old));

        return $session;
    }

    public function authorize(PaymentSession $session, array $credentials): PaymentSession
    {
        throw new PaymentException('This payment is completed on PayMongo’s checkout page.', 409);
    }

    public function confirm(PaymentSession $session, string $otp): PaymentSession
    {
        throw new PaymentException('This payment is completed on PayMongo’s checkout page.', 409);
    }

    public function cancel(PaymentSession $session): PaymentSession
    {
        // The donor may have paid and then pressed Back instead of waiting for the redirect.
        $session = $this->sync($session);

        if ($session->status === 'succeeded') {
            throw PaymentException::notPayable();
        }

        if (in_array($session->status, PaymentSession::LIVE_STATUSES, true)) {
            $this->expireRemote($session);
        }

        return $this->close($session, 'cancelled', null);
    }

    public function expire(PaymentSession $session): PaymentSession
    {
        if (! in_array($session->status, PaymentSession::LIVE_STATUSES, true)) {
            return $session;
        }

        // One last look first: a donor who paid at 14:59 must not be expired at 15:01.
        $session = $this->sync($session);

        if (! in_array($session->status, PaymentSession::LIVE_STATUSES, true)) {
            return $session;
        }

        $this->expireRemote($session);

        return $this->close($session, 'expired', 'expired');
    }

    public function sync(PaymentSession $session): PaymentSession
    {
        if (! $session->provider_ref || $session->status === 'succeeded') {
            return $session;
        }

        try {
            $checkout = $this->request('get', "checkout_sessions/{$session->provider_ref}");
        } catch (PaymentException) {
            // Already logged. The page polls again in a few seconds, and the webhook is
            // still coming — a PayMongo hiccup must not break the return page.
            return $session;
        }

        return $this->reconcile($session, $checkout);
    }

    /**
     * Apply PayMongo's view of a checkout session to ours: settle it if it has been paid.
     * Fed by sync() (fetched from the API) and by the webhook (signed payload).
     */
    public function reconcile(PaymentSession $session, array $checkout): PaymentSession
    {
        if ($session->status === 'succeeded' || ! $this->isPaid($checkout, $session)) {
            return $session;
        }

        $paidElsewhere = PaymentSession::where('donation_id', $session->donation_id)
            ->where('status', 'succeeded')
            ->whereKeyNot($session->id)
            ->exists();

        if ($paidElsewhere) {
            // Two links for one donation were both paid. The donation already counts once;
            // record this payment so our books match PayMongo's, and flag it for a refund.
            Log::error('PayMongo: donation paid twice — refund the duplicate from the PayMongo dashboard', [
                'donation_id'  => $session->donation_id,
                'session_id'   => $session->id,
                'provider_ref' => $session->provider_ref,
            ]);

            $session->update(['status' => 'succeeded', 'failure_code' => 'duplicate', 'completed_at' => now()]);

            return $session->refresh();
        }

        // Money PayMongo has taken is money received, even if our side had already closed
        // the link (expired, or superseded) by the time the donor finished paying.
        return $this->settle($session);
    }

    /** Check that a webhook call's Paymongo-Signature header was made with our secret. */
    public function verifySignature(?string $header, string $payload): bool
    {
        $secret = config('payments.paymongo.webhook_secret');

        if (! $secret || ! $header) {
            return false;
        }

        // "t=<unix time>,te=<test-mode sig>,li=<live-mode sig>" — exactly one of te/li is set.
        $parts = [];
        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            $parts[$key] = $value;
        }

        if (empty($parts['t'])) {
            return false;
        }

        $expected = hash_hmac('sha256', $parts['t'].'.'.$payload, $secret);

        foreach (['te', 'li'] as $field) {
            if (! empty($parts[$field]) && hash_equals($expected, $parts[$field])) {
                return true;
            }
        }

        return false;
    }

    /** Register $url with PayMongo for payment events. Returns the webhook, including its secret_key. */
    public function registerWebhook(string $url): array
    {
        return $this->request('post', 'webhooks', ['data' => ['attributes' => [
            'url'    => $url,
            'events' => ['checkout_session.payment.paid'],
        ]]]);
    }

    protected function isPaid(array $checkout, PaymentSession $session): bool
    {
        foreach ($checkout['attributes']['payments'] ?? [] as $payment) {
            $attributes = $payment['attributes'] ?? [];

            if (($attributes['status'] ?? null) === 'paid'
                && (int) ($attributes['amount'] ?? 0) === $this->centavos($session->amount)) {
                return true;
            }
        }

        return false;
    }

    /** Kill a checkout link on PayMongo's side. Best effort: a link paid anyway is still caught by reconcile(). */
    protected function expireRemote(PaymentSession $session): void
    {
        if (! $session->provider_ref) {
            return;
        }

        try {
            $this->request('post', "checkout_sessions/{$session->provider_ref}/expire");
        } catch (PaymentException) {
            // Already logged.
        }
    }

    /** PayMongo counts in centavos: ₱100.00 is 10000. */
    protected function centavos(string|float|int $pesos): int
    {
        return (int) round(((float) $pesos) * 100);
    }

    /** Call the PayMongo API and return its `data` member, or throw a donor-safe PaymentException. */
    protected function request(string $method, string $path, array $body = []): array
    {
        $key = config('payments.paymongo.secret_key');

        if (! $key) {
            Log::error('PayMongo: PAYMONGO_SECRET_KEY is not set');
            throw new PaymentException('Online payment is not available right now. Please use the manual option.', 503);
        }

        $url = rtrim(config('payments.paymongo.base_url'), '/')."/{$path}";

        try {
            // The secret key is the Basic-auth username, with an empty password.
            $client = Http::withBasicAuth($key, '')->acceptJson()->timeout(15);
            $response = $method === 'get' ? $client->get($url) : $client->post($url, $body);
        } catch (\Throwable $e) {
            Log::error('PayMongo: request failed', ['path' => $path, 'exception' => $e]);
            throw new PaymentException('We could not reach the payment provider. Please try again in a moment.', 502);
        }

        if ($response->failed()) {
            Log::error('PayMongo: request rejected', [
                'path'   => $path,
                'status' => $response->status(),
                'errors' => $response->json('errors'),
            ]);
            throw new PaymentException('The payment provider could not process this request. Please try again or use the manual option.', 502);
        }

        return $response->json('data') ?? [];
    }
}
