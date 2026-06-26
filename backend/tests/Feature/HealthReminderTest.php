<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\MedicalRecord;
use App\Models\Reminder;
use App\Models\User;
use App\Models\Vaccination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Health reminders are auto-managed: a due date on a vaccination or medical record creates a
 * reminder, recording the next shot clears the old one, and clearing the date removes it.
 */
class HealthReminderTest extends TestCase
{
    use RefreshDatabase;

    private function animal(array $attrs = []): Animal
    {
        return Animal::create(array_merge([
            'name' => 'Buddy',
            'species' => 'dog',
            'status' => 'available',
        ], $attrs));
    }

    private function actingAsStaff(): void
    {
        Sanctum::actingAs(User::factory()->staff()->create());
    }

    public function test_vaccination_with_next_due_creates_a_reminder(): void
    {
        $this->actingAsStaff();
        $animal = $this->animal();

        $this->postJson("/api/animals/{$animal->id}/vaccinations", [
            'vaccine_name' => 'Rabies',
            'date_given' => '2026-01-01',
            'next_due' => '2027-01-01',
        ])->assertCreated();

        $this->assertDatabaseHas('reminders', [
            'animal_id' => $animal->id,
            'remindable_type' => Vaccination::class,
            'status' => 'pending',
        ]);
    }

    public function test_clearing_next_due_removes_the_reminder(): void
    {
        $this->actingAsStaff();
        $animal = $this->animal();

        $this->postJson("/api/animals/{$animal->id}/vaccinations", [
            'vaccine_name' => 'Rabies',
            'date_given' => '2026-01-01',
            'next_due' => '2027-01-01',
        ])->assertCreated();

        $vaccination = Vaccination::first();
        $this->assertEquals(1, Reminder::count());

        $this->putJson("/api/vaccinations/{$vaccination->id}", [
            'next_due' => null,
        ])->assertOk();

        $this->assertEquals(0, Reminder::count());
    }

    public function test_recording_the_next_shot_completes_the_prior_reminder(): void
    {
        $this->actingAsStaff();
        $animal = $this->animal();

        // First shot with a booster due date → one pending reminder.
        $this->postJson("/api/animals/{$animal->id}/vaccinations", [
            'vaccine_name' => 'Rabies',
            'date_given' => '2026-01-01',
            'next_due' => '2027-01-01',
        ])->assertCreated();
        $firstId = Vaccination::first()->id;

        // The booster is administered (a new Rabies shot) → the prior reminder is now done.
        $this->postJson("/api/animals/{$animal->id}/vaccinations", [
            'vaccine_name' => 'rabies', // case-insensitive match
            'date_given' => '2027-01-02',
            'next_due' => '2028-01-02',
        ])->assertCreated();

        $this->assertDatabaseHas('reminders', [
            'remindable_type' => Vaccination::class,
            'remindable_id' => $firstId,
            'status' => 'completed',
        ]);
        // The newest shot still has its own pending reminder.
        $this->assertEquals(1, Reminder::where('status', 'pending')->count());
    }

    public function test_medical_record_with_follow_up_creates_a_reminder(): void
    {
        $this->actingAsStaff();
        $animal = $this->animal();

        $this->postJson("/api/animals/{$animal->id}/medical-records", [
            'type' => 'surgery',
            'record_date' => '2026-06-01',
            'follow_up_date' => '2026-06-15',
        ])->assertCreated();

        $this->assertDatabaseHas('reminders', [
            'animal_id' => $animal->id,
            'remindable_type' => MedicalRecord::class,
            'status' => 'pending',
        ]);
    }

    public function test_medical_record_without_follow_up_creates_no_reminder(): void
    {
        $this->actingAsStaff();
        $animal = $this->animal();

        $this->postJson("/api/animals/{$animal->id}/medical-records", [
            'type' => 'checkup',
            'record_date' => '2026-06-01',
        ])->assertCreated();

        $this->assertEquals(0, Reminder::count());
    }
}
