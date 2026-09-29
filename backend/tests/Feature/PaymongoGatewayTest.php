<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\PaymentSession;
use App\Models\User;
use App\Notifications\DonationStatusChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers App\Services\PaymongoGateway with PayMongo's API faked. What matters: a donation
 * settles only on PayMongo's word (a signed webhook, or PayMongo's own API when the donor
 * returns) — never on the browser reaching the success URL — and settles exactly once
 * whichever of the two arrives first.
 */
class PaymongoGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsk_test_secret';

    /** What the fake PayMongo reports for any checkout session it is asked about. */
    private array $remote = ['payments' => []];

    private int $created = 0;

    private bool $paymongoDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payments.driver'                  => 'paymongo',
            'payments.paymongo.secret_key'     => 'sk_test_fake',
            'payments.paymongo.webhook_secret' => self::WEBHOOK_SECRET,
            'app.frontend_url'                 => 'https://shelter.test',
        ]);

        Http::fake(function (Request $request) {
            if ($this->paymongoDown) {
                return Http::response(['errors' => [['detail' => 'Internal error']]], 500);
            }

            $url = $request->url();

            if ($request->method() === 'POST' && str_ends_with($url, '/checkout_sessions')) {
                $id = 'cs_test_'.(++$this->created);

                return Http::response(['data' => [
                    'id'         => $id,
                    'attributes' => ['checkout_url' => "https://checkout.paymongo.com/{$id}"],
                ]]);
            }

            if (str_ends_with($url, '/expire')) {
                return Http::response(['data' => []]);
            }

            return Http::response(['data' => ['id' => basename($url), 'attributes' => $this->remote]]);
        });
    }

    private function startDonation(User $donor, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($donor);

        return $this->postJson('/api/donations', array_merge([
            'amount'         => 500,
            'payment_method' => 'gcash',
            'settlement'     => 'gateway',
        ], $overrides));
    }

    private function paidRemotely(int $centavos = 50000): void
    {
        $this->remote = ['payments' => [['attributes' => ['status' => 'paid', 'amount' => $centavos]]]];
    }

    private function webhook(string $checkoutId, ?string $secret = self::WEBHOOK_SECRET): \Illuminate\Testing\TestResponse
    {
        $body = json_encode(['data' => ['attributes' => [
            'type' => 'checkout_session.payment.paid',
            'data' => [
                'id'         => $checkoutId,
                'attributes' => ['payments' => [['attributes' => ['status' => 'paid', 'amount' => 50000]]]],
            ],
        ]]]);

        $t = (string) time();
        $signature = "t={$t},te=".hash_hmac('sha256', "{$t}.{$body}", $secret).',li=';

        return $this->call('POST', '/api/webhooks/paymongo', [], [], [], [
            'CONTENT_TYPE'            => 'application/json',
            'HTTP_ACCEPT'             => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => $signature,
        ], $body);
    }

    public function test_a_gateway_donation_opens_a_paymongo_checkout(): void
    {
        $res = $this->startDonation(User::factory()->create())
            ->assertCreated()
            ->assertJsonPath('checkout_url', 'https://checkout.paymongo.com/cs_test_1');

        $session = PaymentSession::firstWhere('token', $res->json('checkout_token'));
        $this->assertSame('paymongo', $session->provider);
        $this->assertSame('cs_test_1', $session->provider_ref);
        $this->assertSame('awaiting_payment', Donation::find($res->json('donation.id'))->status);

        Http::assertSent(function (Request $request) use ($session) {
            $attributes = $request['data']['attributes'];

            return $request->hasHeader('Authorization', 'Basic '.base64_encode('sk_test_fake:'))
                && $attributes['line_items'][0]['amount'] === 50000
                && $attributes['payment_method_types'] === ['gcash']
                && $attributes['success_url'] === "https://shelter.test/pay/{$session->token}";
        });
    }

    public function test_reaching_the_return_page_without_paying_settles_nothing(): void
    {
        $res = $this->startDonation(User::factory()->create())->assertCreated();

        $this->getJson('/api/payments/'.$res->json('checkout_token'))
            ->assertOk()
            ->assertJsonPath('session.status', 'open');

        $this->assertSame('awaiting_payment', Donation::find($res->json('donation.id'))->status);
    }

    public function test_the_return_page_settles_once_paymongo_reports_paid(): void
    {
        Notification::fake();
        $donor = User::factory()->create();
        $res = $this->startDonation($donor)->assertCreated();
        $token = $res->json('checkout_token');

        $this->paidRemotely();

        $this->getJson("/api/payments/{$token}")->assertOk()->assertJsonPath('session.status', 'succeeded');
        $this->getJson("/api/payments/{$token}")->assertOk()->assertJsonPath('session.status', 'succeeded');

        $donation = Donation::find($res->json('donation.id'));
        $this->assertSame('verified', $donation->status);
        $this->assertSame('gateway', $donation->settlement);
        Notification::assertSentToTimes($donor, DonationStatusChanged::class, 1);
    }

    public function test_a_paid_amount_that_does_not_match_is_not_accepted(): void
    {
        $res = $this->startDonation(User::factory()->create())->assertCreated();

        $this->paidRemotely(100);

        $this->getJson('/api/payments/'.$res->json('checkout_token'))->assertJsonPath('session.status', 'open');
    }

    public function test_a_signed_webhook_settles_the_donation_and_a_replay_does_not_settle_twice(): void
    {
        Notification::fake();
        $donor = User::factory()->create();
        $res = $this->startDonation($donor)->assertCreated();

        $this->webhook('cs_test_1')->assertOk();
        $this->webhook('cs_test_1')->assertOk();

        $this->assertSame('verified', Donation::find($res->json('donation.id'))->status);
        Notification::assertSentToTimes($donor, DonationStatusChanged::class, 1);
    }

    public function test_a_webhook_with_a_bad_signature_is_rejected(): void
    {
        $res = $this->startDonation(User::factory()->create())->assertCreated();

        $this->webhook('cs_test_1', 'whsk_someone_else')->assertStatus(401);

        $this->assertSame('awaiting_payment', Donation::find($res->json('donation.id'))->status);
    }

    public function test_a_payment_that_lands_after_the_link_expired_still_counts(): void
    {
        $res = $this->startDonation(User::factory()->create())->assertCreated();

        // The donor took their time on PayMongo's page and paid after our window closed.
        $this->travel(30)->minutes();
        $this->paidRemotely();

        $this->getJson('/api/payments/'.$res->json('checkout_token'))->assertJsonPath('session.status', 'succeeded');
        $this->assertSame('verified', Donation::find($res->json('donation.id'))->status);
    }

    public function test_reissuing_a_link_expires_the_old_one_at_paymongo(): void
    {
        $res = $this->startDonation(User::factory()->create())->assertCreated();

        $this->postJson('/api/donations/'.$res->json('donation.id').'/checkout')
            ->assertOk()
            ->assertJsonPath('checkout_url', 'https://checkout.paymongo.com/cs_test_2');

        $this->assertSame('superseded', PaymentSession::firstWhere('provider_ref', 'cs_test_1')->failure_code);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/checkout_sessions/cs_test_1/expire'));
    }

    public function test_cancelling_from_paymongo_releases_the_donation(): void
    {
        $res = $this->startDonation(User::factory()->create())->assertCreated();

        $this->postJson('/api/payments/'.$res->json('checkout_token').'/cancel')
            ->assertOk()
            ->assertJsonPath('session.status', 'cancelled');

        $this->assertSame('cancelled', Donation::find($res->json('donation.id'))->status);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/checkout_sessions/cs_test_1/expire'));
    }

    public function test_the_in_app_pin_and_otp_steps_are_refused(): void
    {
        $res = $this->startDonation(User::factory()->create())->assertCreated();

        $this->postJson('/api/payments/'.$res->json('checkout_token').'/authorize', ['account' => '09171234567', 'pin' => '1234'])
            ->assertStatus(409);
    }

    public function test_a_gift_below_paymongos_minimum_is_refused_and_nothing_is_recorded(): void
    {
        $this->startDonation(User::factory()->create(), ['amount' => 10])->assertStatus(422);

        $this->assertSame(0, Donation::count());
        Http::assertNothingSent();
    }

    public function test_when_paymongo_is_down_the_donation_is_rolled_back(): void
    {
        $this->paymongoDown = true;

        $this->startDonation(User::factory()->create())->assertStatus(502);

        $this->assertSame(0, Donation::count());
        $this->assertSame(0, PaymentSession::count());
    }
}
