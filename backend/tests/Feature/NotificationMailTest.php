<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\User;
use App\Notifications\DonationStatusChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Notification emails must go out over Brevo's HTTPS API when it is configured. Render's free
 * tier blocks SMTP, and a notification sent over SMTP there held its request open until the
 * socket timed out — which is what froze the payment return page for minutes.
 */
class NotificationMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_email_is_sent_through_brevo_when_configured(): void
    {
        config(['services.brevo.key' => 'xkeysib-test']);
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'x'], 201)]);

        $user = User::factory()->create();
        $donation = Donation::create([
            'user_id'        => $user->id,
            'reference_no'   => 'DON-MAILTEST01',
            'amount'         => 500,
            'payment_method' => 'gcash',
            'status'         => 'verified',
        ]);

        (new DonationStatusChanged($donation))->sendTo($user);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.brevo.com/v3/smtp/email'
            && $request['to'][0]['email'] === $user->email
            && $request['subject'] === 'Donation update'
            && str_contains($request['textContent'], 'DON-MAILTEST01'));

        $this->assertDatabaseHas('app_notifications', ['user_id' => $user->id, 'type' => 'donation_status']);
    }
}
