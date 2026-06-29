<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportExportTest extends TestCase
{
    use RefreshDatabase;

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
