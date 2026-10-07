<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\ShelterLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

/**
 * Bulk animal import from Excel: an admin downloads the template, uploads a filled copy, previews
 * every row's problems, and imports the rows that pass the same rules as the Add Animal form —
 * without ever touching an existing animal.
 */
class AnimalImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['Name *', 'Species *', 'Breed', 'Age (years)', 'Gender', 'Size', 'Weight (kg)', 'Status', 'Shelter Area', 'Behavioral Issues', 'Rescue Story'];

    private const KEYS = ['name', 'species', 'breed', 'age', 'gender', 'size', 'weight', 'status', 'area', 'behavior', 'story'];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        Sanctum::actingAs($this->admin);
    }

    /** A template-shaped row from named fields; anything unnamed is left blank. */
    private function row(array $fields): array
    {
        return array_map(fn ($key) => $fields[$key] ?? null, self::KEYS);
    }

    /** An .xlsx upload whose Animals sheet holds $rows under $headers. */
    private function xlsx(array $rows, ?array $headers = self::HEADERS, string $sheet = 'Animals'): UploadedFile
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle($sheet)
            ->fromArray($headers === null ? $rows : [$headers, ...$rows], null, 'A1', true);

        $path = tempnam(sys_get_temp_dir(), 'animal-import').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'animals.xlsx', null, null, true);
    }

    private function preview(UploadedFile $file): TestResponse
    {
        return $this->withHeaders(['Accept' => 'application/json'])
            ->post('/api/admin/animals/import/preview', ['file' => $file]);
    }

    private function import(UploadedFile $file, array $allowDuplicates = []): TestResponse
    {
        return $this->withHeaders(['Accept' => 'application/json'])
            ->post('/api/admin/animals/import', ['file' => $file, 'allow_duplicates' => $allowDuplicates]);
    }

    private function existingAnimal(string $name, string $species, array $extra = []): int
    {
        return DB::table('animals')->insertGetId([
            'name' => $name, 'species' => $species, 'status' => 'available', 'created_at' => now(), ...$extra,
        ]);
    }

    /** row number => that row's problems, from a preview or a refused import. */
    private function errorsByRow(TestResponse $response): array
    {
        return collect($response->json('rows'))->mapWithKeys(fn ($r) => [$r['row'] => $r['errors']])->all();
    }

    public function test_the_template_has_the_animal_columns_dropdowns_and_the_shelters_areas(): void
    {
        $response = $this->get('/api/admin/animals/import/template')->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('Content-Type'));

        $path = tempnam(sys_get_temp_dir(), 'template').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $book = IOFactory::load($path);

        $this->assertSame(['Instructions', 'Animals', 'Example', 'Allowed Values'], $book->getSheetNames());

        $animals = $book->getSheetByName('Animals');
        $this->assertSame(self::HEADERS, $animals->rangeToArray('A1:K1')[0]);
        $below = array_merge(...$animals->rangeToArray('A2:K'.max(2, $animals->getHighestRow())));
        $this->assertSame([], array_filter($below, fn ($v) => $v !== null), 'the Animals sheet starts empty');
        $this->assertArrayHasKey('B2:B1001', $animals->getDataValidationCollection(), 'Species has a dropdown');
        $this->assertArrayHasKey('I2:I1001', $animals->getDataValidationCollection(), 'Shelter Area has a dropdown');

        $allowed = $book->getSheetByName('Allowed Values')->toArray();
        $this->assertContains('Kennel 1', array_column($allowed, 4));
        $this->assertContains('excessive barking', array_column($allowed, 5));

        // Breed suggests the common dog and cat breeds, but still takes any breed typed in.
        $this->assertSame('Common Breeds', $allowed[0][6]);
        $this->assertContains('Aspin', array_column($allowed, 6));
        $this->assertContains('Puspin', array_column($allowed, 6));
        $breed = $animals->getDataValidationCollection()['C2:C1001'] ?? null;
        $this->assertNotNull($breed, 'Breed has a dropdown');
        $this->assertFalse($breed->getShowErrorMessage(), 'an unlisted breed is still accepted');
    }

    public function test_the_templates_own_example_rows_pass_the_check(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'template').'.xlsx';
        file_put_contents($path, $this->get('/api/admin/animals/import/template')->streamedContent());
        $example = IOFactory::load($path)->getSheetByName('Example')->toArray(null, true, false);

        $this->preview($this->xlsx($example, null))->assertOk()
            ->assertJsonPath('summary', ['total' => 3, 'ready' => 3, 'errors' => 0, 'duplicates' => 0]);
    }

    public function test_a_valid_file_is_previewed_without_saving_then_imported(): void
    {
        $kennel = ShelterLocation::where('name', 'Kennel 1')->firstOrFail();
        $file = $this->xlsx([
            $this->row(['name' => 'Brownie', 'species' => 'DOG', 'breed' => 'Aspin', 'age' => 3.0, 'gender' => 'Male', 'size' => 'medium',
                'weight' => 12.5, 'area' => 'kennel 1', 'behavior' => 'Excessive barking, pulling on leash', 'story' => 'Found near the market.']),
            $this->row(['name' => ' Mingming ', 'species' => ' Cat ', 'status' => 'Medical']),
            $this->row(['name' => 'Bantay', 'species' => 'dog', 'age' => 0]),
        ]);

        $this->preview($file)->assertOk()
            ->assertJsonPath('summary', ['total' => 3, 'ready' => 3, 'errors' => 0, 'duplicates' => 0])
            ->assertJsonPath('rows.0.row', 2)
            ->assertJsonPath('rows.0.status', 'ready')
            ->assertJsonPath('rows.0.values.species', 'Dog')
            ->assertJsonPath('rows.1.values.status', 'Medical')
            ->assertJsonPath('rows.2.values.status', 'Available');
        $this->assertDatabaseCount('animals', 0);

        $this->import($file)->assertCreated()
            ->assertJsonPath('summary', ['processed' => 3, 'imported' => 3, 'rejected' => 0, 'skipped_duplicates' => 0])
            ->assertJsonPath('imported.1.name', 'Mingming');

        $brownie = Animal::where('name', 'Brownie')->firstOrFail();
        $this->assertSame('dog', $brownie->species);
        $this->assertSame('male', $brownie->gender);
        $this->assertSame('medium', $brownie->size);
        $this->assertSame(3, (int) $brownie->age);
        $this->assertSame('available', $brownie->status);
        $this->assertSame(['excessive barking', 'pulling on leash'], $brownie->behavioral_assessment);
        $this->assertSame($kennel->id, (int) $brownie->current_location_id);
        $this->assertDatabaseHas('animal_location_logs', [
            'animal_id' => $brownie->id, 'from_location_id' => null, 'to_location_id' => $kennel->id,
            'moved_by' => $this->admin->id, 'source' => 'admin', 'note' => 'Placed by Excel import',
        ]);

        $this->assertDatabaseHas('animals', ['name' => 'Mingming', 'species' => 'cat', 'status' => 'medical', 'current_location_id' => null]);
        $this->assertDatabaseCount('animal_location_logs', 1);
    }

    public function test_rows_missing_required_fields_are_rejected_and_the_rest_still_imported(): void
    {
        $file = $this->xlsx([
            $this->row(['species' => 'dog', 'breed' => 'Aspin']),
            $this->row(['name' => 'Bantay']),
            $this->row(['name' => 'Whitey', 'species' => 'cat']),
        ]);

        $preview = $this->preview($file)->assertOk()
            ->assertJsonPath('summary', ['total' => 3, 'ready' => 1, 'errors' => 2, 'duplicates' => 0]);
        $this->assertSame([2 => ['Name is required.'], 3 => ['Species is required. Use Dog or Cat.'], 4 => []], $this->errorsByRow($preview));

        $this->import($file)->assertCreated()
            ->assertJsonPath('summary.imported', 1)
            ->assertJsonPath('summary.rejected', 2)
            ->assertJsonPath('rejected.0.row', 2)
            ->assertJsonPath('rejected.1.errors.0', 'Species is required. Use Dog or Cat.');
        $this->assertSame(['Whitey'], Animal::pluck('name')->all());
    }

    public function test_invalid_values_are_reported_against_their_row_and_never_saved(): void
    {
        $file = $this->xlsx([
            $this->row(['name' => 'Tweety', 'species' => 'Bird']),
            $this->row(['name' => 'Rex', 'species' => 'dog', 'gender' => 'Boy', 'size' => 'Huge']),
            $this->row(['name' => 'Choco', 'species' => 'dog', 'age' => '3 yrs', 'weight' => 'heavy']),
            $this->row(['name' => 'Snow', 'species' => 'cat', 'age' => -1, 'weight' => -2]),
            $this->row(['name' => 'Lucky', 'species' => 'dog', 'status' => 'Missing']),
            $this->row(['name' => 'Blacky', 'species' => 'dog', 'area' => 'Kennel 9']),
            $this->row(['name' => 'Spot', 'species' => 'dog', 'behavior' => 'extreme shyness, barks a lot']),
            $this->row(['name' => 'Tiny', 'species' => 'cat', 'age' => 2.5, 'weight' => 5000000]),
            $this->row(['name' => 'Goldie', 'species' => 'dog']),
        ]);

        $errors = $this->errorsByRow($this->preview($file)->assertOk()
            ->assertJsonPath('summary', ['total' => 9, 'ready' => 1, 'errors' => 8, 'duplicates' => 0]));

        $this->assertSame(['Species "Bird" is not valid. Use Dog or Cat.'], $errors[2]);
        $this->assertSame(['Gender "Boy" is not valid. Use Male or Female.', 'Size "Huge" is not valid. Use Small, Medium or Large.'], $errors[3]);
        $this->assertSame(['Age "3 yrs" must be a whole number of years, e.g. 3.', 'Weight "heavy" must be a number of kilograms, e.g. 12.5.'], $errors[4]);
        $this->assertSame(['Age cannot be negative.', 'Weight cannot be negative.'], $errors[5]);
        $this->assertStringStartsWith('Status "Missing" is not valid. Use Available, Adopted, Fostered', $errors[6][0]);
        $this->assertStringStartsWith('Shelter area "Kennel 9" does not exist. Use one of: ', $errors[7][0]);
        $this->assertSame(['Behavioral issue "barks a lot" is not on the list. Use the exact wording from the Allowed Values sheet.'], $errors[8]);
        $this->assertSame(['Age "2.5" must be a whole number of years, e.g. 3.', 'Weight "5000000" is too large to be a weight in kilograms.'], $errors[9]);
        $this->assertSame([], $errors[10]);

        $this->import($file)->assertCreated()->assertJsonPath('summary.imported', 1)->assertJsonPath('summary.rejected', 8);
        $this->assertSame(['Goldie'], Animal::pluck('name')->all());
    }

    public function test_cells_holding_excel_errors_or_true_false_are_reported(): void
    {
        $file = $this->xlsx([
            $this->row(['name' => 'Choco', 'species' => 'dog', 'age' => '=1/0']),
            $this->row(['name' => 'Snow', 'species' => true]),
        ]);

        $errors = $this->errorsByRow($this->preview($file)->assertOk());
        $this->assertSame(['Age (years) contains the Excel error #DIV/0!.'], $errors[2]);
        $this->assertSame(['Species contains TRUE/FALSE, which is not a valid value.'], $errors[3]);
    }

    public function test_duplicates_are_reported_and_skipped_and_the_existing_animal_is_left_alone(): void
    {
        $brownie = $this->existingAnimal('Brownie', 'dog', ['breed' => 'Original', 'status' => 'adopted']);
        $file = $this->xlsx([
            $this->row(['name' => 'brownie', 'species' => 'Dog', 'breed' => 'Changed']),
            $this->row(['name' => 'Mingming', 'species' => 'cat']),
            $this->row(['name' => 'MINGMING', 'species' => 'cat']),
            $this->row(['name' => 'Brownie', 'species' => 'cat']),
        ]);

        $this->preview($file)->assertOk()
            ->assertJsonPath('summary', ['total' => 4, 'ready' => 2, 'errors' => 0, 'duplicates' => 2])
            ->assertJsonPath('rows.0.status', 'duplicate')
            ->assertJsonPath('rows.0.duplicate_of.id', $brownie)
            ->assertJsonPath('rows.0.duplicate_of.message', "Brownie (dog, #{$brownie}, adopted) is already in the system.")
            ->assertJsonPath('rows.2.status', 'duplicate')
            ->assertJsonPath('rows.2.duplicate_of.row', 3)
            ->assertJsonPath('rows.3.status', 'ready');

        $this->import($file)->assertCreated()
            ->assertJsonPath('summary', ['processed' => 4, 'imported' => 2, 'rejected' => 0, 'skipped_duplicates' => 2])
            ->assertJsonPath('skipped.1.reason', 'Same name and species as row 3 of this file.');

        $this->assertDatabaseCount('animals', 3);
        $this->assertDatabaseHas('animals', ['id' => $brownie, 'breed' => 'Original', 'status' => 'adopted']);
        $this->assertSame(1, Animal::whereRaw('LOWER(name) = ?', ['mingming'])->count());
    }

    public function test_a_duplicate_the_admin_ticks_is_added_as_a_new_animal(): void
    {
        $brownie = $this->existingAnimal('Brownie', 'dog', ['breed' => 'Original']);
        $file = $this->xlsx([
            $this->row(['name' => 'Brownie', 'species' => 'dog', 'breed' => 'Aspin']),
            $this->row(['name' => 'Brownie', 'species' => 'dog', 'breed' => 'Mix']),
        ]);

        $this->import($file, [2])->assertCreated()
            ->assertJsonPath('summary', ['processed' => 2, 'imported' => 1, 'rejected' => 0, 'skipped_duplicates' => 1]);

        $this->assertDatabaseHas('animals', ['id' => $brownie, 'breed' => 'Original']);
        $this->assertDatabaseHas('animals', ['name' => 'Brownie', 'breed' => 'Aspin']);
        $this->assertDatabaseMissing('animals', ['breed' => 'Mix']);
    }

    public function test_the_import_checks_the_file_again_rather_than_trusting_the_preview(): void
    {
        $file = $this->xlsx([
            $this->row(['name' => 'Brownie', 'species' => 'dog']),
            $this->row(['name' => 'Whitey', 'species' => 'cat']),
        ]);
        $this->preview($file)->assertOk()->assertJsonPath('summary.ready', 2);

        // Someone adds Brownie by hand between the preview and the import.
        $this->existingAnimal('Brownie', 'dog');

        $this->import($file)->assertCreated()
            ->assertJsonPath('summary.imported', 1)
            ->assertJsonPath('summary.skipped_duplicates', 1);
        $this->assertSame(1, Animal::where('name', 'Brownie')->count());
    }

    public function test_nothing_is_imported_when_no_row_is_ready(): void
    {
        $this->existingAnimal('Brownie', 'dog');
        $file = $this->xlsx([
            $this->row(['name' => 'Brownie', 'species' => 'dog']),
            $this->row(['name' => 'Tweety', 'species' => 'bird']),
        ]);

        $this->import($file)->assertStatus(422)
            ->assertJsonPath('message', 'No rows are ready to import. Fix the problems listed for each row and upload the file again.')
            ->assertJsonPath('summary.duplicates', 1);
        $this->assertDatabaseCount('animals', 1);
    }

    public function test_an_empty_file_is_refused(): void
    {
        $this->preview($this->xlsx([]))->assertStatus(422)
            ->assertJsonPath('message', 'There are no animals in this file. Fill in at least one row under the headers on the "Animals" sheet, then upload it again.');

        $this->preview($this->xlsx([['   ', null], [null, '']]))->assertStatus(422)
            ->assertJsonPath('message', 'There are no animals in this file. Fill in at least one row under the headers on the "Animals" sheet, then upload it again.');

        $this->preview($this->xlsx([], null))->assertStatus(422)
            ->assertJsonPath('message', 'This file is empty. Download the template, fill in your animals on the "Animals" sheet, and upload it again.');

        $this->assertDatabaseCount('animals', 0);
    }

    public function test_files_that_are_not_an_xlsx_or_not_the_template_are_refused(): void
    {
        $this->preview(UploadedFile::fake()->createWithContent('animals.csv', "Name,Species\nBrownie,Dog"))
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_starts_with($m, 'Only Excel workbooks (.xlsx) can be imported.'));

        $this->preview(UploadedFile::fake()->createWithContent('animals.xlsx', 'this is not a spreadsheet'))
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_starts_with($m, 'This file could not be opened as an Excel workbook (.xlsx).'));

        $this->preview($this->xlsx([['Brownie', 'Dog']], ['Animal', 'Type']))
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'the "Name" and "Species" columns are missing'));

        $this->withHeaders(['Accept' => 'application/json'])->post('/api/admin/animals/import/preview', [])
            ->assertStatus(422)->assertJsonPath('message', 'Choose the Excel file (.xlsx) to import.');

        $this->assertDatabaseCount('animals', 0);
    }

    public function test_columns_are_matched_by_header_and_unknown_ones_are_ignored_with_a_warning(): void
    {
        $file = $this->xlsx([['Dog', 'Brownie', 'Brown', 'female']], ['species', 'Animal Name', 'Color', 'Sex']);

        $this->preview($file)->assertOk()
            ->assertJsonPath('summary.ready', 1)
            ->assertJsonPath('warnings', ['The "Color" column isn\'t part of the template, so it was ignored.']);

        $this->import($file)->assertCreated();
        $this->assertDatabaseHas('animals', ['name' => 'Brownie', 'species' => 'dog', 'gender' => 'female']);
    }

    public function test_the_first_sheet_is_read_when_there_is_no_animals_sheet(): void
    {
        $this->preview($this->xlsx([$this->row(['name' => 'Brownie', 'species' => 'dog'])], sheet: 'Sheet1'))
            ->assertOk()->assertJsonPath('summary.ready', 1);
    }

    public function test_a_large_batch_imports_and_one_over_the_limit_is_refused(): void
    {
        $rows = array_map(fn ($i) => $this->row([
            'name' => "Batch {$i}", 'species' => $i % 2 ? 'cat' : 'dog', 'age' => $i % 15, 'weight' => 2 + $i % 20, 'size' => 'small',
        ]), range(1, 1000));

        $this->import($this->xlsx($rows))->assertCreated()
            ->assertJsonPath('summary', ['processed' => 1000, 'imported' => 1000, 'rejected' => 0, 'skipped_duplicates' => 0]);
        $this->assertDatabaseCount('animals', 1000);

        $rows[] = $this->row(['name' => 'One too many', 'species' => 'dog']);
        $this->preview($this->xlsx($rows))->assertStatus(422)
            ->assertJsonPath('message', 'This file has more than 1,000 animals. One file can hold at most 1,000 — split it into smaller files and import them one at a time.');
    }

    public function test_a_database_failure_part_way_through_saves_nothing(): void
    {
        Exceptions::fake();
        Animal::creating(function (Animal $animal) {
            if ($animal->name === 'Breaks') {
                throw new RuntimeException('Simulated database failure');
            }
        });

        $file = $this->xlsx([
            $this->row(['name' => 'First', 'species' => 'dog', 'area' => 'Kennel 1']),
            $this->row(['name' => 'Breaks', 'species' => 'dog']),
            $this->row(['name' => 'Third', 'species' => 'cat']),
        ]);

        $this->import($file)->assertStatus(500)
            ->assertJsonPath('message', 'The import could not be saved, so no animals were added. Please try again.');

        $this->assertDatabaseCount('animals', 0);
        $this->assertDatabaseCount('animal_location_logs', 0);
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_only_admins_can_use_the_import(): void
    {
        $file = $this->xlsx([$this->row(['name' => 'Brownie', 'species' => 'dog'])]);

        Sanctum::actingAs(User::factory()->staff()->create());
        $this->getJson('/api/admin/animals/import/template')->assertForbidden();
        $this->preview($file)->assertForbidden();
        $this->import($file)->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Accept' => 'application/json', 'Authorization' => ''])
            ->post('/api/admin/animals/import', ['file' => $file])->assertUnauthorized();

        $this->assertDatabaseCount('animals', 0);
    }
}
