<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\CategoryOption;
use App\Models\MedicalRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Category management: breeds, behavioral issues, medical record types and valid ID types are
 * admin-managed lists. Admins add, rename, hide/restore and (when unused) delete; renames reach
 * the records that use the old name, and hiding never invalidates an existing record.
 */
class CategoryOptionTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    private function option(string $type, string $value): CategoryOption
    {
        return CategoryOption::where('type', $type)->where('value', $value)->firstOrFail();
    }

    private function animal(array $attributes): int
    {
        return DB::table('animals')->insertGetId([
            'name' => 'Bantay', 'species' => 'dog', 'status' => 'available', 'created_at' => now(), ...$attributes,
        ]);
    }

    public function test_the_lists_start_out_as_the_app_had_them(): void
    {
        $lists = $this->getJson('/api/categories')->assertOk()->json('categories');

        $this->assertSame(['dog_breed', 'cat_breed', 'behavioral_issue', 'medical_record_type', 'valid_id_type'], array_keys($lists));
        $this->assertSame(['Aspin', 'Aspin mix'], array_column(array_slice($lists['dog_breed'], 0, 2), 'label'));
        $this->assertSame('Puspin', $lists['cat_breed'][0]['label']);
        $this->assertCount(18, $lists['behavioral_issue']);
        $this->assertSame(['value' => 'checkup', 'label' => 'Checkup', 'hidden' => false], $lists['medical_record_type'][4]);
        $this->assertContains(['value' => 'national_id', 'label' => 'National ID (PhilSys)', 'hidden' => false], $lists['valid_id_type']);

        $this->getJson('/api/categories?types=valid_id_type')->assertOk()
            ->assertJsonCount(1, 'categories')->assertJsonCount(8, 'categories.valid_id_type');
    }

    public function test_reading_is_public_but_only_admins_change_lists(): void
    {
        $this->getJson('/api/categories?types=dog_breed')->assertOk();
        $this->postJson('/api/admin/categories/dog_breed', ['label' => 'Askal'])->assertUnauthorized();

        Sanctum::actingAs(User::factory()->staff()->create());
        $this->getJson('/api/admin/categories/dog_breed')->assertForbidden();
        $this->postJson('/api/admin/categories/dog_breed', ['label' => 'Askal'])->assertForbidden();
        $this->putJson('/api/admin/categories/dog_breed/'.$this->option('dog_breed', 'Beagle')->id, ['is_active' => false])->assertForbidden();

        $this->actingAsAdmin();
        $this->getJson('/api/admin/categories/not_a_list')->assertNotFound();
    }

    public function test_an_added_option_is_offered_and_accepted(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/categories/medical_record_type', ['label' => '  Dental   cleaning '])->assertCreated()
            ->assertJsonPath('option.value', 'dental_cleaning')
            ->assertJsonPath('option.label', 'Dental cleaning');
        $this->postJson('/api/admin/categories/dog_breed', ['label' => 'Askal'])->assertCreated()
            ->assertJsonPath('option.value', 'Askal');

        // Already there, in any capitalisation; or blank.
        $this->postJson('/api/admin/categories/medical_record_type', ['label' => 'DENTAL CLEANING'])
            ->assertStatus(422)->assertJsonPath('message', '"DENTAL CLEANING" is already on the list.');
        $this->postJson('/api/admin/categories/dog_breed', ['label' => '   '])->assertStatus(422);

        $animal = $this->animal([]);
        $this->postJson("/api/animals/{$animal}/medical-records", ['type' => 'dental_cleaning', 'record_date' => '2026-10-01'])
            ->assertCreated();
        $this->getJson("/api/admin/animals/{$animal}")->assertJsonPath('animal.medical_records.0.type_label', 'Dental cleaning');
        $this->assertSame('Askal', collect($this->getJson('/api/categories?types=dog_breed')->json('categories.dog_breed'))->last()['label']);
    }

    public function test_renaming_a_breed_updates_animals_of_that_species_only(): void
    {
        $this->actingAsAdmin();
        $dogA = $this->animal(['breed' => 'Mixed breed']);
        $dogB = $this->animal(['breed' => 'mixed BREED']);
        $cat = $this->animal(['species' => 'cat', 'breed' => 'Mixed breed']);

        $this->getJson('/api/admin/categories/dog_breed')->assertOk()
            ->assertJsonPath('used_by', 'animal')
            ->assertJsonFragment(['label' => 'Mixed breed', 'usage' => 2]);

        $this->putJson('/api/admin/categories/dog_breed/'.$this->option('dog_breed', 'Mixed breed')->id, ['label' => 'Mixed-breed dog'])
            ->assertOk()
            ->assertJsonPath('records_updated', 2)
            ->assertJsonPath('option.value', 'Mixed-breed dog')
            ->assertJsonPath('option.usage', 2);

        $this->assertDatabaseHas('animals', ['id' => $dogA, 'breed' => 'Mixed-breed dog']);
        $this->assertDatabaseHas('animals', ['id' => $dogB, 'breed' => 'Mixed-breed dog']);
        $this->assertDatabaseHas('animals', ['id' => $cat, 'breed' => 'Mixed breed']);
        $this->assertSame('Mixed breed', $this->option('cat_breed', 'Mixed breed')->label);

        // Can't take a name another option already has.
        $this->putJson('/api/admin/categories/dog_breed/'.$this->option('dog_breed', 'Beagle')->id, ['label' => 'aspin'])
            ->assertStatus(422);
    }

    public function test_renaming_a_behavioral_issue_updates_the_animals_that_have_it(): void
    {
        $this->actingAsAdmin();
        $id = $this->animal(['behavioral_assessment' => json_encode(['excessive barking', 'extreme shyness'])]);
        $this->animal(['behavioral_assessment' => json_encode(['extreme shyness'])]);

        $this->putJson('/api/admin/categories/behavioral_issue/'.$this->option('behavioral_issue', 'excessive barking')->id, ['label' => 'barks at night'])
            ->assertOk()->assertJsonPath('records_updated', 1);

        $this->assertSame(['barks at night', 'extreme shyness'], Animal::find($id)->behavioral_assessment);
        $this->assertContains('barks at night', CategoryOption::activeValues('behavioral_issue'));
    }

    public function test_renaming_a_keyed_option_changes_only_its_label(): void
    {
        $this->actingAsAdmin();
        $animal = $this->animal([]);
        $record = MedicalRecord::create(['animal_id' => $animal, 'type' => 'checkup', 'record_date' => '2026-10-01']);

        $this->putJson('/api/admin/categories/medical_record_type/'.$this->option('medical_record_type', 'checkup')->id, ['label' => 'Wellness check'])
            ->assertOk()->assertJsonPath('records_updated', 0)->assertJsonPath('option.value', 'checkup');

        $this->assertSame('checkup', $record->fresh()->type);
        $this->getJson("/api/admin/animals/{$animal}")->assertJsonPath('animal.medical_records.0.type_label', 'Wellness check');
        $this->getJson("/api/animals/{$animal}")->assertJsonPath('animal.medical_records.0.type_label', 'Wellness check');
        $this->assertContains(['label' => 'Wellness check', 'value' => 1], $this->getJson('/api/admin/reports/medical')->json('summary'));
    }

    public function test_hiding_an_option_stops_new_use_but_keeps_existing_records_valid(): void
    {
        $this->actingAsAdmin();
        $animal = $this->animal([]);
        $record = MedicalRecord::create(['animal_id' => $animal, 'type' => 'surgery', 'record_date' => '2026-10-01']);
        $surgery = $this->option('medical_record_type', 'surgery');

        $this->putJson("/api/admin/categories/medical_record_type/{$surgery->id}", ['is_active' => false])
            ->assertOk()->assertJsonPath('option.hidden', true);

        $this->postJson("/api/animals/{$animal}/medical-records", ['type' => 'surgery', 'record_date' => '2026-10-02'])
            ->assertStatus(422)->assertJsonValidationErrors('type');
        $this->putJson("/api/medical-records/{$record->id}", ['type' => 'surgery', 'description' => 'Spay'])->assertOk();
        $this->assertContains(['value' => 'surgery', 'label' => 'Surgery', 'hidden' => true], $this->getJson('/api/categories')->json('categories.medical_record_type'));

        // A hidden ID type is no longer accepted on an application.
        $this->putJson('/api/admin/categories/valid_id_type/'.$this->option('valid_id_type', 'passport')->id, ['is_active' => false])->assertOk();
        Storage::fake();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/animals/{$animal}/adopt", [
            'full_name' => 'Juan Dela Cruz', 'contact_number' => '09171234567', 'address' => 'Calamba, Laguna',
            'reason' => 'We have a fenced yard.', 'valid_id_type' => 'passport',
            'valid_id_image' => UploadedFile::fake()->create('id.jpg', 100, 'image/jpeg'),
        ])->assertStatus(422)->assertJsonValidationErrors('valid_id_type');

        // Restoring brings it back.
        $this->actingAsAdmin();
        $this->putJson("/api/admin/categories/medical_record_type/{$surgery->id}", ['is_active' => true])->assertOk();
        $this->postJson("/api/animals/{$animal}/medical-records", ['type' => 'surgery', 'record_date' => '2026-10-03'])->assertCreated();
    }

    public function test_only_an_unused_option_can_be_deleted(): void
    {
        $this->actingAsAdmin();
        $animal = $this->animal([]);
        MedicalRecord::create(['animal_id' => $animal, 'type' => 'emergency', 'record_date' => '2026-10-01']);

        $this->deleteJson('/api/admin/categories/medical_record_type/'.$this->option('medical_record_type', 'emergency')->id)
            ->assertStatus(422)
            ->assertJsonPath('message', '"Emergency" is used by 1 medical record, so it can\'t be deleted. Hide it instead — those records keep it.');

        $typo = $this->postJson('/api/admin/categories/dog_breed', ['label' => 'Labardor'])->json('option.id');
        $this->deleteJson("/api/admin/categories/dog_breed/{$typo}")->assertOk();
        $this->assertDatabaseMissing('category_options', ['id' => $typo]);

        // An option from another list can't be reached through this one.
        $this->deleteJson('/api/admin/categories/cat_breed/'.$this->option('dog_breed', 'Beagle')->id)->assertNotFound();
    }

    public function test_the_last_visible_option_of_a_list_stays_visible(): void
    {
        $this->actingAsAdmin();
        CategoryOption::where('type', 'medical_record_type')->where('value', '!=', 'checkup')->update(['is_active' => false]);

        $this->putJson('/api/admin/categories/medical_record_type/'.$this->option('medical_record_type', 'checkup')->id, ['is_active' => false])
            ->assertStatus(422)->assertJsonPath('message', 'Keep at least one option visible, or there is nothing left to choose.');
    }
}
