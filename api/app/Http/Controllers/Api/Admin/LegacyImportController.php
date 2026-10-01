<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunLegacyImport;
use App\Models\Barangay;
use App\Models\LegacyImport;
use App\Models\PermitType;
use App\Support\LegacyImport\ImportRunner;
use App\Support\LegacyImport\Sources\CsvSource;
use App\Support\LegacyImport\Sources\OdbcSource;
use App\Support\LegacyImport\SourceUnreadable;
use App\Support\LegacyImport\Template;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The super admin's import of the old register (permission `data.import`).
 *
 * Ken's checklist, 27 September 2026, "Migration 1" and "Migration 2 (IN)".
 * Upload (or name an ODBC source) → dry run → confirm → import. Nothing is
 * written to the register until the confirm; the dry run's verdict and the
 * uploaded file wait on a `legacy_imports` row in between.
 */
class LegacyImportController extends Controller
{
    /**
     * Above this many rows the confirmed import is queued rather than run in
     * the request. At a few milliseconds a row, 1,000 rows finishes well inside
     * a request timeout; 40,000 does not.
     */
    public const QUEUE_ABOVE = 1000;

    /** Upload ceiling. Roughly 100,000 template rows. */
    public const MAX_KILOBYTES = 20480;

    public function __construct(private ImportRunner $runner) {}

    /** Recent imports, newest first. */
    public function index(): JsonResponse
    {
        $imports = LegacyImport::with('user:id,name')->latest('id')->limit(20)->get();

        return response()->json(['data' => $imports->map(fn ($i) => $this->present($i, withRejects: false))]);
    }

    /** What the screen needs to explain the template and the ODBC option. */
    public function guide(): JsonResponse
    {
        return response()->json(['data' => [
            'columns' => Template::guide(),
            'barangays' => Barangay::orderBy('name')->pluck('name'),
            'permit_types' => PermitType::orderBy('code')->get(['code', 'name']),
            'odbc' => [
                'available' => OdbcSource::available(),
                'message' => OdbcSource::available() ? null : OdbcSource::unavailableMessage(),
            ],
            'queue_above' => self::QUEUE_ABOVE,
        ]]);
    }

    /** The template: header row, one example row. */
    public function template(): Response
    {
        return response(Template::csv(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="biztrack-legacy-import-template.csv"',
        ]);
    }

    /** Upload a CSV and dry-run it. */
    public function previewCsv(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:'.self::MAX_KILOBYTES],
        ], [
            'file.max' => 'The file is larger than 20 MB. Split it into smaller files and import them one after another.',
        ]);

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['csv', 'txt'], true)) {
            throw ValidationException::withMessages([
                'file' => ['Upload a .csv file. In Excel, use File → Save As → CSV UTF-8.'],
            ]);
        }

        /*
         * A dry run nobody confirmed leaves its upload on disk — owners' names,
         * emails and mobile numbers (RA 10173) with no purpose left. Anything
         * a day old goes whenever the next upload arrives; the import row and
         * its counts stay, only the file is removed.
         */
        LegacyImport::where('status', LegacyImport::PREVIEWED)
            ->whereNotNull('stored_path')
            ->where('created_at', '<', now()->subDay())
            ->get()
            ->each(fn (LegacyImport $stale) => $this->runner->forgetFile($stale));

        $name = Str::limit($file->getClientOriginalName(), 200, '');
        $path = $file->storeAs('legacy-imports', Str::uuid().'.csv', 'local');

        $import = LegacyImport::create([
            'user_id' => $request->user()->id,
            'source' => 'csv',
            'file_name' => $name,
            'stored_path' => $path,
        ]);

        try {
            $this->runner->preview($import, new CsvSource(Storage::disk('local')->path($path), $name));
        } catch (SourceUnreadable $e) {
            $this->runner->forgetFile($import);
            $import->delete();

            throw ValidationException::withMessages(['file' => [$e->getMessage()]]);
        }

        return response()->json(['data' => $this->present($import->fresh('user'))], 201);
    }

    /** Name an ODBC source and dry-run it. */
    public function previewOdbc(Request $request): JsonResponse
    {
        $data = $request->validate([
            // A DSN name as configured in odbc.ini, or a DSN string MISD gave.
            'dsn' => ['required', 'string', 'max:255'],
            'table' => ['nullable', 'string', 'max:128'],
            'query' => ['nullable', 'string', 'max:4000'],
        ]);

        if (! OdbcSource::available()) {
            throw ValidationException::withMessages(['dsn' => [OdbcSource::unavailableMessage()]]);
        }

        try {
            $source = new OdbcSource($data['dsn'], $data['table'] ?? null, $data['query'] ?? null);
            $source->sql();
        } catch (SourceUnreadable $e) {
            throw ValidationException::withMessages(['query' => [$e->getMessage()]]);
        }

        $import = LegacyImport::create([
            'user_id' => $request->user()->id,
            'source' => 'odbc',
            'file_name' => $source->label(),
            'source_query' => array_filter([
                'dsn' => $data['dsn'],
                'table' => $data['table'] ?? null,
                'query' => $data['query'] ?? null,
            ]),
        ]);

        try {
            $this->runner->preview($import, $source);
        } catch (SourceUnreadable $e) {
            $import->delete();

            throw ValidationException::withMessages(['dsn' => [$e->getMessage()]]);
        }

        return response()->json(['data' => $this->present($import->fresh('user'))], 201);
    }

    /** One import — the screen polls this while a queued import runs. */
    public function show(LegacyImport $legacyImport): JsonResponse
    {
        return response()->json(['data' => $this->present($legacyImport->load('user:id,name'))]);
    }

    /**
     * Confirm a dry run and import it.
     *
     * The status moves `previewed → queued` in one conditional UPDATE, so a
     * double click (or two tabs) cannot start the same import twice.
     */
    public function run(Request $request, LegacyImport $legacyImport): JsonResponse
    {
        $claimed = LegacyImport::whereKey($legacyImport->id)
            ->where('status', LegacyImport::PREVIEWED)
            ->update(['status' => LegacyImport::QUEUED]);

        abort_if($claimed === 0, 409, 'This import has already been started. Refresh to see how it went.');

        $legacyImport->refresh();

        if ($legacyImport->total_rows > self::QUEUE_ABOVE) {
            RunLegacyImport::dispatch($legacyImport->id);
        } else {
            RunLegacyImport::dispatchSync($legacyImport->id);
        }

        return response()->json(['data' => $this->present($legacyImport->fresh('user'))]);
    }

    /** @return array<string, mixed> */
    private function present(LegacyImport $import, bool $withRejects = true): array
    {
        return [
            'id' => $import->id,
            'source' => $import->source,
            'file_name' => $import->file_name,
            'status' => $import->status,
            'total_rows' => $import->total_rows,
            'will_create' => $import->will_create,
            'will_update' => $import->will_update,
            'rejected' => $import->rejected,
            'created_count' => $import->created_count,
            'updated_count' => $import->updated_count,
            'processed_rows' => $import->processed_rows,
            'breakdown' => $import->breakdown,
            'rejects' => $withRejects ? ($import->rejects ?? []) : null,
            'error' => $import->error,
            'user' => $import->user ? ['name' => $import->user->name] : null,
            'created_at' => optional($import->created_at)->toISOString(),
            'finished_at' => optional($import->finished_at)->toISOString(),
        ];
    }
}
