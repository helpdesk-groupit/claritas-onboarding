<?php

namespace App\Support\VendorImport;

use SimpleXMLElement;
use ZipArchive;

/**
 * Turns an uploaded .xlsx or .csv into plain rows of strings. Pure and network-free, like
 * DetectionEngine and ConnectionDiagnosis — no models, no storage, no config; give it a path
 * and it gives you rows, so the whole thing is unit-testable without a request.
 *
 * DELIBERATELY NO LIBRARY. An .xlsx is a zip of XML and PHP ships both `zip` and `simplexml`,
 * so reading one costs ~150 lines here versus a composer dependency that would have to be
 * installed by hand on the live NAS (which carries no composer binary). The old binary .xls
 * format is the one thing that genuinely needs a library, so it is REFUSED with an
 * instruction to re-save rather than half-read — see assertSupported().
 *
 * Rows are keyed by the SPREADSHEET's own row number, not by position: every message the
 * import shows an operator says "row 14", and that has to be the 14 they see in Excel or
 * they cannot find the row being complained about.
 */
class SpreadsheetReader
{
    /**
     * Bounds, not preferences. The whole sheet is held in memory at once (a vendor master is
     * hundreds of rows, not hundreds of thousands), so these are what stop a mis-picked file
     * — an export of every transaction this year — from exhausting the request.
     */
    public const MAX_ROWS = 2000;

    public const MAX_COLUMNS = 60;

    /** Sheets to look through before giving up on finding one with data. */
    private const MAX_SHEETS = 8;

    /** Extensions we can read. `.xls` is deliberately absent — see assertSupported(). */
    public const SUPPORTED = ['xlsx', 'xlsm', 'csv', 'txt'];

    /**
     * @return array{sheet: ?string, rows: array<int, list<string>>, truncated: bool}
     */
    public static function read(string $path, string $extension): array
    {
        $extension = strtolower(trim($extension, '. '));

        self::assertSupported($extension);

        return in_array($extension, ['csv', 'txt'], true)
            ? self::readCsv($path)
            : self::readXlsx($path);
    }

    /**
     * Refuse what we cannot read, in the operator's terms.
     *
     * The old binary `.xls` is the case that matters: it is still what Excel produces when
     * somebody picks "Excel 97-2003", it is not a zip, and every attempt to read it here
     * would come back as an unhelpful "not a valid archive". Saying "re-save as .xlsx" is
     * the whole remedy and takes them ten seconds.
     */
    private static function assertSupported(string $extension): void
    {
        if (in_array($extension, self::SUPPORTED, true)) {
            return;
        }

        if ($extension === 'xls') {
            throw new SpreadsheetReadException(
                'That is the older Excel format (.xls), which this importer cannot read. '
                .'Open it in Excel and use File → Save As → "Excel Workbook (.xlsx)", then upload the .xlsx.'
            );
        }

        throw new SpreadsheetReadException(
            'Unsupported file type ".'.$extension.'". Upload an Excel workbook (.xlsx) or a CSV file.'
        );
    }

    // ── CSV ───────────────────────────────────────────────────────────────────
    /**
     * @return array{sheet: ?string, rows: array<int, list<string>>, truncated: bool}
     */
    private static function readCsv(string $path): array
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            throw new SpreadsheetReadException('The uploaded file could not be opened.');
        }

        $delimiter = self::sniffDelimiter($path);
        $rows = [];
        $line = 0;
        $truncated = false;

        while (($cells = fgetcsv($handle, 0, $delimiter)) !== false) {
            $line++;

            if (count($rows) >= self::MAX_ROWS) {
                $truncated = true;
                break;
            }

            // fgetcsv reports a blank line as [null]; keep the line NUMBER moving anyway so
            // later rows still line up with what the operator sees in their editor.
            if ($cells === [null]) {
                continue;
            }

            $rows[$line] = self::normaliseCells($cells);
        }

        fclose($handle);

        return ['sheet' => null, 'rows' => self::trimEmpty($rows), 'truncated' => $truncated];
    }

    /**
     * Work out the separator from the first non-empty line.
     *
     * Exports from Malaysian/European locale Excel are semicolon-separated as often as they
     * are comma-separated, and reading a semicolon file with a comma yields exactly one
     * enormous column — which looks like "the importer understood nothing" rather than like
     * a delimiter problem.
     */
    private static function sniffDelimiter(string $path): string
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            return ',';
        }

        $sample = '';
        $read = 0;

        while ($read < 5 && ($line = fgets($handle)) !== false) {
            if (trim($line) === '') {
                continue;
            }
            $sample .= $line;
            $read++;
        }

        fclose($handle);

        $best = ',';
        $bestCount = 0;

        foreach ([',', ';', "\t", '|'] as $candidate) {
            $count = substr_count($sample, $candidate);
            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    // ── XLSX ──────────────────────────────────────────────────────────────────
    /**
     * @return array{sheet: ?string, rows: array<int, list<string>>, truncated: bool}
     */
    private static function readXlsx(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new SpreadsheetReadException(
                'That file is not a readable Excel workbook. If it was saved as "Excel 97-2003 (.xls)", '
                .'re-save it as "Excel Workbook (.xlsx)" and try again.'
            );
        }

        try {
            $shared = self::sharedStrings($zip);
            $sheets = self::sheetIndex($zip);

            if ($sheets === []) {
                throw new SpreadsheetReadException('That workbook contains no worksheets.');
            }

            $first = null;

            // Take the first sheet that actually holds data, not simply the first sheet: a
            // vendor list regularly sits behind a cover/instructions tab, and importing the
            // cover sheet would report "nothing recognisable in this file".
            foreach (array_slice($sheets, 0, self::MAX_SHEETS) as [$name, $target]) {
                $xml = $zip->getFromName($target);

                if ($xml === false) {
                    continue;
                }

                $parsed = self::parseSheet($xml, $shared);
                $parsed['sheet'] = $name;
                $first ??= $parsed;

                if (count($parsed['rows']) >= 2) {
                    return $parsed;
                }
            }

            if ($first === null) {
                throw new SpreadsheetReadException('That workbook could not be read — no worksheet data was found in it.');
            }

            return $first;
        } finally {
            $zip->close();
        }
    }

    /**
     * The workbook's sheets, in tab order, as [name, path-inside-the-zip].
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function sheetIndex(ZipArchive $zip): array
    {
        $workbook = self::loadXml($zip->getFromName('xl/workbook.xml'));
        $rels = self::loadXml($zip->getFromName('xl/_rels/workbook.xml.rels'));

        if ($workbook === null) {
            return [];
        }

        $targets = [];

        if ($rels !== null) {
            foreach ($rels->Relationship as $rel) {
                // `r:id` has lost its prefix in flatten(), so it reads as a plain `id` here.
                $targets[(string) $rel['Id']] = self::normaliseTarget((string) $rel['Target']);
            }
        }

        $sheets = [];

        foreach ($workbook->sheets->sheet ?? [] as $sheet) {
            $relId = (string) $sheet['id'];
            $target = $targets[$relId] ?? null;

            // Some generators emit no relationship part at all; fall back to the conventional
            // layout rather than dropping the sheet.
            $target ??= 'xl/worksheets/sheet'.(count($sheets) + 1).'.xml';

            $sheets[] = [(string) $sheet['name'], $target];
        }

        return $sheets;
    }

    /** Relationship targets are relative to `xl/`, and occasionally absolute. */
    private static function normaliseTarget(string $target): string
    {
        $target = ltrim($target, './');

        if (str_starts_with($target, '/')) {
            return ltrim($target, '/');
        }

        return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
    }

    /**
     * The workbook's shared string table, flattened to plain text.
     *
     * @return list<string>
     */
    private static function sharedStrings(ZipArchive $zip): array
    {
        $xml = self::loadXml($zip->getFromName('xl/sharedStrings.xml'));

        if ($xml === null) {
            return [];
        }

        $strings = [];

        foreach ($xml->si as $si) {
            // A styled cell is split across <r> runs, so the text has to be gathered from
            // every <t> descendant — reading only the first would truncate "Sdn Bhd" off a
            // vendor name that happened to have one word in bold.
            $strings[] = self::textOf($si);
        }

        return $strings;
    }

    /**
     * @param  list<string>  $shared
     * @return array{sheet: ?string, rows: array<int, list<string>>, truncated: bool}
     */
    private static function parseSheet(string $xml, array $shared): array
    {
        $sheet = self::loadXml($xml);

        if ($sheet === null) {
            return ['sheet' => null, 'rows' => [], 'truncated' => false];
        }

        $rows = [];
        $truncated = false;
        $fallbackLine = 0;

        foreach ($sheet->sheetData->row ?? [] as $row) {
            $fallbackLine++;
            $line = (int) ($row['r'] ?? 0) ?: $fallbackLine;

            if (count($rows) >= self::MAX_ROWS) {
                $truncated = true;
                break;
            }

            $cells = [];

            foreach ($row->c ?? [] as $cell) {
                $index = self::columnIndex((string) ($cell['r'] ?? ''));

                if ($index === null || $index >= self::MAX_COLUMNS) {
                    continue;
                }

                $cells[$index] = self::cellValue($cell, $shared);
            }

            if ($cells === []) {
                continue;
            }

            // Gaps are real columns: a blank cell in the middle must keep the ones after it
            // in their own positions, or every mapping past the gap shifts left by one.
            $width = max(array_keys($cells)) + 1;
            $dense = [];

            for ($i = 0; $i < $width; $i++) {
                $dense[] = $cells[$i] ?? '';
            }

            $rows[$line] = self::normaliseCells($dense);
        }

        return ['sheet' => null, 'rows' => self::trimEmpty($rows), 'truncated' => $truncated];
    }

    /**
     * @param  list<string>  $shared
     */
    private static function cellValue(SimpleXMLElement $cell, array $shared): string
    {
        $type = (string) ($cell['t'] ?? '');

        if ($type === 's') {
            return $shared[(int) $cell->v] ?? '';
        }

        if ($type === 'inlineStr') {
            return self::textOf($cell->is);
        }

        // A formula that evaluated to an error (#N/A, #REF!) has no usable value; treating it
        // as the literal text "#N/A" would file that string as a vendor's phone number.
        if ($type === 'e') {
            return '';
        }

        if ($type === 'b') {
            return ((string) $cell->v) === '1' ? 'Yes' : 'No';
        }

        $value = (string) ($cell->v ?? '');

        return self::normaliseNumber($value);
    }

    /**
     * Whole numbers must not come back in scientific notation.
     *
     * Excel stores a phone number typed without a leading apostrophe as a NUMBER, and
     * casting that through PHP's default float formatting turns 60123456789 into
     * "6.0123456789E+10" — which is then filed as the vendor's contact number.
     */
    private static function normaliseNumber(string $value): string
    {
        if ($value === '' || ! is_numeric($value)) {
            return $value;
        }

        $float = (float) $value;

        if (abs($float) < 1e15 && floor($float) === $float && ! str_contains($value, '.')) {
            return number_format($float, 0, '.', '');
        }

        return $value;
    }

    /** Every <t> under an element, concatenated — handles rich-text runs. */
    private static function textOf(?SimpleXMLElement $element): string
    {
        if ($element === null) {
            return '';
        }

        $text = '';

        foreach ($element->xpath('.//t') ?: [] as $t) {
            $text .= (string) $t;
        }

        return $text;
    }

    /** "AB12" → 27. Null when the reference is missing or malformed. */
    public static function columnIndex(string $reference): ?int
    {
        if (! preg_match('/^([A-Z]+)/i', $reference, $match)) {
            return null;
        }

        $letters = strtoupper($match[1]);
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }

    // ── Shared plumbing ───────────────────────────────────────────────────────
    /**
     * Parse OOXML with its namespaces flattened away.
     *
     * SimpleXML will not traverse a document with a default namespace using plain property
     * access — `$xml->sheetData->row` silently yields nothing, which reads exactly like an
     * empty spreadsheet. Every OOXML part declares one, so the namespaces are stripped up
     * front and the rest of this class can address elements by name. The parts are our own
     * zip entries, never arbitrary XML from a URL, and LIBXML_NONET blocks any external
     * fetch a crafted file might try.
     */
    private static function loadXml(string|false $xml): ?SimpleXMLElement
    {
        if ($xml === false || trim($xml) === '') {
            return null;
        }

        $flat = self::flatten($xml);

        $previous = libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($flat, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $parsed === false ? null : $parsed;
    }

    private static function flatten(string $xml): string
    {
        // Order matters: the declarations go first, then element prefixes, then attribute
        // prefixes — dropping `xmlns:r="…"` before rewriting `r:id` keeps the document valid
        // at every step, and rewriting in the other order leaves an undeclared prefix behind.
        $xml = preg_replace('/\sxmlns(:[A-Za-z0-9_.-]+)?="[^"]*"/', '', $xml) ?? $xml;
        $xml = preg_replace('/(<\/?)[A-Za-z0-9_.-]+:/', '$1', $xml) ?? $xml;

        return preg_replace('/\s[A-Za-z0-9_.-]+:([A-Za-z0-9_.-]+=")/', ' $1', $xml) ?? $xml;
    }

    /**
     * @param  array<int, string|null>  $cells
     * @return list<string>
     */
    private static function normaliseCells(array $cells): array
    {
        return array_map(static fn ($cell) => self::clean((string) $cell), array_values($cells));
    }

    /**
     * Strip what a copy-pasted spreadsheet cell drags in with it.
     *
     * Non-breaking spaces are the one that bites: a header of "Vendor\xA0Name" matches no
     * synonym at all and the column silently arrives unmapped, with nothing on screen to
     * distinguish it from a header nobody thought of.
     */
    public static function clean(string $value): string
    {
        $value = str_replace(["\xc2\xa0", "\xa0"], ' ', $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;

        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }

        // Runs of spaces collapse, but newlines survive — a multi-line address is one cell,
        // and flattening it here would lose the only structure it has.
        $value = preg_replace('/[ \t]{2,}/', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * Drop rows that hold nothing at all, and trailing empty columns.
     *
     * A spreadsheet a human maintains is full of spacer rows; keeping them would put dozens
     * of "row 47: no vendor name" complaints in front of an operator whose file is fine.
     *
     * @param  array<int, list<string>>  $rows
     * @return array<int, list<string>>
     */
    private static function trimEmpty(array $rows): array
    {
        $kept = [];

        foreach ($rows as $line => $cells) {
            while ($cells !== [] && end($cells) === '') {
                array_pop($cells);
            }

            if ($cells === []) {
                continue;
            }

            $kept[$line] = array_slice($cells, 0, self::MAX_COLUMNS);
        }

        return $kept;
    }
}
