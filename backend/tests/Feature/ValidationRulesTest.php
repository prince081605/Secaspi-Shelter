<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\User;
use App\Rules\NotInFuture;
use App\Rules\PhilippinePhone;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Forms say what's actually wrong (not "Validation failed"), phone numbers must look like a
 * Philippine number, and dates/amounts that can't be real are refused.
 */
class ValidationRulesTest extends TestCase
{
    use RefreshDatabase;

    private function passes(mixed $value, object $rule): bool
    {
        return Validator::make(['field' => $value], ['field' => [$rule]])->passes();
    }

    public function test_philippine_phone_accepts_common_formats(): void
    {
        foreach (['09171234567', '0917 123 4567', '0917-123-4567', '+63 917 123 4567', '639171234567', '(02) 8123 4567'] as $number) {
            $this->assertTrue($this->passes($number, new PhilippinePhone), "{$number} should be accepted");
        }
    }

    public function test_philippine_phone_rejects_numbers_that_cannot_be_dialled(): void
    {
        foreach (['12345', '0917', 'call me', '+1 415 555 0100', '091712345678901'] as $number) {
            $this->assertFalse($this->passes($number, new PhilippinePhone), "{$number} should be refused");
        }
    }

    public function test_not_in_future_uses_the_shelters_day_in_manila(): void
    {
        // 23:30 UTC is already 07:30 the next morning in Manila.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07 23:30:00', 'UTC'));
        try {
            $this->assertTrue($this->passes('2026-10-08', new NotInFuture));
            $this->assertTrue($this->passes('2026-10-01', new NotInFuture));
            $this->assertFalse($this->passes('2026-10-09', new NotInFuture));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_errors_name_the_problem_instead_of_a_generic_message(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/profile', ['full_name' => 'Juan Dela Cruz', 'phone' => '12345'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The phone must be a valid Philippine phone number, e.g. 0917 123 4567.')
            ->assertJsonValidationErrors('phone');

        $this->putJson('/api/profile', ['full_name' => 'Juan Dela Cruz', 'phone' => '0917 123 4567'])
            ->assertOk();
    }

    public function test_rescue_report_contact_must_be_a_phone_number(): void
    {
        $this->postJson('/api/rescue-reports', ['location' => 'Market', 'urgency' => 'high', 'contact_number' => 'abc'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('contact_number');
    }

    public function test_health_records_and_expenses_cannot_be_dated_in_the_future(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $animal = Animal::create(['name' => 'Buddy', 'species' => 'dog', 'status' => 'available']);
        $tomorrow = now('Asia/Manila')->addDay()->toDateString();

        $this->postJson("/api/animals/{$animal->id}/medical-records", ['type' => 'checkup', 'record_date' => $tomorrow])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The record date cannot be in the future.');

        $this->postJson("/api/animals/{$animal->id}/vaccinations", ['vaccine_name' => 'Rabies', 'date_given' => $tomorrow])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The date given cannot be in the future.');

        $this->postJson('/api/admin/expenses', [
            'category' => 'animal_care', 'amount' => 100, 'description' => 'Food', 'spent_at' => $tomorrow,
        ])->assertStatus(422)->assertJsonPath('message', 'The date spent cannot be in the future.');

        $this->postJson('/api/admin/expenses', [
            'category' => 'animal_care', 'amount' => 100000000, 'description' => 'Food', 'spent_at' => now()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_foster_cannot_start_in_the_past(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $animal = Animal::create(['name' => 'Mingming', 'species' => 'cat', 'status' => 'available']);

        $this->postJson("/api/animals/{$animal->id}/foster", [
            'full_name' => 'Juan Dela Cruz', 'address' => 'Manila', 'reason' => 'I have space',
            'start_date' => now()->subDays(3)->toDateString(),
        ])->assertStatus(422)->assertJsonPath('message', 'The start date cannot be in the past.');
    }

    public function test_animal_age_and_weight_must_be_believable(): void
    {
        Sanctum::actingAs(User::factory()->staff()->create());

        $this->postJson('/api/animals', ['name' => 'Old Timer', 'species' => 'dog', 'age' => 120])
            ->assertStatus(422)->assertJsonValidationErrors('age');

        $this->postJson('/api/animals', ['name' => 'Heavy', 'species' => 'dog', 'weight' => 900])
            ->assertStatus(422)->assertJsonValidationErrors('weight');

        $this->postJson('/api/animals', ['name' => 'Normal', 'species' => 'dog', 'age' => 12, 'weight' => 30])
            ->assertCreated();
    }
}
