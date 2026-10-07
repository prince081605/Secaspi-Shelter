<?php

namespace App\Http\Middleware;

use App\Support\Captcha;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Anti-spam gate for the public forms (->middleware('human')), run after their rate limit:
 *
 *  1. Honeypot — the forms carry a field people never see. Bots that fill in every field fill
 *     that one too, and are turned away without being told why.
 *  2. CAPTCHA — when Turnstile is configured (App\Support\Captcha), the request must carry a
 *     token Cloudflare confirms. Tokens are single-use, so the form gets a fresh one per attempt.
 */
class VerifyHuman
{
    /** The hidden honeypot field's name. Mirrored in the frontend's HumanCheck component. */
    public const HONEYPOT = 'company_website';

    public function handle(Request $request, Closure $next): Response
    {
        if (filled($request->input(self::HONEYPOT))) {
            return response()->json(['message' => 'Your submission could not be accepted. Please try again.'], 422);
        }

        if (Captcha::enabled() && ! Captcha::verify($request->input('captcha_token'), $request->ip())) {
            $message = 'Please complete the "Verify you are human" check and try again.';

            return response()->json(['message' => $message, 'errors' => ['captcha_token' => [$message]]], 422);
        }

        return $next($request);
    }
}
