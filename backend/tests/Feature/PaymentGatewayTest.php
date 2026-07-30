<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\PaymentSession;
use App\Models\User;
use App\Notifications\DonationStatusChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the simulated checkout (AspinPay): App\Services\SimulatedGateway and the
 * /api/payments/{token} endpoints that drive the hosted checkout page. The point of
 * these tests is the state machine — a donation must settle exactly once, a declined
 * payment must stay retryable, and an unpaid checkout must never reach the admin queue.
 */
class PaymentGatewayTest extends TestCase
{
    use RefreshDatabase;

    private function startGatewayDonation(User $donor, array $overrides = []): array
    {
        Sanctum::actingAs($donor);

        $res = $this->postJson('/api/donations', array_merge([
            'amount'         => 500,
            'payment_method' => 'gcash',
            'settlement'     => 'gateway',
            'category'       => 'animal_care',
        ], $overrides))->assertCreated();

        return [$res->json('donation.id'), $res->json('checkout_token')];
    }

    /** Walk a session all the way to settled. */
    private function payFully(string $token, string $account = '09171234567'): void
    {
        $this->postJson("/api/payments/{$token}/authorize", ['account' => $account, 'pin' => '1234'])
            ->assertOk()
            ->assertJsonPath('session.status', 'awaiting_otp');

        $this->postJson("/api/payments/{$token}/confirm", ['otp' => config('payments.otp')])
            ->assertOk()
            ->assertJsonPath('session.status', 'succeeded');
    }

    public function test_gateway_donation_is_created_awaiting_payment_with_a_checkout_link(): void
    {
        [$id, $token] = $this->startGatewayDonation(User::factory()->create());

        $this->assertNotEmpty($token);
        $this->assertSame('awaiting_payment', Donation::find($id)->status);
        $this->assertDatabaseHas('payment_sessions', [
            'donation_id' => $id,
            'status'      => 'open',
            'rail'        => 'gcash',
        ]);
    }

    public function test_cash_cannot_be_paid_online(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/donations', [
            'amount'         => 500,
            'payment_method' => 'cash',
            'settlement'     => 'gateway',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('settlement');
    }

    public function test_manual_gcash_still_requires_proof(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/donations', [
            'amount'         => 500,
            'payment_method' => 'gcash',
            'settlement'     => 'manual',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('proof_image');
    }

    public function test_gateway_donation_needs_no_proof(): void
    {
        [$id] = $this->startGatewayDonation(User::factory()->create());

        $this->assertNull(Donation::find($id)->proof_image);
    }

    public function test_correct_otp_settles_the_donation_and_notifies_the_donor(): void
    {
        Notification::fake();
        $donor = User::factory()->create();
        [$id, $token] = $this->startGatewayDonation($donor);

        $this->payFully($token);

        $donation = Donation::find($id);
        $this->assertSame('verified', $donation->status);
        $this->assertSame('gateway', $donation->settlement);
        $this->assertNotNull($donation->donated_at);
        Notification::assertSentTo($donor, DonationStatusChanged::class);
    }

    public function test_a_broken_mailer_does_not_fail_a_settled_payment(): void
    {
        $donor = User::factory()->create();
        [$id, $token] = $this->startGatewayDonation($donor);

        // Notifications send synchronously, so a dead SMTP server throws right in the
        // middle of the confirm request — after the money is already recorded.
        Mail::shouldReceive('mailer')->andThrow(new \RuntimeException('SMTP is down'));
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('SMTP is down'));

        $this->postJson("/api/payments/{$token}/authorize", ['account' => '09171234567', 'pin' => '1234'])
            ->assertOk();

        $this->postJson("/api/payments/{$token}/confirm", ['otp' => config('payments.otp')])
            ->assertOk()
            ->assertJsonPath('session.status', 'succeeded');

        $this->assertSame('verified', Donation::find($id)->status);
    }

    public function test_confirming_twice_settles_once(): void
    {
        Notification::fake();
        $donor = User::factory()->create();
        [$id, $token] = $this->startGatewayDonation($donor);

        $this->payFully($token);

        // A double-tap or a refreshed tab replays the confirm. The session is spent, so
        // the second call is refused outright rather than settling a second time.
        $this->postJson("/api/payments/{$token}/confirm", ['otp' => config('payments.otp')])
            ->assertStatus(409);

        $this->assertSame(1, PaymentSession::where('donation_id', $id)->where('status', 'succeeded')->count());
        Notification::assertSentToTimes($donor, DonationStatusChanged::class, 1);
    }

    public function test_a_trigger_account_declines_and_leaves_the_donation_retryable(): void
    {
        $donor = User::factory()->create();
        [$id, $token] = $this->startGatewayDonation($donor);

        $this->postJson("/api/payments/{$token}/authorize", ['account' => '09000000001', 'pin' => '1234'])
            ->assertOk()
            ->assertJsonPath('session.status', 'failed')
            ->assertJsonPath('session.failure_code', 'insufficient_funds');

        // A decline is not a cancellation — the gift is still on the table.
        $this->assertSame('awaiting_payment', Donation::find($id)->status);

        // Retrying issues a fresh link, and that one goes through.
        $retry = $this->postJson("/api/donations/{$id}/checkout")->assertOk()->json('checkout_token');
        $this->payFully($retry);

        $this->assertSame('verified', Donation::find($id)->status);
    }

    public function test_three_wrong_codes_kill_the_session(): void
    {
        [$id, $token] = $this->startGatewayDonation(User::factory()->create());

        $this->postJson("/api/payments/{$token}/authorize", ['account' => '09171234567', 'pin' => '1234'])
            ->assertOk();

        $this->postJson("/api/payments/{$token}/confirm", ['otp' => '000000'])
            ->assertOk()
            ->assertJsonPath('session.status', 'awaiting_otp')
            ->assertJsonPath('session.attempts_left', 2);

        $this->postJson("/api/payments/{$token}/confirm", ['otp' => '000000'])->assertOk();

        $this->postJson("/api/payments/{$token}/confirm", ['otp' => '000000'])
            ->assertOk()
            ->assertJsonPath('session.status', 'failed')
            ->assertJsonPath('session.failure_code', 'invalid_otp');

        // Even the right code cannot revive a burned session.
        $this->postJson("/api/payments/{$token}/confirm", ['otp' => config('payments.otp')])
            ->assertStatus(409);

        $this->assertSame('awaiting_payment', Donation::find($id)->status);
    }

    public function test_cancelling_marks_the_donation_cancelled_and_it_can_be_resumed(): void
    {
        $donor = User::factory()->create();
        [$id, $token] = $this->startGatewayDonation($donor);

        $this->postJson("/api/payments/{$token}/cancel")
            ->assertOk()
            ->assertJsonPath('session.status', 'cancelled');

        $this->assertSame('cancelled', Donation::find($id)->status);

        $resumed = $this->postJson("/api/donations/{$id}/checkout")->assertOk()->json('checkout_token');
        $this->assertSame('awaiting_payment', Donation::find($id)->status);
        $this->payFully($resumed);
        $this->assertSame('verified', Donation::find($id)->status);
    }

    public function test_reissuing_a_link_voids_the_previous_one(): void
    {
        $donor = User::factory()->create();
        [$id, $first] = $this->startGatewayDonation($donor);

        $this->postJson("/api/donations/{$id}/checkout")->assertOk();

        // Two payable links for one donation would mean two possible settlements.
        $this->postJson("/api/payments/{$first}/authorize", ['account' => '09171234567', 'pin' => '1234'])
            ->assertStatus(409);
    }

    public function test_another_users_checkout_is_forbidden(): void
    {
        [, $token] = $this->startGatewayDonation(User::factory()->create());

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/payments/{$token}")->assertStatus(403);
        $this->postJson("/api/payments/{$token}/cancel")->assertStatus(403);
    }

    public function test_an_unknown_token_is_a_404(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/payments/nope')->assertStatus(404);
    }

    public function test_an_expired_session_is_gone_and_releases_the_donation(): void
    {
        $donor = User::factory()->create();
        [$id, $token] = $this->startGatewayDonation($donor);

        $this->travel(config('payments.session_ttl_minutes') + 1)->minutes();

        // Reading the session sweeps it, without waiting on the scheduled command.
        $this->getJson("/api/payments/{$token}")
            ->assertOk()
            ->assertJsonPath('session.status', 'expired');

        $this->assertSame('cancelled', Donation::find($id)->status);

        $this->postJson("/api/payments/{$token}/authorize", ['account' => '09171234567', 'pin' => '1234'])
            ->assertStatus(410);
    }

    public function test_the_expiry_command_sweeps_abandoned_sessions(): void
    {
        [$id] = $this->startGatewayDonation(User::factory()->create());

        $this->travel(config('payments.session_ttl_minutes') + 1)->minutes();
        $this->artisan('payments:expire-sessions')->assertExitCode(0);

        $this->assertDatabaseHas('payment_sessions', ['donation_id' => $id, 'status' => 'expired']);
        $this->assertSame('cancelled', Donation::find($id)->status);
    }

    public function test_unsettled_checkouts_stay_out_of_the_admin_queue_and_public_totals(): void
    {
        $donor = User::factory()->create();
        [, $abandoned] = $this->startGatewayDonation($donor);
        [, $paid] = $this->startGatewayDonation($donor, ['amount' => 750]);

        $this->payFully($paid);
        $this->postJson("/api/payments/{$abandoned}/cancel")->assertOk();

        // The admin sidebar badge counts work waiting on staff. Neither of these is.
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/admin/dashboard/pending-counts')
            ->assertOk()
            ->assertJsonPath('donation', 0);

        $this->getJson('/api/admin/donations/stats')
            ->assertOk()
            ->assertJsonPath('counts.pending', 0)
            ->assertJsonPath('counts.verified', 1)
            ->assertJsonPath('counts.cancelled', 1);

        // Only the settled gift is public money.
        $this->getJson('/api/home/transparency')
            ->assertOk()
            ->assertJsonPath('this_month_raised', 750);
    }
}
