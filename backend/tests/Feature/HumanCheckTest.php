<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Anti-spam on the public forms (login, register, forgot password, rescue report): a honeypot
 * field that's always checked, and a Cloudflare Turnstile CAPTCHA that's checked once both keys
 * are configured. Cloudflare is never really called here — every request to it is faked.
 */
class HumanCheckTest extends TestCase
{
    use RefreshDatabase;

    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    private const CAPTCHA_MESSAGE = 'Please complete the "Verify you are human" check and try again.';

    private function turnOnCaptcha(): void
    {
        config(['services.turnstile.site_key' => 'site-key-123', 'services.turnstile.secret_key' => 'secret-key-456']);
    }

    private function registration(array $extra = []): array
    {
        return [
            'name' => 'Jane Doe', 'email' => 'jane@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
            ...$extra,
        ];
    }

    private function rescueReport(array $extra = []): array
    {
        return ['location' => 'Purok 3, Brgy. San Isidro', 'urgency' => 'high', ...$extra];
    }

    public function test_the_captcha_is_off_until_both_keys_are_set(): void
    {
        Http::fake();

        $this->getJson('/api/captcha')->assertOk()->assertExactJson(['site_key' => null]);
        $this->postJson('/api/register', $this->registration())->assertCreated();

        config(['services.turnstile.site_key' => 'site-key-123']); // the secret is still missing
        $this->getJson('/api/captcha')->assertExactJson(['site_key' => null]);
        $this->postJson('/api/rescue-reports', $this->rescueReport())->assertCreated();

        Http::assertNothingSent();
    }

    public function test_the_site_key_is_published_but_never_the_secret(): void
    {
        $this->turnOnCaptcha();

        $response = $this->getJson('/api/captcha')->assertOk()->assertExactJson(['site_key' => 'site-key-123']);
        $this->assertStringNotContainsString('secret-key-456', $response->getContent());
    }

    public function test_a_token_cloudflare_confirms_lets_the_form_through(): void
    {
        $this->turnOnCaptcha();
        Http::fake([self::VERIFY_URL => Http::response(['success' => true])]);

        $this->postJson('/api/register', $this->registration(['captcha_token' => 'good-token']))->assertCreated();
        $this->postJson('/api/rescue-reports', $this->rescueReport(['captcha_token' => 'another-good-token']))->assertCreated();

        Http::assertSent(fn ($request) => $request->url() === self::VERIFY_URL
            && $request['secret'] === 'secret-key-456'
            && $request['response'] === 'good-token'
            && $request['remoteip'] === '127.0.0.1');
        Http::assertSentCount(2);
    }

    public function test_a_missing_or_rejected_token_is_turned_away(): void
    {
        $this->turnOnCaptcha();
        Http::fake([self::VERIFY_URL => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);

        // No token at all: refused without asking Cloudflare.
        $this->postJson('/api/register', $this->registration())
            ->assertStatus(422)->assertJsonPath('errors.captcha_token.0', self::CAPTCHA_MESSAGE);
        Http::assertNothingSent();

        // A token Cloudflare doesn't recognise (forged, expired, or already used).
        $this->postJson('/api/register', $this->registration(['captcha_token' => 'forged']))
            ->assertStatus(422)->assertJsonPath('message', self::CAPTCHA_MESSAGE);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_it_fails_closed_when_cloudflare_cannot_be_reached(): void
    {
        $this->turnOnCaptcha();
        Http::fake([self::VERIFY_URL => Http::failedConnection()]);

        $this->postJson('/api/register', $this->registration(['captcha_token' => 'good-token']))
            ->assertStatus(422)->assertJsonPath('message', self::CAPTCHA_MESSAGE);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_login_forgot_password_and_rescue_reports_are_all_guarded(): void
    {
        $this->turnOnCaptcha();
        Http::fake();

        $this->postJson('/api/login', ['email' => 'jane@example.com', 'password' => 'password123'])
            ->assertStatus(422)->assertJsonValidationErrors('captcha_token');
        $this->postJson('/api/forgot-password', ['email' => 'jane@example.com'])
            ->assertStatus(422)->assertJsonValidationErrors('captcha_token');
        $this->postJson('/api/rescue-reports', $this->rescueReport())
            ->assertStatus(422)->assertJsonValidationErrors('captcha_token');

        $this->assertDatabaseCount('rescue_reports', 0);
    }

    public function test_a_filled_honeypot_is_refused_even_with_the_captcha_off(): void
    {
        $refused = 'Your submission could not be accepted. Please try again.';

        $this->postJson('/api/register', $this->registration(['company_website' => 'https://cheap-pills.example']))
            ->assertStatus(422)->assertJsonPath('message', $refused);
        $this->postJson('/api/rescue-reports', $this->rescueReport(['company_website' => 'spam']))
            ->assertStatus(422)->assertJsonPath('message', $refused);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('rescue_reports', 0);

        // Real visitors send the hidden field empty.
        $this->postJson('/api/rescue-reports', $this->rescueReport(['company_website' => '']))->assertCreated();
    }
}
