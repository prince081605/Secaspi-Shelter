<?php

namespace Tests\Feature;

use App\Models\BlockedReporter;
use App\Models\RescueReport;
use App\Models\User;
use App\Services\ReportScreening;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Spam and fake rescue reports: a daily limit, blocking a reporter, spotting a report about a
 * place that's already open, and staff closing a report as invalid without it counting as a rescue.
 */
class RescueScreeningTest extends TestCase
{
    use RefreshDatabase;

    private function report(array $overrides = [])
    {
        return $this->postJson('/api/rescue-reports', array_merge([
            'location' => 'Near the public market',
            'urgency' => 'high',
            'contact_number' => '0917 123 4567',
        ], $overrides));
    }

    public function test_a_contact_number_can_send_three_reports_a_day(): void
    {
        $this->report(['location' => 'Place one'])->assertCreated();
        $this->report(['location' => 'Place two', 'contact_number' => '+63 917 123 4567'])->assertCreated();
        $this->report(['location' => 'Place three', 'contact_number' => '09171234567'])->assertCreated();

        $this->report(['location' => 'Place four'])
            ->assertStatus(429)
            ->assertJsonPath('message', fn ($m) => str_starts_with($m, 'You have already sent 3 rescue reports today.'));

        $this->assertSame(3, RescueReport::count());
        // A different number is not held back.
        $this->report(['location' => 'Place five', 'contact_number' => '0918 765 4321'])->assertCreated();
    }

    public function test_one_connection_is_limited_more_loosely(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        for ($i = 0; $i < ReportScreening::DAILY_LIMIT_PER_CONNECTION; $i++) {
            $this->report(['location' => "Street {$i}", 'contact_number' => null])->assertCreated();
        }
        $this->report(['location' => 'Street 99', 'contact_number' => null])->assertStatus(429);
    }

    public function test_a_blocked_number_is_refused_with_the_shelter_phone(): void
    {
        $admin = User::factory()->systemAdmin()->create();
        $this->report()->assertCreated();
        $report = RescueReport::first();

        Sanctum::actingAs($admin);
        $this->postJson("/api/rescue-reports/{$report->id}/block", ['reason' => 'Three fake reports'])->assertCreated();
        $this->assertDatabaseHas('blocked_reporters', ['label' => '0917 •••• 567', 'reason' => 'Three fake reports']);
        $this->app['auth']->forgetGuards();

        $this->report(['location' => 'Somewhere else', 'contact_number' => '+639171234567'])
            ->assertForbidden()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'can no longer be accepted online'));

        // Unblocking lets them report again.
        Sanctum::actingAs($admin);
        $blocked = BlockedReporter::first();
        $this->deleteJson("/api/admin/blocked-reporters/{$blocked->id}")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->report(['location' => 'Somewhere else'])->assertCreated();
    }

    public function test_only_the_system_admin_blocks_and_only_reports_with_a_number_or_account(): void
    {
        $this->report(['contact_number' => null])->assertCreated();
        $anonymous = RescueReport::first();

        Sanctum::actingAs(User::factory()->staff()->create());
        $this->postJson("/api/rescue-reports/{$anonymous->id}/block")->assertForbidden();

        Sanctum::actingAs(User::factory()->systemAdmin()->create());
        $this->postJson("/api/rescue-reports/{$anonymous->id}/block")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This report has no contact number or account to block.');
    }

    public function test_a_report_near_an_open_one_is_flagged_as_a_possible_duplicate(): void
    {
        $this->report(['latitude' => 14.2117, 'longitude' => 121.1653])->assertCreated();
        $first = RescueReport::first();

        // About 100 m away → flagged; about 1 km away → not.
        $this->report(['location' => 'By the chapel', 'contact_number' => '0918 000 0001', 'latitude' => 14.2126, 'longitude' => 121.1653])->assertCreated();
        $this->report(['location' => 'By the river', 'contact_number' => '0918 000 0002', 'latitude' => 14.2207, 'longitude' => 121.1653])->assertCreated();
        // No pin, but the same typed place → flagged.
        $this->report(['location' => 'near the public market.', 'contact_number' => '0918 000 0003'])->assertCreated();

        $flags = RescueReport::orderBy('id')->pluck('possible_duplicate_of_id')->all();
        $this->assertSame([null, $first->id, null, $first->id], $flags);
    }

    public function test_staff_close_a_report_as_invalid_with_a_reason(): void
    {
        $this->report()->assertCreated();
        $this->report(['location' => 'Elsewhere', 'contact_number' => '0918 000 0009'])->assertCreated();
        [$original, $repeat] = RescueReport::orderBy('id')->get()->all();

        Sanctum::actingAs(User::factory()->staff()->create());

        $this->postJson("/api/rescue-reports/{$repeat->id}/status", ['status' => 'invalid'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Choose why this report is invalid.');
        $this->postJson("/api/rescue-reports/{$repeat->id}/status", ['status' => 'invalid', 'invalid_reason' => 'duplicate'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Enter the number of the report this one repeats.');

        $this->postJson("/api/rescue-reports/{$repeat->id}/status", [
            'status' => 'invalid', 'invalid_reason' => 'duplicate', 'duplicate_of_id' => $original->id, 'invalid_note' => 'Same dog',
        ])->assertOk()
            ->assertJsonPath('report.invalid_reason_label', 'Duplicate')
            ->assertJsonPath('report.duplicate_of.id', $original->id);

        // Invalid reports leave the default list, and the public count of reports handled.
        $this->getJson('/api/rescue-reports')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/rescue-reports?status=invalid')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/home/impact')->assertOk()->assertJsonPath('rescue_reports_handled', 1);

        // Reopening clears the reason.
        $this->postJson("/api/rescue-reports/{$repeat->id}/status", ['status' => 'pending'])->assertOk();
        $this->assertDatabaseHas('rescue_reports', ['id' => $repeat->id, 'status' => 'pending', 'invalid_reason' => null, 'duplicate_of_id' => null]);
    }

    public function test_triage_shows_the_reporters_history_without_the_hashes(): void
    {
        $this->report(['location' => 'One'])->assertCreated();
        $this->report(['location' => 'Two'])->assertCreated();
        RescueReport::orderBy('id')->first()->update(['status' => 'invalid', 'invalid_reason' => 'fake']);
        $this->report(['location' => 'Three'])->assertCreated();

        Sanctum::actingAs(User::factory()->staff()->create());
        $latest = $this->getJson('/api/rescue-reports')->assertOk()->json('data.0');

        $this->assertSame('Three', $latest['location']);
        $this->assertSame(['basis' => 'contact', 'earlier' => 2, 'invalid' => 1, 'fake' => 1], $latest['reporter_history']);
        $this->assertTrue($latest['can_block']);
        $this->assertArrayNotHasKey('contact_hash', $latest);
        $this->assertArrayNotHasKey('ip_hash', $latest);
    }

    public function test_a_logged_in_reporter_is_linked_to_their_account(): void
    {
        $user = User::factory()->create(['full_name' => 'Juan Dela Cruz']);
        Sanctum::actingAs($user);

        $this->report(['contact_number' => null])->assertCreated();

        $this->assertDatabaseHas('rescue_reports', ['user_id' => $user->id]);
    }
}
