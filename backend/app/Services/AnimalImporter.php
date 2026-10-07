<?php

namespace App\Services;

use App\Exceptions\AnimalImportException;
use App\Models\Animal;
use App\Models\AnimalLocationLog;
use App\Models\CategoryOption;
use App\Models\ShelterLocation;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Bulk-adds animals from an Excel workbook: builds the template, reads an uploaded copy back,
 * checks every row against the same rules as the Add Animal form (Animal::rules()), and creates
 * the rows that pass.
 *
 * It only ever inserts. An existing animal is never updated or deleted — a row that looks like one
 * already on file is reported as a possible duplicate and skipped unless the admin ticks it.
 */
class AnimalImporter
{
    public const MAX_ROWS = 1000;

    /** The sheet animals are read from; the first sheet is used if a file has none by this name. */
    public const SHEET = 'Animals';

    /** Blank rows tolerated between animals before a sheet counts as too long to read. */
    private const BLANK_ROW_ALLOWANCE = 500;

    /** Columns read at most — the template has 11; anything past this is ignored unread. */
    private const MAX_COLUMNS = 30;

    /** Template rows that get dropdowns (the header is row 1). */
    private const TEMPLATE_LAST_ROW = self::MAX_ROWS + 1;

    /**
     * The template's columns, in order: key => [header, has dropdown, width, note]. Headers are
     * matched leniently on upload (see columnKey()), so a returned file may reorder them.
     */
    private const COLUMNS = [
        'name' => ['Name *', false, 22, "Required. The animal's name."],
        'species' => ['Species *', true, 12, 'Required. Dog or Cat.'],
        'breed' => ['Breed', false, 18, 'Optional. Pick a common breed from the list, or type any breed, e.g. Shih Tzu mix.'],
        'age' => ['Age (years)', false, 12, 'Optional. Whole years, e.g. 3. Use 0 for under a year.'],
        'gender' => ['Gender', true, 11, 'Optional. Male or Female.'],
        'size' => ['Size', true, 11, 'Optional. Small, Medium or Large.'],
        'weight' => ['Weight (kg)', false, 12, 'Optional. Kilograms, e.g. 12.5.'],
        'status' => ['Status', true, 14, 'Optional. Leave blank for Available.'],
        'location' => ['Shelter Area', true, 16, 'Optional. The area of the shelter the animal is kept in. Leave blank if not assigned yet.'],
        'behavioral_assessment' => ['Behavioral Issues', false, 40, 'Optional. One or more issues from the Allowed Values sheet, separated by commas.'],
        'rescue_story' => ['Rescue Story', false, 50, 'Optional. How the animal came to the shelter.'],
    ];

    /** Other header spellings accepted on upload, as normalised by columnKey(). */
    private const HEADER_ALIASES = [
        'animal name' => 'name',
        'sex' => 'gender',
        'animal status' => 'status',
        'area' => 'location',
        'location' => 'location',
        'shelter location' => 'location',
        'behavioural issues' => 'behavioral_assessment',
        'behavior' => 'behavioral_assessment',
        'behaviour' => 'behavioral_assessment',
        'story' => 'rescue_story',
        'description' => 'rescue_story',
    ];

    private const REQUIRED_COLUMNS = ['name', 'species'];

    private const NOT_XLSX = 'This file could not be opened as an Excel workbook (.xlsx). Download the template, copy your animals into it, and upload that file.';

    /**
     * A fresh template: Instructions, the empty Animals sheet (with dropdowns), a filled-in
     * Example, and the Allowed Values the dropdowns draw from — including the shelter's current
     * areas, which is why it's built per request rather than shipped as a static file.
     */
    public function template(): Spreadsheet
    {
        $areas = ShelterLocation::orderBy('name')->pluck('name')->all();
        $lists = [
            'species' => array_map('ucfirst', Animal::SPECIES),
            'gender' => array_map('ucfirst', Animal::GENDERS),
            'size' => array_map('ucfirst', Animal::SIZES),
            'status' => array_map('ucfirst', Animal::STATUSES),
            'location' => $areas,
        ];

        $book = new Spreadsheet;
        $book->getProperties()->setCreator('SECASPI Shelter')->setTitle('SECASPI animal import template');

        $this->writeInstructions($book->getActiveSheet()->setTitle('Instructions'));

        $animals = $book->createSheet()->setTitle(self::SHEET);
        $this->writeHeader($animals);
        $breeds = $this->commonBreeds();
        $this->addDropdowns($animals, $lists, $breeds);

        $example = $book->createSheet()->setTitle('Example');
        $this->writeHeader($example);
        $this->writeExamples($example, $areas);

        $this->writeAllowedValues($book->createSheet()->setTitle('Allowed Values'), $lists, $breeds);

        $book->setActiveSheetIndex(0);

        return $book;
    }

    /**
     * Read the uploaded workbook's Animals sheet: each non-blank row's cell values keyed by
     * column, plus per-cell problems (an Excel error, a TRUE/FALSE) and warnings about columns
     * that were ignored.
     *
     * @return array{rows: array<int, array{values: array<string, mixed>, cell_errors: array<string, string>}>, warnings: array<int, string>}
     *
     * @throws AnimalImportException when the file as a whole can't be used
     */
    public function read(UploadedFile $file): array
    {
        $path = (string) $file->getRealPath();
        $reader = new XlsxReader;

        try {
            $sheets = $path !== '' && $reader->canRead($path) ? $reader->listWorksheetInfo($path) : [];
        } catch (Throwable) {
            $sheets = [];
        }

        $info = collect($sheets)->first(fn ($s) => strcasecmp(trim($s['worksheetName']), self::SHEET) === 0) ?? ($sheets[0] ?? null);
        if (! $info) {
            throw new AnimalImportException(self::NOT_XLSX);
        }

        // Rows past this point aren't read at all, so a sheet that long is refused outright
        // rather than silently cut short.
        $lastReadableRow = 1 + self::MAX_ROWS + self::BLANK_ROW_ALLOWANCE;
        if ((int) $info['totalRows'] > $lastReadableRow) {
            throw new AnimalImportException(sprintf(
                'The "%s" sheet goes down to row %s, but one file can hold at most %s animals. Split it into smaller files (and delete any empty formatted rows below your animals).',
                $info['worksheetName'], number_format((int) $info['totalRows']), number_format(self::MAX_ROWS),
            ));
        }

        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $reader->setLoadSheetsOnly([$info['worksheetName']]);
        $reader->setReadFilter(new class($lastReadableRow, self::MAX_COLUMNS) implements IReadFilter
        {
            public function __construct(private int $lastRow, private int $lastColumn) {}

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= $this->lastRow && Coordinate::columnIndexFromString($columnAddress) <= $this->lastColumn;
            }
        });

        try {
            $book = $reader->load($path);
        } catch (Throwable) {
            throw new AnimalImportException(self::NOT_XLSX);
        }

        try {
            return $this->readSheet($book->getSheet(0));
        } finally {
            $book->disconnectWorksheets();
        }
    }

    /**
     * Check every row read from the file. A row is "ready" (will be imported), "error" (has
     * problems, listed in plain words) or "duplicate" (same name and species as an animal already
     * on file or an earlier row of the file — skipped unless its row number is in $allowDuplicates).
     *
     * Returns `rows` to show the admin, `insert` (row number => attributes) for import() to save,
     * and the counts.
     *
     * @param  array<int, array{values: array<string, mixed>, cell_errors: array<string, string>}>  $rows  from read()
     * @param  array<int|string>  $allowDuplicates  row numbers to import even though they look like duplicates
     * @return array{rows: array<int, array<string, mixed>>, insert: array<int, array<string, mixed>>, summary: array<string, int>}
     */
    public function validate(array $rows, array $allowDuplicates = []): array
    {
        $areas = ShelterLocation::all(['id', 'name'])->keyBy(fn (ShelterLocation $l) => mb_strtolower(trim($l->name)));
        $issues = collect(CategoryOption::activeValues('behavioral_issue'))->keyBy(fn (string $issue) => mb_strtolower($issue));

        $checked = [];
        foreach ($rows as $rowNumber => $row) {
            $checked[$rowNumber] = $this->checkRow($row['values'], $row['cell_errors'], $areas, $issues);
        }

        $existing = $this->existingAnimals(array_filter($checked, fn ($c) => ! $c['errors']));
        $allowed = array_flip(array_map('intval', $allowDuplicates));
        $firstRowFor = []; // duplicate key => the first row of the file that will be imported under it
        $result = [];
        $insert = [];

        foreach ($checked as $rowNumber => $c) {
            $entry = ['row' => $rowNumber, 'status' => 'error', 'values' => $c['display'], 'errors' => $c['errors'], 'duplicate_of' => null];

            if (! $c['errors']) {
                $key = $this->duplicateKey($c['data']['name'], $c['data']['species']);

                if ($match = $existing[$key] ?? null) {
                    $entry['duplicate_of'] = [
                        'id' => $match->id,
                        'row' => null,
                        'message' => sprintf('%s (%s, #%d, %s) is already in the system.', $match->name, $match->species, $match->id, $match->status),
                    ];
                } elseif (isset($firstRowFor[$key])) {
                    $entry['duplicate_of'] = [
                        'id' => null,
                        'row' => $firstRowFor[$key],
                        'message' => "Same name and species as row {$firstRowFor[$key]} of this file.",
                    ];
                }

                if ($entry['duplicate_of'] && ! isset($allowed[$rowNumber])) {
                    $entry['status'] = 'duplicate';
                } else {
                    $entry['status'] = 'ready';
                    $insert[$rowNumber] = $c['data'];
                    $firstRowFor[$key] ??= $rowNumber;
                }
            }

            $result[] = $entry;
        }

        $count = fn (string $status) => count(array_filter($result, fn ($r) => $r['status'] === $status));

        return [
            'rows' => $result,
            'insert' => $insert,
            'summary' => [
                'total' => count($result),
                'ready' => $count('ready'),
                'errors' => $count('error'),
                'duplicates' => $count('duplicate'),
            ],
        ];
    }

    /**
     * Save the rows validate() passed, in one transaction: if the database refuses any of them,
     * none are saved, so a failed import never leaves half a batch behind.
     *
     * @param  array<int, array<string, mixed>>  $insert  row number => attributes, from validate()
     * @return array<int, array{row: int, id: int, name: string}>
     */
    public function import(array $insert, User $by): array
    {
        return DB::transaction(function () use ($insert, $by) {
            $created = [];

            foreach ($insert as $rowNumber => $attributes) {
                $locationId = $attributes['location_id'] ?? null;
                unset($attributes['location_id']);

                $animal = Animal::create($attributes);

                if ($locationId) {
                    AnimalLocationLog::record($animal, $locationId, $by, 'admin', 'Placed by Excel import');
                }

                $created[] = ['row' => $rowNumber, 'id' => $animal->id, 'name' => $animal->name];
            }

            return $created;
        });
    }

    /** @return array{rows: array, warnings: array<int, string>} */
    private function readSheet(Worksheet $sheet): array
    {
        $columns = []; // column index => field key
        $warnings = [];
        $headers = 0;
        $lastColumn = min(Coordinate::columnIndexFromString($sheet->getHighestDataColumn()), self::MAX_COLUMNS);

        for ($col = 1; $col <= $lastColumn; $col++) {
            [$header] = $this->cellValue($sheet, $col, 1);
            if ($header === null) {
                continue;
            }
            $headers++;

            $header = (string) $header;
            $key = $this->columnKey($header);

            if ($key === null) {
                $warnings[] = "The \"{$header}\" column isn't part of the template, so it was ignored.";
            } elseif (in_array($key, $columns, true)) {
                $warnings[] = "There is more than one \"{$header}\" column; only the first one was used.";
            } else {
                $columns[$col] = $key;
            }
        }

        if ($headers === 0 && $sheet->getHighestDataRow() <= 1) {
            throw new AnimalImportException('This file is empty. Download the template, fill in your animals on the "'.self::SHEET.'" sheet, and upload it again.');
        }

        $missing = array_diff(self::REQUIRED_COLUMNS, $columns);
        if ($missing) {
            $labels = array_map(fn ($key) => '"'.$this->label($key).'"', $missing);
            throw new AnimalImportException(
                "This file doesn't look like the SECASPI animal template: the first row must hold the column headers, and the "
                .implode(' and ', $labels).' column'.(count($missing) > 1 ? 's are' : ' is')
                .' missing. Download the template and copy your animals into it.'
            );
        }

        $rows = [];
        $lastRow = $sheet->getHighestDataRow();

        for ($row = 2; $row <= $lastRow; $row++) {
            $values = [];
            $cellErrors = [];

            foreach ($columns as $col => $key) {
                [$value, $problem] = $this->cellValue($sheet, $col, $row);
                $values[$key] = $value;
                if ($problem) {
                    $cellErrors[$key] = $problem;
                }
            }

            if (! $cellErrors && ! array_filter($values, fn ($v) => $v !== null)) {
                continue; // a blank row
            }

            if (count($rows) === self::MAX_ROWS) {
                throw new AnimalImportException(sprintf(
                    'This file has more than %s animals. One file can hold at most %1$s — split it into smaller files and import them one at a time.',
                    number_format(self::MAX_ROWS),
                ));
            }

            $rows[$row] = ['values' => $values, 'cell_errors' => $cellErrors];
        }

        if (! $rows) {
            throw new AnimalImportException(
                'There are no animals in this file. Fill in at least one row under the headers on the "'.self::SHEET.'" sheet, then upload it again.'
            );
        }

        return ['rows' => $rows, 'warnings' => $warnings];
    }

    /**
     * A cell's usable value — text trimmed (blank becomes null), numbers as numbers — or, if the
     * cell can't be used, a description of why (completing the sentence "<Column> ...").
     *
     * @return array{0: mixed, 1: ?string}
     */
    private function cellValue(Worksheet $sheet, int $col, int $row): array
    {
        if (! $sheet->cellExists([$col, $row])) {
            return [null, null];
        }

        $cell = $sheet->getCell([$col, $row]);
        $value = $cell->getValue();

        // Use the result Excel saved with the file; only work a formula out if there is none.
        if ($cell->isFormula()) {
            $value = $cell->getOldCalculatedValue();
            if ($value === null) {
                try {
                    $value = $cell->getCalculatedValue();
                } catch (Throwable) {
                    return [null, 'has a formula that could not be worked out — type the value in instead'];
                }
            }
        }

        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }

        if (is_bool($value)) {
            return [null, 'contains TRUE/FALSE, which is not a valid value'];
        }

        if (is_string($value)) {
            if (preg_match('~^#(NULL!|DIV/0!|VALUE!|REF!|NAME\?|NUM!|N/A|GETTING_DATA|SPILL!|CALC!)$~', $value)) {
                return [null, "contains the Excel error {$value}"];
            }

            // Includes non-breaking spaces, which text pasted from the web often carries.
            $value = preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $value) ?? trim($value);

            return [$value === '' ? null : $value, null];
        }

        return [is_int($value) || is_float($value) ? $value : null, null];
    }

    /**
     * Normalise one row the way the Add Animal form's values arrive (choices lowercase, blank
     * status = available), resolve its area and behavioral issues, and run it through
     * Animal::rules().
     *
     * @return array{data: array<string, mixed>, display: array<string, mixed>, errors: array<int, string>}
     */
    private function checkRow(array $values, array $cellErrors, Collection $areas, Collection $issues): array
    {
        $raw = array_merge(array_fill_keys(array_keys(self::COLUMNS), null), $values);
        $errors = []; // column key => messages

        foreach ($cellErrors as $key => $problem) {
            $errors[$key][] = $this->label($key).' '.$problem.'.';
        }

        $age = $raw['age'];
        if (is_float($age) && floor($age) === $age) {
            $age = (int) $age; // Excel stores every number as a float; 3.0 years is 3.
        }

        $data = [
            'name' => $this->text($raw['name']),
            'species' => $this->choice($raw['species']),
            'breed' => $this->text($raw['breed']),
            'age' => $age,
            'gender' => $this->choice($raw['gender']),
            'size' => $this->choice($raw['size']),
            'weight' => $raw['weight'],
            'status' => $this->choice($raw['status']) ?? 'available',
            'rescue_story' => $this->text($raw['rescue_story']),
            'behavioral_assessment' => null,
        ];

        if (($list = $this->text($raw['behavioral_assessment'])) !== null) {
            $picked = [];
            foreach (preg_split('/[,;\n]+/', $list) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                if ($known = $issues[mb_strtolower($part)] ?? null) {
                    $picked[$known] = $known;
                } else {
                    $errors['behavioral_assessment'][] = "Behavioral issue \"{$this->quote($part)}\" is not on the list. Use the exact wording from the Allowed Values sheet.";
                }
            }
            $data['behavioral_assessment'] = $picked ? array_values($picked) : null;
        }

        $locationId = null;
        if (($areaName = $this->text($raw['location'])) !== null) {
            if ($area = $areas[mb_strtolower($areaName)] ?? null) {
                $locationId = $area->id;
            } else {
                $errors['location'][] = "Shelter area \"{$this->quote($areaName)}\" does not exist. ".($areas->isEmpty()
                    ? 'No shelter areas are set up yet, so leave it blank.'
                    : 'Use one of: '.$areas->pluck('name')->join(', ').' — or leave it blank.');
            }
        }

        $validator = Validator::make($data, Animal::rules(), $this->messages($raw));
        foreach ($validator->errors()->messages() as $field => $messages) {
            $key = explode('.', $field)[0];
            // A cell already reported as unusable (an Excel error, TRUE/FALSE) was read as blank;
            // don't also complain that it's blank.
            if (! isset($cellErrors[$key])) {
                $errors[$key] = [...($errors[$key] ?? []), ...$messages];
            }
        }

        // Problems listed in column order, as they'd be read across the row.
        $ordered = [];
        foreach (array_keys(self::COLUMNS) as $key) {
            array_push($ordered, ...($errors[$key] ?? []));
        }

        return [
            // Only ever saved when $ordered is empty, i.e. when it passed Animal::rules().
            'data' => [...$data, 'location_id' => $locationId],
            // What the preview table shows: a recognised choice in its proper form ("DOG" → "Dog"),
            // anything else exactly as typed so the problem is visible.
            'display' => [
                ...array_map(fn ($v) => $this->text($v), $raw),
                'species' => $this->displayChoice($data['species'], Animal::SPECIES, $raw['species']),
                'gender' => $this->displayChoice($data['gender'], Animal::GENDERS, $raw['gender']),
                'size' => $this->displayChoice($data['size'], Animal::SIZES, $raw['size']),
                'status' => $this->displayChoice($data['status'], Animal::STATUSES, $raw['status']),
                'rescue_story' => Str::limit((string) $this->text($raw['rescue_story']), 120) ?: null,
            ],
            'errors' => $ordered,
        ];
    }

    /** The same rule as Animal::findDuplicate(), for every row of the file in a few queries. */
    private function existingAnimals(array $checked): array
    {
        $names = collect($checked)->map(fn ($c) => mb_strtolower($c['data']['name']))->unique()->values();
        $matches = [];

        foreach ($names->chunk(500) as $chunk) {
            Animal::query()
                ->select(['id', 'name', 'species', 'status'])
                ->whereIn(DB::raw('LOWER(name)'), $chunk->values()->all())
                ->orderBy('id')
                ->get()
                ->each(function (Animal $animal) use (&$matches) {
                    $matches[$this->duplicateKey((string) $animal->name, (string) $animal->species)] ??= $animal;
                });
        }

        return $matches;
    }

    private function duplicateKey(string $name, string $species): string
    {
        return mb_strtolower(trim($name)).'|'.mb_strtolower(trim($species));
    }

    /** Validation messages in spreadsheet terms, quoting what the cell actually said. */
    private function messages(array $raw): array
    {
        $said = fn (string $key) => '"'.$this->quote((string) $this->text($raw[$key])).'"';

        return [
            'name.required' => 'Name is required.',
            'name.max' => 'Name is longer than 100 characters.',
            'species.required' => 'Species is required. Use '.$this->choices(Animal::SPECIES).'.',
            'species.in' => 'Species '.$said('species').' is not valid. Use '.$this->choices(Animal::SPECIES).'.',
            'breed.max' => 'Breed is longer than 100 characters.',
            'age.integer' => 'Age '.$said('age').' must be a whole number of years, e.g. 3.',
            'age.min' => 'Age cannot be negative.',
            'age.max' => 'Age '.$said('age').' is more than 40 years. Check the number.',
            'gender.in' => 'Gender '.$said('gender').' is not valid. Use '.$this->choices(Animal::GENDERS).'.',
            'size.in' => 'Size '.$said('size').' is not valid. Use '.$this->choices(Animal::SIZES).'.',
            'weight.numeric' => 'Weight '.$said('weight').' must be a number of kilograms, e.g. 12.5.',
            'weight.min' => 'Weight cannot be negative.',
            'weight.max' => 'Weight '.$said('weight').' is too large to be a weight in kilograms.',
            'status.in' => 'Status '.$said('status').' is not valid. Use '.$this->choices(Animal::STATUSES).', or leave it blank for Available.',
            'behavioral_assessment.*.max' => 'A behavioral issue is longer than 100 characters.',
        ];
    }

    /** "Dog or Cat", "Small, Medium or Large". */
    private function choices(array $values): string
    {
        $labels = array_map('ucfirst', $values);
        $last = array_pop($labels);

        return $labels ? implode(', ', $labels).' or '.$last : $last;
    }

    /** A cell value as text (numbers included), or null when blank. */
    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = is_float($value) && floor($value) === $value ? (string) (int) $value : (string) $value;

        return $text === '' ? null : $text;
    }

    private function choice(mixed $value): ?string
    {
        $text = $this->text($value);

        return $text === null ? null : mb_strtolower($text);
    }

    private function displayChoice(?string $normalised, array $allowed, mixed $raw): ?string
    {
        return in_array($normalised, $allowed, true) ? ucfirst($normalised) : $this->text($raw);
    }

    /** Cell text short enough to quote back inside a message. */
    private function quote(string $text): string
    {
        return Str::limit($text, 40);
    }

    /** The column's header as staff see it, without the required marker. */
    private function label(string $key): string
    {
        return rtrim(self::COLUMNS[$key][0], ' *');
    }

    /** Which field a header names: case, spacing, "*" and anything in brackets don't matter. */
    private function columnKey(string $header): ?string
    {
        $normal = preg_replace(['/\(.*?\)/', '/\*/', '/\s+/'], ['', '', ' '], mb_strtolower($header));
        $normal = trim((string) $normal);

        foreach (self::COLUMNS as $key => [$templateHeader]) {
            if ($normal === trim((string) preg_replace(['/\(.*?\)/', '/\*/', '/\s+/'], ['', '', ' '], mb_strtolower($templateHeader)))) {
                return $key;
            }
        }

        return self::HEADER_ALIASES[$normal] ?? null;
    }

    private function writeHeader(Worksheet $sheet): void
    {
        $col = 1;
        foreach (self::COLUMNS as [$header, , $width, $note]) {
            $letter = Coordinate::stringFromColumnIndex($col++);
            $sheet->setCellValue("{$letter}1", $header);
            $sheet->getColumnDimension($letter)->setWidth($width);
            $sheet->getComment("{$letter}1")->setAuthor('SECASPI')->getText()->createTextRun($note);
        }

        $last = Coordinate::stringFromColumnIndex(count(self::COLUMNS));
        $sheet->getStyle("A1:{$last}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'C1612E']],
        ]);
        $sheet->freezePane('A2');
    }

    /**
     * Dropdowns for the choice columns (sourced from the Allowed Values sheet) and number checks
     * for Age and Weight. These only help whoever fills the sheet in — every value is checked
     * again on upload regardless.
     */
    private function addDropdowns(Worksheet $sheet, array $lists, array $breeds): void
    {
        $listColumn = 1;
        foreach ($lists as $key => $values) {
            $source = Coordinate::stringFromColumnIndex($listColumn++);
            if (! $values) {
                continue;
            }

            $validation = $this->cellRule($key, DataValidation::TYPE_LIST)
                ->setShowDropDown(true)
                ->setErrorTitle('Not on the list')
                ->setError('Pick a value from the dropdown. The full list is on the Allowed Values sheet.')
                ->setFormula1(sprintf("'Allowed Values'!\$%s\$2:\$%s\$%d", $source, $source, count($values) + 1));
            $this->applyRule($sheet, $key, $validation);
        }

        // Breed suggests the common breeds but accepts anything typed (no error on an unlisted
        // breed). Its list is the Allowed Values column after Behavioral Issues.
        if ($breeds) {
            $source = Coordinate::stringFromColumnIndex(count($lists) + 2);
            $validation = $this->cellRule('breed', DataValidation::TYPE_LIST)
                ->setShowDropDown(true)
                ->setShowErrorMessage(false)
                ->setFormula1(sprintf("'Allowed Values'!\$%s\$2:\$%s\$%d", $source, $source, count($breeds) + 1));
            $this->applyRule($sheet, 'breed', $validation);
        }

        // Same limits as Animal::rules().
        foreach (['age' => [DataValidation::TYPE_WHOLE, '40'], 'weight' => [DataValidation::TYPE_DECIMAL, '200']] as $key => [$type, $max]) {
            $validation = $this->cellRule($key, $type)
                ->setOperator(DataValidation::OPERATOR_BETWEEN)
                ->setFormula1('0')
                ->setFormula2($max)
                ->setErrorTitle('Not a valid number')
                ->setError($key === 'age' ? 'Age is a whole number of years, from 0 to 40.' : 'Weight is a number of kilograms, from 0 to 200.');
            $this->applyRule($sheet, $key, $validation);
        }
    }

    private function cellRule(string $key, string $type): DataValidation
    {
        return (new DataValidation)
            ->setType($type)
            ->setErrorStyle(DataValidation::STYLE_STOP)
            ->setAllowBlank(true)
            ->setShowErrorMessage(true)
            ->setShowInputMessage(true)
            ->setPromptTitle(Str::limit($this->label($key), 32, ''))
            ->setPrompt(self::COLUMNS[$key][3]);
    }

    private function applyRule(Worksheet $sheet, string $key, DataValidation $validation): void
    {
        $letter = Coordinate::stringFromColumnIndex(array_search($key, array_keys(self::COLUMNS), true) + 1);
        $sheet->setDataValidation("{$letter}2:{$letter}".self::TEMPLATE_LAST_ROW, $validation);
    }

    private function writeInstructions(Worksheet $sheet): void
    {
        $lines = [
            'How to import animals into SECASPI Shelter',
            '',
            '1. Go to the "'.self::SHEET.'" sheet and fill in one animal per row, starting on row 2. Keep the header row as it is.',
            '2. Name and Species are required. Every other column is optional and can be left blank.',
            '3. Use the dropdowns for Species, Gender, Size, Status and Shelter Area. The full lists are on the "Allowed Values" sheet. Breed also lists common breeds, but you can type any breed.',
            '4. Age is in whole years (e.g. 3; use 0 for under a year). Weight is in kilograms (e.g. 12.5).',
            '5. Status: leave it blank for Available. Available animals appear on the public adoption page as soon as they are imported.',
            '6. Behavioral Issues: type one or more issues exactly as written on the "Allowed Values" sheet, separated by commas.',
            '7. Shelter Area: the area of the shelter the animal is kept in. Leave it blank if the animal has not been assigned one yet.',
            '8. Photos are not part of this file. After importing, add photos from Animals → Manage → Photos.',
            '9. An animal with the same name and species as one already in the system is treated as a possible duplicate. It is skipped unless you tick "Import anyway" in the preview. Existing animals are never changed by an import.',
            '10. One file can hold up to '.number_format(self::MAX_ROWS).' animals. Save it as an Excel Workbook (.xlsx).',
            '11. Upload the file under Animals → Import from Excel. You will see every row checked before anything is saved.',
            '',
            'The "Example" sheet shows a filled-in sample. Animals are only read from the "'.self::SHEET.'" sheet.',
        ];

        foreach ($lines as $i => $line) {
            $sheet->setCellValueExplicit('A'.($i + 1), $line, DataType::TYPE_STRING);
        }

        $sheet->getColumnDimension('A')->setWidth(120);
        $sheet->getStyle('A1:A'.count($lines))->getAlignment()->setWrapText(true);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    }

    private function writeExamples(Worksheet $sheet, array $areas): void
    {
        // Behavioral issues are admin-managed, so the examples only use ones still on the list.
        $active = CategoryOption::activeValues('behavioral_issue');
        $issues = fn (array $wanted) => implode(', ', array_intersect($wanted, $active)) ?: ($active[0] ?? null);

        $sheet->fromArray([
            ['Brownie', 'Dog', 'Aspin', 3, 'Male', 'Medium', 12.5, 'Available', $areas[0] ?? null, $issues(['excessive barking', 'pulling on leash']), 'Found wandering near the public market. Friendly with people.'],
            ['Mingming', 'Cat', 'Puspin', 1, 'Female', 'Small', 3.2, 'Medical', $areas[1] ?? $areas[0] ?? null, $issues(['extreme shyness']), 'Rescued from a drainage canal during heavy rain.'],
            ['Bantay', 'Dog', 'Aspin', 6, 'Male', 'Large', 20, null, null, null, null],
        ], null, 'A2', true);
    }

    /** Dog breeds then cat breeds from the managed lists, each name once. */
    private function commonBreeds(): array
    {
        $breeds = [];
        foreach ([...CategoryOption::activeValues('dog_breed'), ...CategoryOption::activeValues('cat_breed')] as $breed) {
            $breeds[mb_strtolower($breed)] ??= $breed;
        }

        return array_values($breeds);
    }

    private function writeAllowedValues(Worksheet $sheet, array $lists, array $breeds): void
    {
        $columns = [...$lists, 'behavioral_assessment' => CategoryOption::activeValues('behavioral_issue'), 'breed' => $breeds];

        $widths = ['behavioral_assessment' => 34, 'breed' => 24];

        $col = 1;
        foreach ($columns as $key => $values) {
            $letter = Coordinate::stringFromColumnIndex($col++);
            $sheet->setCellValue("{$letter}1", $key === 'breed' ? 'Common Breeds' : $this->label($key));
            $sheet->getColumnDimension($letter)->setWidth($widths[$key] ?? 16);

            foreach ($values ?: ['(none set up yet)'] as $i => $value) {
                $sheet->setCellValueExplicit($letter.($i + 2), $value, DataType::TYPE_STRING);
            }
        }

        $last = Coordinate::stringFromColumnIndex(count($columns));
        $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true);
        $sheet->freezePane('A2');
    }
}
