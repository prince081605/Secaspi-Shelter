<?php

namespace Tests\Feature;

use App\Models\CategoryOption;
use App\Models\User;
use App\Models\Volunteer;
use App\Models\VolunteerApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Volunteer categories: a managed list; applicants say what they'd like to help with, approval
 * carries that over, staff assign categories and filter the personnel list by them.
 */
class VolunteerCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_list_is_seeded_and_managed_like_the_others(): void
    {
        $this->getJson('/api/categories?types=volunteer_category')
            ->assertOk()
            ->assertJsonPath('categories.volunteer_category.0', ['value' => 'rescue', 'label' => 'Rescue', 'hidden' => false]);

        Sanctum::actingAs(User::factory()->dataAdmin()->create());
        $this->postJson('/api/admin/categories/volunteer_category', ['label' => 'Dog walking'])
            ->assertCreated()
            ->assertJsonPath('option.value', 'dog_walking');
    }

    public function test_applicants_pick_categories_and_approval_carries_them_over(): void
    {
        Storage::fake();
        $applicant = User::factory()->create();
        Sanctum::actingAs($applicant);

        $this->post('/api/volunteer-applications', [
            'availability' => 'Weekends',
            'reason' => 'I love dogs',
            'valid_id_type' => 'school_id',
            'valid_id_image' => UploadedFile::fake()->image('id.jpg'),
            'preferred_categories' => ['feeding', 'transport'],
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('application.preferred_categories', ['feeding', 'transport']);

        $this->post('/api/volunteer-applications', [
            'availability' => 'Weekends', 'reason' => 'x', 'valid_id_type' => 'school_id',
            'valid_id_image' => UploadedFile::fake()->image('id.jpg'), 'preferred_categories' => ['astronaut'],
        ], ['Accept' => 'application/json'])->assertStatus(422);

        Sanctum::actingAs(User::factory()->staff()->create());
        $application = VolunteerApplication::first();
        $this->putJson("/api/admin/volunteer-applications/{$application->id}", ['status' => 'approved'])->assertOk();

        $this->assertSame(['feeding', 'transport'], Volunteer::where('user_id', $applicant->id)->first()->categories);
    }

    public function test_staff_assign_categories_and_filter_by_them(): void
    {
        Sanctum::actingAs(User::factory()->staff()->create());
        $ana = Volunteer::create(['user_id' => User::factory()->create()->id, 'type' => 'volunteer']);
        $ben = Volunteer::create(['user_id' => User::factory()->create()->id, 'type' => 'volunteer']);

        $this->putJson("/api/admin/volunteers/{$ana->id}", ['categories' => ['rescue', 'rescue', 'medical']])
            ->assertOk()
            ->assertJsonPath('volunteer.categories', ['rescue', 'medical']);
        $this->putJson("/api/admin/volunteers/{$ben->id}", ['categories' => ['cleaning']])->assertOk();
        $this->putJson("/api/admin/volunteers/{$ben->id}", ['categories' => ['not_a_category']])->assertStatus(422);

        $ids = collect($this->getJson('/api/admin/volunteers?category=rescue')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$ana->id], $ids);
    }

    public function test_a_hidden_category_stays_on_people_who_have_it_and_cant_be_deleted(): void
    {
        $dataAdmin = User::factory()->dataAdmin()->create();
        Sanctum::actingAs($dataAdmin);
        $person = Volunteer::create(['user_id' => User::factory()->create()->id, 'type' => 'volunteer', 'categories' => ['events']]);
        $events = CategoryOption::where('type', 'volunteer_category')->where('value', 'events')->first();

        $this->putJson("/api/admin/categories/volunteer_category/{$events->id}", ['is_active' => false])->assertOk();
        // Saving their other categories (desk work) keeps the hidden one valid.
        Sanctum::actingAs(User::factory()->deskAdmin()->create());
        $this->putJson("/api/admin/volunteers/{$person->id}", ['categories' => ['events', 'media']])->assertOk();

        Sanctum::actingAs($dataAdmin);
        $this->deleteJson("/api/admin/categories/volunteer_category/{$events->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'used by 1 person'));
    }
}
