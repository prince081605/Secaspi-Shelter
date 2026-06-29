<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Guards the public (unauthenticated) read surfaces against over-exposure (audit §2/§3):
 * the settings endpoint must whitelist, and the public animal payload must hide internal
 * medical detail (cost / vet_name / notes).
 */
class PublicExposureTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_settings_endpoint_whitelists_keys(): void
    {
        Setting::setMany([
            'shelter_name' => 'SECASPI',
            'ai_assistant_enabled' => '1',
            'ai_persona' => 'secret system persona',
            'ai_daily_message_cap' => '50',
            'cost_per_meal' => '25',
        ]);

        $json = $this->getJson('/api/home/settings')
            ->assertOk()
            ->assertJsonPath('shelter_name', 'SECASPI')
            ->assertJsonPath('ai_assistant_enabled', '1')
            ->json();

        $this->assertArrayNotHasKey('ai_persona', $json);
        $this->assertArrayNotHasKey('ai_daily_message_cap', $json);
        $this->assertArrayNotHasKey('cost_per_meal', $json);
    }

    public function test_public_animal_payload_hides_internal_medical_detail(): void
    {
        Storage::fake();

        $animalId = DB::table('animals')->insertGetId(['name' => 'Rex', 'species' => 'dog', 'status' => 'available', 'created_at' => now()]);
        DB::table('medical_records')->insert([
            'animal_id' => $animalId,
            'type' => 'treatment',
            'description' => 'Treated for mange',
            'vet_name' => 'Dr. Lim',
            'cost' => 5603.00,
            'notes' => 'internal note',
            'record_date' => now(),
        ]);

        $record = $this->getJson("/api/animals/{$animalId}")
            ->assertOk()
            ->json('animal.medical_records.0');

        $this->assertSame('Treated for mange', $record['description']);
        $this->assertArrayNotHasKey('cost', $record);
        $this->assertArrayNotHasKey('vet_name', $record);
        $this->assertArrayNotHasKey('notes', $record);
    }
}
