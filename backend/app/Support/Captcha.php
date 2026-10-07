<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cloudflare Turnstile, the CAPTCHA on the public forms. The browser shows a "Verify you are
 * human" box that hands back a one-time token; the form sends it as `captcha_token`, and
 * App\Http\Middleware\VerifyHuman asks Cloudflare here whether it is genuine before the
 * request reaches the controller.
 *
 * Switched on by setting both TURNSTILE_SITE_KEY and TURNSTILE_SECRET_KEY; with either one
 * missing the check is off, which keeps local dev and the test suite working without keys.
 */
class Captcha
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    // Turnstile tokens are at most 2048 characters; anything longer isn't worth a round trip.
    private const MAX_TOKEN_LENGTH = 2048;

    public static function enabled(): bool
    {
        return filled(config('services.turnstile.site_key')) && filled(config('services.turnstile.secret_key'));
    }

    /** The public half of the key pair, which the browser needs to draw the box. Never the secret. */
    public static function siteKey(): ?string
    {
        return self::enabled() ? config('services.turnstile.site_key') : null;
    }

    /**
     * Whether Cloudflare confirms this token is a solved, unused challenge. Fails closed: if
     * Cloudflare can't be reached, the token is treated as not verified rather than waved through.
     */
    public static function verify(?string $token, ?string $ip): bool
    {
        if (! is_string($token) || $token === '' || strlen($token) > self::MAX_TOKEN_LENGTH) {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(8)->post(self::VERIFY_URL, array_filter([
                'secret' => config('services.turnstile.secret_key'),
                'response' => $token,
                'remoteip' => $ip,
            ]));

            if ($response->json('success') === true) {
                return true;
            }

            Log::info('Captcha: token rejected', ['errors' => $response->json('error-codes')]);

            return false;
        } catch (\Throwable $e) {
            Log::warning('Captcha: could not reach Cloudflare to verify a token', ['exception' => $e->getMessage()]);

            return false;
        }
    }
}
