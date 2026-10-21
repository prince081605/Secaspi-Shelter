<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Intake;
use App\Models\User;
use App\Support\AnimalAge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * An animal's age is an estimated birth date: staff enter "3 months" or "2 years", and the age
 * shown is worked out from it every time — so a puppy grows up on its own.
 */
class AnimalAgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-21 10:00:00');
        Sanctum::actingAs(User::factory()->staff()->create());
    }

    public function test_ages_are_read_the_way_people_write_them(): void
    {
        $this->assertSame(['value' => 3, 'unit' => 'months'], AnimalAge::parse('3 months'));
        $this->assertSame(['value' => 6, 'unit' => 'weeks'], AnimalAge::parse('6 wks'));
        $this->assertSame(['value' => 2, 'unit' => 'years'], AnimalAge::parse('about 2 yrs'));
        $this->assertSame(['value' => 4, 'unit' => 'years'], AnimalAge::parse('4'));
        $this->assertSame(['value' => 4, 'unit' => 'years'], AnimalAge::parse(4.0));
        $this->assertSame(['value' => 18, 'unit' => 'months'], AnimalAge::parse('1.5 years'));
        $this->assertSame(['value' => 2, 'unit' => 'years'], AnimalAge::parse('~2 years old'));
        $this->assertNull(AnimalAge::parse('three'));
        $this->assertNull(AnimalAge::parse('2 decades'));
    }

    public function test_a_puppy_entered_in_months_grows_up_on_its_own(): void
    {
        $id = $this->post('/api/animals', ['name' => 'Peanut', 'species' => 'dog', 'age_value' => 6, 'age_unit' => 'weeks'], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('animal.age_label', '6 weeks')
            ->assertJsonPath('animal.birth_date', '2026-09-09')
            ->assertJsonPath('animal.age_parts', ['value' => 6, 'unit' => 'weeks'])
            ->json('animal.id');

        $this->travelTo('2027-01-21 10:00:00');
        $this->getJson("/api/animals/{$id}")->assertJsonPath('animal.age_label', '4 months');

        $this->travelTo('2028-03-01 10:00:00');
        $this->getJson("/api/animals/{$id}")->assertJsonPath('animal.age_label', '1 year')->assertJsonPath('animal.age', 1);
    }

    public function test_the_age_can_be_changed_cleared_or_given_as_a_birth_date(): void
    {
        $animal = Animal::create(['name' => 'Brownie', 'species' => 'dog', 'status' => 'available', 'birth_date' => '2023-10-21']);

        // Editing something else leaves the age alone.
        $this->putJson("/api/animals/{$animal->id}", ['weight' => 12])->assertOk()->assertJsonPath('animal.age_label', '3 years');

        $this->putJson("/api/animals/{$animal->id}", ['age_value' => 8, 'age_unit' => 'months'])->assertOk()
            ->assertJsonPath('animal.age_label', '8 months');
        $this->putJson("/api/animals/{$animal->id}", ['birth_date' => '2025-04-01'])->assertOk()
            ->assertJsonPath('animal.age_label', '1 year')->assertJsonPath('animal.birth_date', '2025-04-01');
        $this->putJson("/api/animals/{$animal->id}", ['age_value' => null])->assertOk()
            ->assertJsonPath('animal.age_label', null);
        $this->assertNull($animal->fresh()->birth_date);
    }

    public function test_impossible_ages_are_refused(): void
    {
        $send = fn (array $age) => $this->postJson('/api/animals', ['name' => 'Rex', 'species' => 'dog', ...$age]);

        $send(['age_value' => 0, 'age_unit' => 'years'])->assertStatus(422)
            ->assertJsonPath('message', 'For an animal under a year old, give its age in months or weeks.');
        $send(['age_value' => 3])->assertStatus(422)->assertJsonValidationErrors('age_unit');
        $send(['age_value' => 2, 'age_unit' => 'decades'])->assertStatus(422);
        $send(['birth_date' => '2026-12-01'])->assertStatus(422);   // in the future
        $send(['age_value' => 0, 'age_unit' => 'weeks'])->assertCreated()->assertJsonPath('animal.age_label', 'Under 1 week');
    }

    public function test_an_intake_estimate_carries_over_and_life_stage_follows_the_age(): void
    {
        $intake = Intake::create(['intake_type' => 'stray', 'animal_name' => 'Mingming', 'species' => 'cat', 'estimated_age' => '~3 months', 'status' => 'pending']);
        $animalId = $this->postJson("/api/admin/intakes/{$intake->id}/convert")->assertOk()->json('animal.id');
        $this->assertSame('3 months', Animal::find($animalId)->ageLabel());
        $this->assertSame('puppy', Animal::find($animalId)->lifeStage());

        $this->assertSame('adult', (new Animal(['birth_date' => '2022-01-01']))->lifeStage());
        $this->assertSame('senior', (new Animal(['birth_date' => '2015-01-01']))->lifeStage());
        $this->assertNull((new Animal)->lifeStage());
    }
}
