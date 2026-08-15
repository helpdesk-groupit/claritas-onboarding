<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use App\Models\VendorImportBatch;
use App\Support\VendorImport\ColumnMapper;
use App\Support\VendorImport\SpreadsheetReader;
use App\Support\VendorImport\SpreadsheetReadException;
use App\Support\VendorImport\VendorRowBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Bulk-register vendors from the list the company already keeps in a spreadsheet.
 *
 * READ BEFORE WRITE, in three steps, and the middle one is the feature: the file is uploaded
 * and parsed, the operator is shown what the importer made of every column and every row and
 * corrects anything it got wrong, and only then is a single vendor created. Nothing is
 * written to `vendors` until that confirmation.
 *
 * The alternative — map and import in one request — is what makes a bulk importer dangerous
 * here. `vendors.name` has no database unique constraint (it is unique by validation), the
 * SST category decides whether an invoice's tax line is flagged, and `pic_email` is where the
 * e-waste RFQ and the signed AARF copy are sent. A column silently mapped to the wrong field
 * therefore does not fail — it quietly produces a master that reads as authoritative and
 * isn't. Every guess this flow makes is on screen with the reason it was made.
 *
 * The confirm step RE-READS the stored file rather than trusting a payload posted back from
 * the preview, so what gets imported is always what the document says.
 */
class VendorImportController extends Controller
{
    /** Modes for a vendor whose name is already registered. */
    private const MODE_SKIP = 'skip';

    private const MODE_UPDATE = 'update';

    private function authorizeManage(): void
    {
        if (! Auth::user()->canManageVendors()) {
            abort(403, 'No permission to manage vendors.');
        }
    }

    /**
     * Step 1 — take the file, park it, read it, and go to the preview.
     *
     * A parse failure never creates a batch: there would be nothing to preview, and a stray
     * row pointing at an unreadable file is exactly what the nightly prune exists to avoid
     * having to reason about.
     */
    public function upload(Request $request)
    {
        $this->authorizeManage();

        $request->validate([
            // `extensions` checks the name the operator's machine gave the file. `mimes` is
            // deliberately not used on top: Excel hands a .csv the MIME of application/
            // vnd.ms-excel, which Laravel guesses as "xls" and would then reject — bouncing
            // a perfectly good CSV with a message about file types.
            'import_file' => ['required', 'file', 'max:10240', 'extensions:'.implode(',', SpreadsheetReader::SUPPORTED)],
        ], [
            'import_file.extensions' => 'Upload an Excel workbook (.xlsx) or a CSV file. '
                .'If yours is an older .xls, open it in Excel and re-save as "Excel Workbook (.xlsx)".',
            'import_file.max' => 'That file is larger than 10 MB. A vendor list should be well under that — check you picked the right file.',
        ]);

        $file = $request->file('import_file');
        $originalName = $file->getClientOriginalName();
        $path = $file->store(VendorImportBatch::DIRECTORY, 'local');

        try {
            $parsed = SpreadsheetReader::read(
                Storage::disk('local')->path($path),
                $file->getClientOriginalExtension()
            );
        } catch (SpreadsheetReadException $e) {
            Storage::disk('local')->delete($path);

            return back()->with('error', $e->getMessage())->with('import_reopen', true);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            Log::warning('Vendor import: could not read uploaded file', [
                'file' => $originalName,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'That file could not be read. Save it as .xlsx or .csv and try again.')
                ->with('import_reopen', true);
        }

        if ($parsed['rows'] === []) {
            Storage::disk('local')->delete($path);

            return back()->with('error', 'There is no data in that file — every row read as empty.')
                ->with('import_reopen', true);
        }

        $batch = VendorImportBatch::create([
            'user_id' => Auth::id(),
            'token' => VendorImportBatch::freshToken(),
            'original_filename' => $originalName,
            'file_path' => $path,
            'sheet_name' => $parsed['sheet'],
            'row_count' => count($parsed['rows']),
        ]);

        return redirect()->route('vendors.import.preview', $batch->token);
    }

    /**
     * Step 2 — what the importer made of the file, and the chance to correct it.
     *
     * Re-parses on every render, which is what lets the mapping and the header row be
     * changed and re-checked without re-uploading.
     */
    public function preview(Request $request, string $token)
    {
        $this->authorizeManage();

        $batch = $this->findBatch($token);
        $parsed = $this->parse($batch);

        if ($parsed === null) {
            return redirect()->route('vendors.index')
                ->with('error', 'That uploaded list is no longer available — please upload it again.');
        }

        $plan = $this->buildPlan($request, $parsed['rows']);

        return view('vendors.import', [
            'batch' => $batch,
            'sheet' => $parsed['sheet'],
            'truncated' => $parsed['truncated'],
            'headerLine' => $plan['headerLine'],
            'headerConfident' => $plan['headerConfident'],
            'mapping' => $plan['mapping'],
            'rows' => $plan['rows'],
            'counts' => $plan['counts'],
            'unmapped' => $plan['unmapped'],
            'mode' => $request->input('mode', self::MODE_SKIP),
            'headerOptions' => array_slice(array_keys($parsed['rows']), 0, 15),
        ]);
    }

    /**
     * Step 3 — create the vendors the operator approved.
     *
     * One transaction: a bulk import that half-lands leaves nobody able to say which half,
     * and re-running it would then duplicate whatever did land.
     */
    public function commit(Request $request, string $token)
    {
        $this->authorizeManage();

        $batch = $this->findBatch($token);
        $parsed = $this->parse($batch);

        if ($parsed === null) {
            return redirect()->route('vendors.index')
                ->with('error', 'That uploaded list is no longer available — please upload it again.');
        }

        $mode = $request->input('mode') === self::MODE_UPDATE ? self::MODE_UPDATE : self::MODE_SKIP;
        $plan = $this->buildPlan($request, $parsed['rows']);

        // Only the rows still ticked on the preview. Absent parameter = nothing selected,
        // which is a real answer (the operator unticked everything) and not "import all".
        $selected = array_map('intval', (array) $request->input('rows', []));
        $selected = array_flip($selected);

        $created = 0;
        $updated = 0;
        $report = [];

        DB::transaction(function () use ($plan, $selected, $mode, &$created, &$updated, &$report) {
            foreach ($plan['rows'] as $row) {
                if ($row['error'] !== null || ! isset($selected[$row['line']])) {
                    continue;
                }

                // Re-checked inside the transaction rather than trusted from the preview:
                // `vendors.name` has no unique index, so two operators importing overlapping
                // lists at the same time is otherwise how the master gets its duplicates.
                $existing = Vendor::whereRaw('LOWER(name) = ?', [mb_strtolower($row['attributes']['name'])])->first();

                if ($existing) {
                    if ($mode !== self::MODE_UPDATE) {
                        continue;
                    }

                    $existing->fill($this->updatableAttributes($row['attributes'], $row['defaulted']))->save();
                    $updated++;
                    $report[] = 'Row '.$row['line'].': updated "'.$existing->name.'".';

                    continue;
                }

                $vendor = Vendor::create($row['attributes'] + [
                    'is_active' => $row['attributes']['is_active'] ?? true,
                ]);

                $created++;
                $report[] = 'Row '.$row['line'].': created "'.$vendor->name.'".';
            }
        });

        $batch->discard();

        if ($created === 0 && $updated === 0) {
            return redirect()->route('vendors.index')
                ->with('error', 'Nothing was imported — no rows were selected, or every selected vendor is already registered.');
        }

        $summary = trim(
            ($created ? $created.' vendor'.($created === 1 ? '' : 's').' created' : '')
            .($created && $updated ? ', ' : '')
            .($updated ? $updated.' updated' : '')
        );

        return redirect()->route('vendors.index')
            ->with('success', 'Import complete — '.$summary.'.')
            ->with('import_report', $report);
    }

    /** Abandon an import without importing anything. */
    public function discard(string $token)
    {
        $this->authorizeManage();

        $this->findBatch($token)->discard();

        return redirect()->route('vendors.index')->with('success', 'Import cancelled — the uploaded file was discarded.');
    }

    /**
     * A starter file, for the operator who has no list yet or wants to see what the importer
     * recognises without guessing. Headers only — the field names it maps with no ambiguity.
     */
    public function template()
    {
        $this->authorizeManage();

        $headers = [
            'Vendor Name', 'Company Registration No', 'TIN', 'SST No', 'SST Category',
            'Type of Service', 'Industry',
            'PIC Name', 'PIC Email', 'PIC Phone',
            'Technical Person', 'Technical Person Email', 'Technical Person Phone',
            'Office No', 'Company Email', 'Website', 'Address',
            'Bank', 'Bank Account Name', 'Bank Account No', 'Bank Branch', 'SWIFT',
            'Notes', 'Status',
        ];

        $example = [
            'Contoh Teknologi Sdn Bhd', '202201234567 (1234567-A)', 'C12345678901', 'W10-1234-56789012',
            'Group G — Professional services', 'IT Services; Repair & Maintenance', 'IT Hardware',
            'Lim Wei Ming', 'weiming@contoh.com.my', '012-345 6789',
            'Ahmad Faizal', 'support@contoh.com.my', '03-1234 5678',
            '03-1234 5678', 'info@contoh.com.my', 'www.contoh.com.my',
            'No. 12, Jalan Teknologi 3/5, 47810 Petaling Jaya, Selangor',
            'Maybank', 'Contoh Teknologi Sdn Bhd', '512345678901', 'PJ Old Town', 'MBBEMYKL',
            'Renewal every January', 'Active',
        ];

        return response()->streamDownload(function () use ($headers, $example) {
            $handle = fopen('php://output', 'w');
            // BOM, so Excel opens the file as UTF-8 and does not mangle the sample address.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headers);
            fputcsv($handle, $example);
            fclose($handle);
        }, 'vendor_import_template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    // ── Internals ─────────────────────────────────────────────────────────────
    /**
     * The batch this token names, scoped to the operator who uploaded it.
     *
     * The token travels in a URL and is a lookup key, never a capability — same rule as
     * VendorDocumentScan. Somebody else's half-finished import is not theirs to file.
     */
    private function findBatch(string $token): VendorImportBatch
    {
        return VendorImportBatch::where('token', $token)
            ->where('user_id', Auth::id())
            ->firstOrFail();
    }

    /**
     * @return array{sheet: ?string, rows: array<int, list<string>>, truncated: bool}|null
     */
    private function parse(VendorImportBatch $batch): ?array
    {
        $path = $batch->absolutePath();

        if ($path === null) {
            return null;
        }

        try {
            return SpreadsheetReader::read($path, $batch->extension());
        } catch (\Throwable $e) {
            Log::warning('Vendor import: stored file could not be re-read', [
                'batch' => $batch->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Everything the preview shows and the commit acts on, derived from the file plus
     * whatever the operator has changed about the header row and the mapping.
     *
     * Built by ONE method used by both steps, so the confirm can never act on a different
     * reading of the file from the one that was approved.
     *
     * @param  array<int, list<string>>  $rows
     */
    private function buildPlan(Request $request, array $rows): array
    {
        $detected = ColumnMapper::detectHeaderRow($rows);

        $submittedLine = $request->input('header_line');
        $headerLine = is_numeric($submittedLine) && isset($rows[(int) $submittedLine])
            ? (int) $submittedLine
            : $detected['line'];

        $headers = $rows[$headerLine] ?? [];

        // Every data row's width, so a mapping select exists for a column that only appears
        // further down (a header row shorter than its data is common when a column was added
        // later and nobody captioned it).
        $width = count($headers);
        foreach ($rows as $line => $cells) {
            if ($line !== $headerLine) {
                $width = max($width, count($cells));
            }
        }
        $headers = array_pad($headers, $width, '');

        $samples = ColumnMapper::samples($rows, $headerLine);

        $mapping = $request->has('map')
            ? ColumnMapper::sanitiseSubmitted((array) $request->input('map'), $headers)
            : ColumnMapper::map($headers, $samples);

        $byField = ColumnMapper::byField($mapping);

        // Names already in the master, matched case-insensitively — "ABC Sdn Bhd" and
        // "ABC SDN BHD" are the same vendor and registering both is the failure a master
        // exists to prevent.
        $existing = Vendor::query()
            ->select('id', 'name')
            ->get()
            ->keyBy(fn ($vendor) => mb_strtolower($vendor->name));

        $plan = [];
        $counts = ['create' => 0, 'update' => 0, 'duplicate' => 0, 'error' => 0];
        $seen = [];
        $past = false;

        foreach ($rows as $line => $cells) {
            if (! $past) {
                $past = $line === $headerLine;

                continue;
            }

            $built = VendorRowBuilder::build($cells, $byField);
            $notes = $built['notes'];
            $action = 'create';
            $match = null;

            if ($built['error'] !== null) {
                $action = 'error';
            } else {
                $key = mb_strtolower($built['attributes']['name']);

                if (isset($seen[$key])) {
                    $action = 'error';
                    $built['error'] = 'The same vendor name appears earlier in this file (row '.$seen[$key].').';
                } elseif ($existing->has($key)) {
                    $action = 'duplicate';
                    $match = $existing->get($key);
                } else {
                    $seen[$key] = $line;
                }
            }

            $counts[$action === 'duplicate' ? 'duplicate' : $action]++;

            $plan[] = [
                'line' => $line,
                'cells' => $cells,
                'attributes' => $built['attributes'],
                'notes' => $notes,
                'error' => $built['error'],
                'action' => $action,
                'existing' => $match,
                'defaulted' => $built['defaulted'],
            ];
        }

        return [
            'headerLine' => $headerLine,
            'headerConfident' => $detected['confident'] || $request->has('header_line') || $request->has('map'),
            'mapping' => $mapping,
            'rows' => $plan,
            'counts' => $counts,
            'unmapped' => array_values(array_diff(array_keys(ColumnMapper::FIELDS), array_keys($byField))),
        ];
    }

    /**
     * What an "update existing" pass is allowed to write.
     *
     * Only values the sheet actually carries: a column the spreadsheet does not have, or a
     * cell left blank in it, must never blank out a field somebody filled in on the vendor's
     * profile. An import adds what the list knows; it does not assert what the list omits.
     *
     * `$defaulted` is why this takes two arguments. `vendor_types` is never empty on a new
     * vendor — a row with no service type is filed as "Other" — so writing it back on an
     * update would silently re-tag a carefully categorised vendor as Other for no reason
     * beyond the spreadsheet not having that column.
     *
     * The name is excluded because it is what MATCHED the existing vendor; re-writing it
     * would only ever change its capitalisation.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $defaulted
     * @return array<string, mixed>
     */
    private function updatableAttributes(array $attributes, array $defaulted = []): array
    {
        unset($attributes['name']);

        foreach ($defaulted as $field) {
            unset($attributes[$field]);
        }

        return array_filter($attributes, static fn ($value) => $value !== null && $value !== '' && $value !== []);
    }
}
