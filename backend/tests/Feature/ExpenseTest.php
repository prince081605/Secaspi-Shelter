<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The expense ledger: who may write to it, what it accepts, and the two places it surfaces —
 * the public Transparency board (donated vs. actually spent) and the admin expenses report.
 */
class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A fake receipt upload.
     *
     * `create()` with an explicit mime rather than `image()`: the latter renders a real image and
     * so needs the GD extension, which is not installed on every dev machine here. The `image`
     * validation rule checks the mime type, so this satisfies it either way.
     */
    private function receipt(string $name = 'receipt.jpg'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 100, 'image/jpeg');
    }

    /** @return array<string, mixed> A valid create payload. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'category'    => 'animal_care',
            'amount'      => 1500.50,
            'description' => 'Dog food — 3 sacks',
            'spent_at'    => now()->toDateString(),
        ], $overrides);
    }

    // ---- Authorization: staff read, admin write -------------------------------------------

    public function test_admin_can_record_an_expense(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/admin/expenses', $this->payload())
            ->assertCreated()
            ->assertJsonPath('expense.category', 'animal_care')
            ->assertJsonPath('expense.amount', 1500.5)
            ->assertJsonPath('expense.category_label', 'Animal Care / Food Expenses');

        $this->assertDatabaseHas('expenses', ['description' => 'Dog food — 3 sacks']);
    }

    public function test_staff_can_read_the_ledger_but_not_write_to_it(): void
    {
        Sanctum::actingAs(User::factory()->staff()->create());

        $this->getJson('/api/admin/expenses')->assertOk();
        $this->getJson('/api/admin/expenses/stats')->assertOk();

        $this->postJson('/api/admin/expenses', $this->payload())->assertForbidden();
    }

    public function test_volunteer_is_refused_the_ledger_entirely(): void
    {
        Sanctum::actingAs(User::factory()->volunteer()->create());

        $this->getJson('/api/admin/expenses')->assertForbidden();
        $this->postJson('/api/admin/expenses', $this->payload())->assertForbidden();
    }

    public function test_guest_is_refused(): void
    {
        $this->withHeader('Accept', 'application/json')
            ->getJson('/api/admin/expenses')
            ->assertUnauthorized();
    }

    // ---- Validation ------------------------------------------------------------------------

    public function test_category_must_be_one_of_the_donation_categories(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/admin/expenses', $this->payload(['category' => 'yacht_fund']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('category');
    }

    public function test_expense_requires_a_positive_amount(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/admin/expenses', $this->payload(['amount' => 0]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    // ---- Lifecycle -------------------------------------------------------------------------

    public function test_update_replaces_the_receipt_and_deletes_the_old_file(): void
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $created = $this->postJson('/api/admin/expenses', $this->payload([
            'receipt' => $this->receipt(),
        ]))->assertCreated();

        $id = $created->json('expense.id');
        $original = Expense::find($id)->receipt_path;
        $this->assertNotNull($original);
        Storage::assertExists($original);

        $this->post("/api/admin/expenses/{$id}", $this->payload([
            'receipt' => $this->receipt('better-receipt.jpg'),
        ]))->assertOk();

        $replacement = Expense::find($id)->receipt_path;
        $this->assertNotSame($original, $replacement);
        Storage::assertMissing($original);
        Storage::assertExists($replacement);
    }

    public function test_destroy_removes_the_row_and_its_receipt(): void
    {
        Storage::fake('local');
        Sanctum::actingAs(User::factory()->admin()->create());

        $id = $this->postJson('/api/admin/expenses', $this->payload([
            'receipt' => $this->receipt(),
        ]))->json('expense.id');

        $path = Expense::find($id)->receipt_path;

        $this->deleteJson("/api/admin/expenses/{$id}")->assertOk();

        $this->assertDatabaseMissing('expenses', ['id' => $id]);
        Storage::assertMissing($path);
    }

    public function test_index_filters_by_category_and_date_range(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        Expense::create($this->payload(['category' => 'animal_care', 'spent_at' => '2026-08-10']));
        Expense::create($this->payload(['category' => 'facility', 'spent_at' => '2026-08-10']));
        Expense::create($this->payload(['category' => 'animal_care', 'spent_at' => '2026-01-01']));

        $this->getJson('/api/admin/expenses?category=animal_care')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/admin/expenses?category=animal_care&from=2026-08-01')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ---- Stats -----------------------------------------------------------------------------

    public function test_stats_report_totals_and_a_full_category_breakdown(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        Expense::create($this->payload(['category' => 'animal_care', 'amount' => 1000]));
        Expense::create($this->payload(['category' => 'facility', 'amount' => 500]));

        $res = $this->getJson('/api/admin/expenses/stats')->assertOk();

        $res->assertJsonPath('total', 1500)
            ->assertJsonPath('count', 2)
            // Every known category is present even at zero, so the UI never null-checks a key.
            ->assertJsonCount(5, 'by_category')
            ->assertJsonPath('by_category.3.key', 'animal_care')
            ->assertJsonPath('by_category.3.total', 1000);
    }

    // ---- Public transparency ---------------------------------------------------------------

    public function test_transparency_reports_spend_alongside_the_donation_allocation(): void
    {
        $donor = User::factory()->create();

        DB::table('donations')->insert([
            ['user_id' => $donor->id, 'reference_no' => 'E1', 'amount' => 40000, 'payment_method' => 'gcash', 'category' => 'animal_care', 'status' => 'verified', 'is_anonymous' => true, 'donated_at' => now()],
        ]);

        // animal_care's goal is 35,500, so allocation caps there; we spend 7,100 of it (20%).
        Expense::create($this->payload(['category' => 'animal_care', 'amount' => 7100]));

        $res = $this->getJson('/api/home/transparency')->assertOk();

        $res->assertJsonPath('categories.3.key', 'animal_care')
            ->assertJsonPath('categories.3.allocated', 35500)
            ->assertJsonPath('categories.3.spent', 7100)
            ->assertJsonPath('categories.3.spent_pct', 20)
            ->assertJsonPath('categories.3.overspent', false)
            ->assertJsonPath('this_month_spent', 7100);
    }

    public function test_transparency_flags_spend_on_a_category_with_no_allocation(): void
    {
        // Nothing was donated to cleaning, but the shelter still bought bleach. The board must
        // report that rather than rounding it to "0% of allocated", which reads as no spend.
        Expense::create($this->payload(['category' => 'cleaning', 'amount' => 1400]));

        $res = $this->getJson('/api/home/transparency')->assertOk();

        $res->assertJsonPath('categories.4.key', 'cleaning')
            ->assertJsonPath('categories.4.allocated', 0)
            ->assertJsonPath('categories.4.spent', 1400)
            // null, not 0 — there is no allocation to express this as a share of.
            ->assertJsonPath('categories.4.spent_pct', null)
            ->assertJsonPath('categories.4.overspent', true);
    }

    public function test_transparency_reports_spend_beyond_allocation_uncapped(): void
    {
        $donor = User::factory()->create();

        DB::table('donations')->insert([
            ['user_id' => $donor->id, 'reference_no' => 'E2', 'amount' => 500, 'payment_method' => 'gcash', 'category' => 'transportation', 'status' => 'verified', 'is_anonymous' => true, 'donated_at' => now()],
        ]);

        // 1,000 spent against 500 allocated: the percentage must stay true at 200 rather than
        // being capped — the caller caps the bar, not the figure.
        Expense::create($this->payload(['category' => 'transportation', 'amount' => 1000]));

        $this->getJson('/api/home/transparency')
            ->assertOk()
            ->assertJsonPath('categories.2.key', 'transportation')
            ->assertJsonPath('categories.2.allocated', 500)
            ->assertJsonPath('categories.2.spent_pct', 200)
            ->assertJsonPath('categories.2.overspent', true);
    }

    public function test_transparency_ignores_spend_from_earlier_months(): void
    {
        Expense::create($this->payload(['amount' => 999, 'spent_at' => now()->subMonths(2)->toDateString()]));

        $this->getJson('/api/home/transparency')
            ->assertOk()
            ->assertJsonPath('this_month_spent', 0);
    }

    // ---- Report + export -------------------------------------------------------------------

    public function test_expenses_report_merges_ledger_rows_with_medical_costs(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        Expense::create($this->payload(['category' => 'facility', 'amount' => 2000, 'description' => 'Roof repair']));

        $animalId = DB::table('animals')->insertGetId([
            'name' => 'Bantay', 'species' => 'Dog', 'status' => 'available', 'created_at' => now(),
        ]);
        DB::table('medical_records')->insert([
            'animal_id' => $animalId, 'type' => 'surgery', 'description' => 'Spay',
            'cost' => 3500, 'record_date' => now()->toDateString(),
        ]);

        $res = $this->getJson('/api/admin/reports/expenses')->assertOk();

        // Both sources present, and the totals add up across them.
        $this->assertCount(2, $res->json('rows'));
        $this->assertContains('₱2,000.00', array_column($res->json('rows'), 'amount'));
        $this->assertContains('₱3,500.00', array_column($res->json('rows'), 'amount'));

        $summary = collect($res->json('summary'))->pluck('value', 'label');
        $this->assertSame('₱2,000.00', $summary['Ledger total']);
        $this->assertSame('₱3,500.00', $summary['Medical costs']);
        $this->assertSame('₱5,500.00', $summary['Total spent']);
    }

    public function test_expenses_report_and_export_are_admin_only(): void
    {
        Sanctum::actingAs(User::factory()->staff()->create());

        $this->getJson('/api/admin/reports/expenses')->assertForbidden();

        // The export route itself is staff-accessible, so the financial block has to happen
        // inside resolveData() — the same guard that protects the donations export.
        $this->get('/api/admin/reports/export/csv?type=expenses')->assertForbidden();
    }

    public function test_expenses_export_produces_csv_and_pdf_for_an_admin(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        Expense::create($this->payload(['description' => 'Dog food']));

        $csv = $this->get('/api/admin/reports/export/csv?type=expenses')->assertOk();
        $this->assertStringContainsString('Dog food', $csv->streamedContent());

        $this->get('/api/admin/reports/export/pdf?type=expenses')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
}
