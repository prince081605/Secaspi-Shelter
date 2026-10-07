<?php

namespace App\Http\Controllers;

use App\Exceptions\AnimalImportException;
use App\Services\AnimalImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * Admin-only bulk import of animals from an Excel workbook, in two steps: preview (read and check
 * every row, save nothing) and import. The import step is sent the same file again and checks it
 * again from scratch — it never trusts the preview the browser is holding, and it catches anything
 * that changed in between (another admin adding one of the same animals, an area being removed).
 */
class AnimalImportController extends Controller
{
    private const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __construct(private AnimalImporter $importer) {}

    public function template()
    {
        $book = $this->importer->template();

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
            $book->disconnectWorksheets();
        }, 'secaspi-animal-import-template.xlsx', ['Content-Type' => self::XLSX_MIME]);
    }

    public function preview(Request $request)
    {
        if ($invalid = $this->rejectUpload($request)) {
            return $invalid;
        }

        try {
            $read = $this->importer->read($request->file('file'));
        } catch (AnimalImportException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $checked = $this->importer->validate($read['rows']);

        return response()->json([
            'file' => ['name' => $request->file('file')->getClientOriginalName()],
            'summary' => $checked['summary'],
            'rows' => $checked['rows'],
            'warnings' => $read['warnings'],
        ]);
    }

    public function store(Request $request)
    {
        if ($invalid = $this->rejectUpload($request, [
            'allow_duplicates' => ['nullable', 'array'],
            'allow_duplicates.*' => ['integer', 'min:2'],
        ])) {
            return $invalid;
        }

        // One import at a time per admin, so a double-clicked Import button can't run the same
        // file twice (which, with duplicates ticked "import anyway", would add them twice).
        $lock = Cache::lock('animal-import:'.$request->user()->id, 120);
        if (! $lock->get()) {
            return response()->json(['message' => 'An import is already running. Wait for it to finish, then check the animal list.'], 409);
        }

        try {
            try {
                $read = $this->importer->read($request->file('file'));
            } catch (AnimalImportException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            $checked = $this->importer->validate($read['rows'], $request->input('allow_duplicates', []));

            if (! $checked['insert']) {
                return response()->json([
                    'message' => 'No rows are ready to import. Fix the problems listed for each row and upload the file again.',
                    'summary' => $checked['summary'],
                    'rows' => $checked['rows'],
                ], 422);
            }

            try {
                $created = $this->importer->import($checked['insert'], $request->user());
            } catch (Throwable $e) {
                report($e);

                return response()->json(['message' => 'The import could not be saved, so no animals were added. Please try again.'], 500);
            }
        } finally {
            $lock->release();
        }

        $rejected = array_values(array_filter($checked['rows'], fn ($r) => $r['status'] === 'error'));
        $skipped = array_values(array_filter($checked['rows'], fn ($r) => $r['status'] === 'duplicate'));

        return response()->json([
            'summary' => [
                'processed' => count($checked['rows']),
                'imported' => count($created),
                'rejected' => count($rejected),
                'skipped_duplicates' => count($skipped),
            ],
            'imported' => $created,
            'rejected' => array_map(fn ($r) => ['row' => $r['row'], 'name' => $r['values']['name'], 'errors' => $r['errors']], $rejected),
            'skipped' => array_map(fn ($r) => ['row' => $r['row'], 'name' => $r['values']['name'], 'reason' => $r['duplicate_of']['message']], $skipped),
            'warnings' => $read['warnings'],
        ], 201);
    }

    /** The upload itself (before its contents are read): there, an .xlsx, not too big. */
    private function rejectUpload(Request $request, array $extraRules = []): ?JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'extensions:xlsx', 'max:5120'],
            ...$extraRules,
        ], [
            'file.required' => 'Choose the Excel file (.xlsx) to import.',
            'file.uploaded' => 'The file could not be uploaded. Make sure it is an .xlsx file under 5 MB and try again.',
            'file.file' => 'The file could not be uploaded. Please try again.',
            // `extensions` (the file's name) rather than `mimes`: fileinfo often reports a
            // genuine .xlsx as application/zip. Its contents are checked when it's opened.
            'file.extensions' => 'Only Excel workbooks (.xlsx) can be imported. If your file is .xls or .csv, open it in Excel and use Save As → Excel Workbook (.xlsx).',
            'file.max' => 'The file is larger than 5 MB. Split it into smaller files.',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        return null;
    }
}
