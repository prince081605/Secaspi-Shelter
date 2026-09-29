<?php

namespace App\Http\Controllers;

use App\Models\PaymentSession;
use App\Services\PaymongoGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * PayMongo's server-to-server "this checkout was paid" call.
 *
 * Public and unauthenticated by nature — the Paymongo-Signature header, an HMAC over the
 * raw body made with the webhook's secret, is the credential. Anything it does not vouch
 * for is refused. Everything else is acknowledged with a 200 even when we ignore it, so
 * PayMongo does not keep retrying events we have no use for.
 */
class PaymongoWebhookController extends Controller
{
    public function __invoke(Request $request, PaymongoGateway $gateway)
    {
        if (! $gateway->verifySignature($request->header('Paymongo-Signature'), $request->getContent())) {
            Log::warning('PayMongo webhook: rejected a call with a missing or invalid signature', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $type = $request->input('data.attributes.type');
        $checkout = $request->input('data.attributes.data');

        if ($type !== 'checkout_session.payment.paid' || ! is_array($checkout)) {
            return response()->json(['received' => true]);
        }

        $session = PaymentSession::where('provider_ref', $checkout['id'] ?? null)->first();

        if (! $session) {
            Log::warning('PayMongo webhook: paid checkout session is not one of ours', ['provider_ref' => $checkout['id'] ?? null]);

            return response()->json(['received' => true]);
        }

        // The payload is signed, so it can be applied as-is without asking PayMongo again.
        $gateway->reconcile($session, $checkout);

        return response()->json(['received' => true]);
    }
}
