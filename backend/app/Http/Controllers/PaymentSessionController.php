<?php

namespace App\Http\Controllers;

use App\Contracts\PaymentGateway;
use App\Exceptions\PaymentException;
use App\Models\PaymentSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * The checkout page's API. Four endpoints, one per step the donor takes on
 * /pay/{token}: read the session, authorise the account, confirm the OTP, or
 * back out.
 *
 * Note on auth: a real hosted gateway lives on the provider's domain and is
 * unauthenticated — the URL token is the only credential, and settlement comes
 * back to us over a signed webhook. Ours runs inside the same app, so these
 * routes sit behind auth:sanctum and additionally check that the session's
 * donation belongs to the caller. Strictly tighter than the real thing, and it
 * costs nothing here; it is the one place the simulation deliberately diverges.
 */
class PaymentSessionController extends Controller
{
    public function __construct(private readonly PaymentGateway $gateway)
    {
    }

    public function show(Request $request, string $token)
    {
        $session = $this->resolve($request, $token);

        // Expire lazily so the state a donor sees is always current, whether or not
        // the scheduler that runs payments:expire-sessions exists in this environment.
        if ($session->hasExpired()) {
            $session = $this->gateway->expire($session);
        }

        return response()->json(['session' => $this->present($session)]);
    }

    public function authorize(Request $request, string $token)
    {
        $session = $this->resolve($request, $token);

        $validator = Validator::make($request->all(), [
            'account' => ['required', 'string', 'max:64'],
            'pin'     => ['required', 'string', 'min:4', 'max:32'],
        ], [
            'account.required' => $session->rail === 'gcash'
                ? 'Enter your mobile number.'
                : 'Enter your account number.',
            'pin.required' => $session->rail === 'gcash'
                ? 'Enter your MPIN.'
                : 'Enter your online banking password.',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        return $this->run(fn () => $this->gateway->authorize($session, [
            'account' => $request->input('account'),
            'pin'     => $request->input('pin'),
        ]));
    }

    public function confirm(Request $request, string $token)
    {
        $session = $this->resolve($request, $token);

        $validator = Validator::make($request->all(), [
            'otp' => ['required', 'digits:6'],
        ], [
            'otp.digits' => 'The code is 6 digits.',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        return $this->run(fn () => $this->gateway->confirm($session, (string) $request->input('otp')));
    }

    public function cancel(Request $request, string $token)
    {
        $session = $this->resolve($request, $token);

        return $this->run(fn () => $this->gateway->cancel($session));
    }

    /**
     * Look the session up by its URL token and prove the caller owns the donation
     * behind it. 404 for an unknown token — the same answer a guessed one gets.
     */
    protected function resolve(Request $request, string $token): PaymentSession
    {
        $session = PaymentSession::with('donation')->where('token', $token)->first();

        abort_if($session === null || $session->donation === null, 404, 'Payment session not found.');
        abort_if($session->donation->user_id !== $request->user()->id, 403, 'Forbidden');

        return $session;
    }

    /** Run a gateway step, turning its state-machine refusals into HTTP answers. */
    protected function run(callable $step)
    {
        try {
            $session = $step();
        } catch (PaymentException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json(['session' => $this->present($session)]);
    }

    protected function present(PaymentSession $session): array
    {
        $donation = $session->donation;

        return [
            'token'         => $session->token,
            'rail'          => $session->rail,
            'amount'        => $session->amount,
            'status'        => $session->status,
            'failure_code'  => $session->failure_code,
            'attempts_left' => $session->attemptsLeft(),
            'expires_at'    => $session->expires_at,
            'donation'      => [
                'id'           => $donation->id,
                'reference_no' => $donation->reference_no,
                'status'       => $donation->status,
                'category'     => $donation->category,
            ],
        ];
    }
}
