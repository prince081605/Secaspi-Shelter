<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Volunteer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_report_is_reachable_and_returns_only_staff_personnel(): void
    {
        // The staff report was previously unrouted dead code; it's now exposed (audit §11 A-1).
        $staffMember = User::factory()->create(['full_name' => 'Staffer One']);
        Volunteer::create(['user_id' => $staffMember->id, 'type' => 'staff', 'hours_rendered' => 12]);

        $volunteerMember = User::factory()->create(['full_name' => 'Volunteer Two']);
        Volunteer::create(['user_id' => $volunteerMember->id, 'type' => 'volunteer', 'hours_rendered' => 5]);

        Sanctum::actingAs(User::factory()->staff()->create());

        $json = $this->getJson('/api/admin/reports/staff')->assertOk()->json();

        $names = array_column($json['rows'], 'name');
        $this->assertContains('Staffer One', $names);
        $this->assertNotContains('Volunteer Two', $names, 'the staff report must not include non-staff volunteers');
    }

    public function test_csv_export_neutralizes_formula_injection(): void
    {
        // Rescue reporter fields are anonymous public input — a planted formula must not execute
        // in Excel/Sheets when staff export the report.
        DB::table('rescue_reports')->insert([
            'reporter_name' => '=HYPERLINK("http://evil.test","clickme")',
            'location' => '+SUM(1+1)',
            'urgency' => 'high',
            'status' => 'pending',
            'created_at' => now(),
        ]);

        Sanctum::actingAs(User::factory()->staff()->create());

        $csv = $this->get('/api/admin/reports/export/csv?type=rescue')
            ->assertOk()
            ->streamedContent();

        // Dangerous leading characters are prefixed with a single quote so the cell is literal.
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'+SUM", $csv);
    }
}
