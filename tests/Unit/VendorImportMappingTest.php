<?php

namespace Tests\Unit;

use App\Support\VendorImport\ColumnMapper;
use App\Support\VendorImport\SpreadsheetReader;
use App\Support\VendorImport\SpreadsheetReadException;
use App\Support\VendorImport\VendorRowBuilder;
use Tests\TestCase;
use ZipArchive;

/**
 * The reading half of the vendor import: file → rows → mapping → attributes.
 *
 * All three classes are pure — no models written, no storage, no HTTP — so this is a unit
 * suite in the sense that matters. It extends Tests\TestCase rather than PHPUnit's own only
 * because the SST categories are overridable from config/vendors.php, and resolving one
 * needs the config repository; nothing here touches the database.
 *
 * The behaviour that matters is not "does it map a tidy sheet" but "what does it do with a
 * messy one", because every real vendor list is the second kind.
 */
class VendorImportMappingTest extends TestCase
{
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    // ── The reader ────────────────────────────────────────────────────────────
    public function test_it_reads_an_xlsx_workbook_without_a_library(): void
    {
        $path = $this->makeXlsx([
            ['Vendor Name', 'PIC', 'Email'],
            ['Alpha Sdn Bhd', 'Lim Wei Ming', 'lim@alpha.com.my'],
            ['Beta Enterprise', 'Siti', 'siti@beta.my'],
        ]);

        $result = SpreadsheetReader::read($path, 'xlsx');

        $this->assertSame('Vendors', $result['sheet']);
        $this->assertSame(['Vendor Name', 'PIC', 'Email'], $result['rows'][1]);
        $this->assertSame(['Beta Enterprise', 'Siti', 'siti@beta.my'], $result['rows'][3]);
    }

    /**
     * Rows are keyed by the spreadsheet's OWN row number. Every message the preview shows
     * says "row N", and if that is a position in a filtered list rather than the number in
     * Excel, the operator cannot find the row being complained about.
     */
    public function test_row_numbers_survive_blank_rows(): void
    {
        $path = $this->makeXlsx([
            ['Our Vendor List'],
            [],
            ['Vendor Name', 'Email'],
            ['Alpha Sdn Bhd', 'lim@alpha.com.my'],
        ]);

        $rows = SpreadsheetReader::read($path, 'xlsx')['rows'];

        $this->assertArrayNotHasKey(2, $rows, 'the blank row should be dropped');
        $this->assertSame(['Vendor Name', 'Email'], $rows[3]);
        $this->assertSame(4, array_key_last($rows));
    }

    /**
     * Excel stores a phone number typed without a leading apostrophe as a NUMBER. Casting
     * that naively yields "6.0123456789E+10", which is then filed as the vendor's contact
     * number and is not a phone number anybody can ring.
     */
    public function test_a_numeric_phone_column_does_not_come_back_in_scientific_notation(): void
    {
        $path = $this->makeXlsx([
            ['Vendor Name', 'Phone'],
            ['Alpha Sdn Bhd', 60123456789],
        ]);

        $rows = SpreadsheetReader::read($path, 'xlsx')['rows'];

        $this->assertSame('60123456789', $rows[2][1]);
    }

    /** A gap in the middle must not shift every later column one to the left. */
    public function test_a_blank_cell_keeps_the_columns_after_it_in_place(): void
    {
        $path = $this->makeXlsx([
            ['Vendor Name', 'Reg No', 'Email'],
            ['Alpha Sdn Bhd', '', 'lim@alpha.com.my'],
        ]);

        $rows = SpreadsheetReader::read($path, 'xlsx')['rows'];

        $this->assertSame(['Alpha Sdn Bhd', '', 'lim@alpha.com.my'], $rows[2]);
    }

    /**
     * A vendor list regularly sits behind a cover or instructions tab. Importing the cover
     * sheet would report "nothing recognisable in this file" about a file that is fine.
     */
    public function test_it_skips_a_cover_sheet_and_reads_the_one_with_data(): void
    {
        $path = $this->makeXlsx(
            [['Prepared by Finance']],
            [['Vendor Name', 'Email'], ['Alpha Sdn Bhd', 'lim@alpha.com.my']]
        );

        $result = SpreadsheetReader::read($path, 'xlsx');

        $this->assertSame('Data', $result['sheet']);
        $this->assertSame(['Vendor Name', 'Email'], $result['rows'][1]);
    }

    public function test_a_semicolon_separated_csv_is_not_read_as_one_giant_column(): void
    {
        $path = $this->makeTemp("Vendor Name;PIC;Email\nAlpha Sdn Bhd;Lim;lim@alpha.com.my\n", 'csv');

        $rows = SpreadsheetReader::read($path, 'csv')['rows'];

        $this->assertSame(['Vendor Name', 'PIC', 'Email'], $rows[1]);
    }

    /**
     * The old binary .xls is refused with the remedy, not half-read. It is still what Excel
     * produces from "Excel 97-2003", and every attempt to parse it here would surface as an
     * unhelpful "not a valid archive".
     */
    public function test_the_legacy_xls_format_is_refused_with_an_instruction(): void
    {
        $this->expectException(SpreadsheetReadException::class);
        $this->expectExceptionMessageMatches('/Save As.*xlsx/is');

        SpreadsheetReader::read($this->makeTemp('anything', 'xls'), 'xls');
    }

    /** A header of "Vendor\xA0Name" matches nothing, and nothing on screen says why. */
    public function test_non_breaking_spaces_are_cleaned_out_of_cells(): void
    {
        $this->assertSame('Vendor Name', SpreadsheetReader::clean("Vendor\xc2\xa0Name"));
    }

    // ── Header detection ──────────────────────────────────────────────────────
    public function test_the_header_row_is_found_below_a_title_and_a_blank_row(): void
    {
        $rows = [
            1 => ['CLARITAS ASIA — VENDOR MASTER LIST 2026'],
            3 => ['Company Name', 'SSM No.', 'Contact Person', 'H/P'],
            4 => ['Alpha Sdn Bhd', '123456-A', 'Lim', '012-345 6789'],
        ];

        $detected = ColumnMapper::detectHeaderRow($rows);

        $this->assertSame(3, $detected['line']);
        $this->assertTrue($detected['confident']);
    }

    /**
     * With nothing recognisable the page must SAY so rather than present the first data row
     * as headings and silently import one vendor fewer.
     */
    public function test_an_unrecognisable_sheet_reports_that_it_is_not_confident(): void
    {
        $detected = ColumnMapper::detectHeaderRow([
            1 => ['zzz', 'qqq'],
            2 => ['aaa', 'bbb'],
        ]);

        $this->assertFalse($detected['confident']);
        $this->assertSame(1, $detected['line'], 'falls back to the first row so the mapping table still renders');
    }

    // ── Column mapping ────────────────────────────────────────────────────────
    public function test_it_maps_the_headings_a_real_vendor_list_uses(): void
    {
        $mapping = ColumnMapper::map([
            'Company Name', 'SSM No.', 'Nature of Business', 'Contact Person',
            'H/P', 'Office No', 'Bank', 'A/C No', 'Remarks',
        ]);

        $fields = array_column($mapping, 'field');

        $this->assertSame([
            'name', 'company_registration_no', 'industry', 'pic_name',
            'pic_phone', 'contact_number', 'bank_name', 'bank_account_number', 'notes',
        ], $fields);
    }

    /**
     * A bare "Email" belongs to the PIC, not to the decorative company-level column.
     * pic_email is where the e-waste RFQ and the signed AARF copy are sent, so mapping the
     * one email column a sheet has to `email` leaves every automated notification with
     * nowhere to go — and nothing reports that.
     */
    public function test_a_lone_email_column_goes_to_the_pic_not_the_company_field(): void
    {
        $mapping = ColumnMapper::map(['Vendor', 'Email']);

        $this->assertSame('pic_email', $mapping[1]['field']);
    }

    /**
     * Longest-match-wins, or "Technical Contact Email" is taken by `email`'s "email" and
     * the support address is filed as the salesperson's.
     */
    public function test_a_more_specific_heading_is_not_stolen_by_a_shorter_synonym(): void
    {
        $mapping = ColumnMapper::map(['Vendor Name (as per SSM)', 'Technical Contact Email', 'Email']);

        $this->assertSame('name', $mapping[0]['field']);
        $this->assertSame('technical_person_email', $mapping[1]['field']);
        $this->assertSame('pic_email', $mapping[2]['field']);
    }

    /** Plenty of hand-kept lists caption nothing at all. */
    public function test_an_uncaptioned_column_is_identified_from_its_values(): void
    {
        $mapping = ColumnMapper::map(['Vendor', '', ''], [
            1 => ['lim@alpha.com.my', 'siti@beta.my', 'raj@gamma.com'],
            2 => ['012-345 6789', '03-7788 1234', '+60 19 222 3344'],
        ]);

        $this->assertSame('pic_email', $mapping[1]['field']);
        $this->assertSame('values', $mapping[1]['via'], 'the guess must be reported as a guess');
        $this->assertSame('pic_phone', $mapping[2]['field']);
    }

    /**
     * One stray address in a remarks column must not turn it into the address column —
     * every sniffer needs a clear majority.
     */
    public function test_one_stray_value_does_not_claim_a_column(): void
    {
        $mapping = ColumnMapper::map(['Vendor', ''], [
            1 => ['see contract', 'call first', 'lim@alpha.com.my', 'pending', 'n/a'],
        ]);

        $this->assertNull($mapping[1]['field']);
    }

    /**
     * The selects are rendered per column, so nothing stops an operator picking the same
     * field twice — and letting the second win would file whichever value is rightmost.
     */
    public function test_a_field_chosen_for_two_columns_is_kept_only_on_the_first(): void
    {
        $mapping = ColumnMapper::sanitiseSubmitted(
            [0 => 'name', 1 => 'pic_email', 2 => 'pic_email', 3 => 'not_a_field'],
            ['A', 'B', 'C', 'D']
        );

        $this->assertSame('pic_email', $mapping[1]['field']);
        $this->assertNull($mapping[2]['field']);
        $this->assertNull($mapping[3]['field'], 'a field name that does not exist is dropped, not stored');
    }

    // ── Row building ──────────────────────────────────────────────────────────
    public function test_it_resolves_several_service_types_from_one_cell(): void
    {
        $built = VendorRowBuilder::build(
            ['Alpha Sdn Bhd', 'Rental; Repair & Maintenance'],
            ['name' => 0, 'vendor_types' => 1]
        );

        $this->assertSame(['rental', 'repair'], $built['attributes']['vendor_types']);
        $this->assertSame([], $built['notes']);
    }

    /**
     * An unrecognised service type is NAMED, not dropped and not guessed from the vendor's
     * name. Guessing is how a telco gets filed under Utilities.
     */
    public function test_an_unrecognised_service_type_is_reported_rather_than_guessed(): void
    {
        $built = VendorRowBuilder::build(
            ['Alpha Sdn Bhd', 'Feng shui consultation for the lobby'],
            ['name' => 0, 'vendor_types' => 1]
        );

        $this->assertSame(['professional'], $built['attributes']['vendor_types']);

        $built = VendorRowBuilder::build(
            ['Beta Sdn Bhd', 'Zzzz Qqqq'],
            ['name' => 0, 'vendor_types' => 1]
        );

        $this->assertSame(['other'], $built['attributes']['vendor_types']);
        $this->assertStringContainsString('not recognised', $built['notes'][0]);
        $this->assertStringContainsString('Zzzz Qqqq', $built['notes'][0]);
    }

    /**
     * SST decides whether an invoice's tax line gets flagged, so a self-contradictory cell
     * is recorded as NOT RECORDED. "Not recorded" and "not registered" are different
     * answers, and picking either half of the contradiction states something the sheet did
     * not say.
     */
    public function test_a_contradictory_sst_cell_is_left_blank_rather_than_resolved(): void
    {
        $built = VendorRowBuilder::build(
            ['Alpha Sdn Bhd', 'Not registered / Group G'],
            ['name' => 0, 'sst_categories' => 1]
        );

        $this->assertArrayNotHasKey('sst_categories', $built['attributes']);
        // Searched across all notes, not notes[0]: a row collects notes from several fields
        // and pinning one to a position makes the test fail when an unrelated field speaks up.
        $this->assertStringContainsString('rather than guessing', implode(' | ', $built['notes']));
    }

    public function test_a_group_letter_on_its_own_resolves_to_the_right_category(): void
    {
        $built = VendorRowBuilder::build(
            ['Alpha Sdn Bhd', 'Group K'],
            ['name' => 0, 'sst_categories' => 1]
        );

        $this->assertSame(['rental_leasing'], $built['attributes']['sst_categories']);
    }

    /**
     * Every one of our own category labels has to survive the round trip, because the sheet
     * an operator is most likely to hand us is the one they built from this system's own
     * dropdown. They arrive comma-SPLIT into fragments ("Group B — Food & beverage
     * (restaurants" / "cafés" / …), so each fragment has to confirm its own letter — which is
     * the exact path the clash check below runs down.
     */
    public function test_every_category_label_this_system_offers_still_reads_back(): void
    {
        foreach (\App\Models\Vendor::sstCategories() as $key => $label) {
            $built = VendorRowBuilder::build(
                ['Alpha Sdn Bhd', $label],
                ['name' => 0, 'sst_categories' => 1]
            );

            $this->assertSame(
                [$key],
                $built['attributes']['sst_categories'] ?? null,
                'The label for "'.$key.'" no longer reads back as itself: '.$label
            );
        }
    }

    /**
     * A cell naming a group letter under WORDING WE DO NOT USE cannot be resolved either way:
     * taking the letter files the vendor under a group the sheet does not describe, and
     * taking the description ignores the one part of the cell that is a statutory identity.
     *
     * This is the field the B2B exemption turns on, so it is left blank — but the message has
     * to name what the letter means HERE, or the operator has no way to tell a wording
     * difference from a value the importer simply does not know.
     */
    public function test_a_group_letter_described_differently_is_left_blank_and_names_the_clash(): void
    {
        $built = VendorRowBuilder::build(
            ['Alpha Sdn Bhd', 'Group K — Other taxable services'],
            ['name' => 0, 'sst_categories' => 1]
        );

        $this->assertArrayNotHasKey('sst_categories', $built['attributes']);

        $notes = implode(' | ', $built['notes']);
        $this->assertStringContainsString('Group K here is', $notes);
        $this->assertStringContainsString('Rental or leasing services', $notes);
    }

    /**
     * The description is judged against the LETTER, not against the rest of the list. Without
     * this, "Group K — Other service providers" resolves on the description alone and files
     * the vendor under Group I while the cell says K.
     */
    public function test_a_description_naming_another_group_does_not_override_the_letter(): void
    {
        $built = VendorRowBuilder::build(
            ['Alpha Sdn Bhd', 'Group K — Other service providers'],
            ['name' => 0, 'sst_categories' => 1]
        );

        $this->assertArrayNotHasKey('sst_categories', $built['attributes']);
    }

    /**
     * `professional` carries the hint word `it` (its label ends "IT & digital"). As a plain
     * substring that matches inside "facILITies", "securITy", "kITchen" and "recruITment", so
     * any of those cells silently filed the vendor under Group G — the group that decides
     * whether their invoices get an SST flag. A wrong tax group is worse than a blank one.
     */
    public function test_a_short_hint_word_cannot_match_inside_a_longer_word(): void
    {
        foreach (['Facilities Management', 'Security services', 'Recruitment'] as $value) {
            $built = VendorRowBuilder::build(
                ['Alpha Sdn Bhd', $value],
                ['name' => 0, 'sst_categories' => 1]
            );

            $this->assertArrayNotHasKey(
                'sst_categories',
                $built['attributes'],
                '"'.$value.'" was resolved to an SST group by a hint word buried inside a longer word.'
            );
        }

        // The hint must still do the job it was added for.
        $built = VendorRowBuilder::build(
            ['Alpha Sdn Bhd', 'IT & digital'],
            ['name' => 0, 'sst_categories' => 1]
        );

        $this->assertSame(['professional'], $built['attributes']['sst_categories']);
    }

    /**
     * Longer hints stay substrings, which is what lets "rent" reach "rental" and "club" reach
     * "clubs". Whole-word matching does not generalise to them, so both halves are pinned.
     */
    public function test_a_longer_hint_word_still_matches_inside_a_word(): void
    {
        $built = VendorRowBuilder::build(
            ['Alpha Sdn Bhd', 'Equipment rental'],
            ['name' => 0, 'sst_categories' => 1]
        );

        $this->assertSame(['rental_leasing'], $built['attributes']['sst_categories']);
    }

    /**
     * The industry list carries no neutral "IT" entry, so a vendor described the way vendors
     * describe themselves had its industry silently dropped. Hardware must keep winning on
     * the values that say hardware.
     */
    public function test_a_generic_it_wording_resolves_to_an_industry(): void
    {
        foreach (['Information Technology', 'IT Services', 'ICT'] as $value) {
            $built = VendorRowBuilder::build(
                ['Alpha Sdn Bhd', $value],
                ['name' => 0, 'industry' => 1]
            );

            $this->assertSame('it_software', $built['attributes']['industry'] ?? null, $value);
        }

        foreach (['IT Hardware', 'Computer Hardware'] as $value) {
            $built = VendorRowBuilder::build(
                ['Alpha Sdn Bhd', $value],
                ['name' => 0, 'industry' => 1]
            );

            $this->assertSame('it_hardware', $built['attributes']['industry'] ?? null, $value);
        }
    }

    /**
     * An address a mail server would refuse is worse than a blank one: the failure surfaces
     * later, as a notification that silently never arrived.
     */
    public function test_an_invalid_email_is_dropped_and_said_so(): void
    {
        $built = VendorRowBuilder::build(
            ['Alpha Sdn Bhd', 'ask the boss'],
            ['name' => 0, 'pic_email' => 1]
        );

        $this->assertArrayNotHasKey('pic_email', $built['attributes']);
        $this->assertStringContainsString('not a valid email', $built['notes'][0]);
    }

    public function test_an_address_is_lifted_out_of_a_cell_that_also_holds_a_name(): void
    {
        $built = VendorRowBuilder::build(
            ['Alpha Sdn Bhd', 'Lim Wei Ming <lim@alpha.com.my>'],
            ['name' => 0, 'pic_email' => 1]
        );

        $this->assertSame('lim@alpha.com.my', $built['attributes']['pic_email']);
    }

    public function test_a_row_with_no_vendor_name_cannot_be_imported(): void
    {
        $built = VendorRowBuilder::build(['', 'lim@alpha.com.my'], ['name' => 0, 'pic_email' => 1]);

        $this->assertNotNull($built['error']);
        $this->assertSame([], $built['attributes']);
    }

    /**
     * A value longer than its column must be shortened HERE, with a note — reaching the
     * database it would abort the whole transaction over one over-long address, and the
     * operator would be told nothing useful about which row did it.
     */
    public function test_an_over_long_value_is_shortened_rather_than_failing_the_import(): void
    {
        $built = VendorRowBuilder::build(
            ['Alpha Sdn Bhd', str_repeat('x', 90)],
            ['name' => 0, 'sst_number' => 1]
        );

        $this->assertSame(60, mb_strlen($built['attributes']['sst_number']));
        $this->assertStringContainsString('shortened', $built['notes'][0]);
    }

    /**
     * A sheet with no service-type column produces "Other" for a NEW vendor — but that is a
     * default, not a reading, and the update path must be able to tell the difference or it
     * re-tags a carefully categorised vendor as Other.
     */
    public function test_a_defaulted_service_type_is_flagged_as_defaulted(): void
    {
        $built = VendorRowBuilder::build(['Alpha Sdn Bhd'], ['name' => 0]);

        $this->assertSame(['other'], $built['attributes']['vendor_types']);
        $this->assertContains('vendor_types', $built['defaulted']);

        $read = VendorRowBuilder::build(['Alpha Sdn Bhd', 'Rental'], ['name' => 0, 'vendor_types' => 1]);
        $this->assertSame([], $read['defaulted']);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────
    private function makeTemp(string $contents, string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vndimp').'.'.$extension;
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * Build a real .xlsx by hand — a zip of the OOXML parts the reader consumes. Writing the
     * fixture the same way Excel does is the only way this suite proves the reader works on
     * the format rather than on a shape of our own invention.
     *
     * @param  list<list<string|int>>  ...$sheets
     */
    private function makeXlsx(array ...$sheets): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vndimp').'.xlsx';
        $this->tempFiles[] = $path;

        $names = ['Vendors', 'Data', 'Extra'];
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $sheetTags = '';
        $relTags = '';

        foreach ($sheets as $i => $rows) {
            $n = $i + 1;
            $sheetTags .= '<sheet name="'.$names[$i].'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
            $relTags .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
            $zip->addFromString('xl/worksheets/sheet'.$n.'.xml', $this->sheetXml($rows));
        }

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$sheetTags.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$relTags.'</Relationships>');

        $zip->close();

        return $path;
    }

    /**
     * Inline strings rather than a shared table: both are valid OOXML and the reader handles
     * each, and this keeps the fixture readable.
     *
     * @param  list<list<string|int>>  $rows
     */
    private function sheetXml(array $rows): string
    {
        $xml = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        foreach ($rows as $r => $cells) {
            $line = $r + 1;

            if ($cells === []) {
                continue;   // a blank row is simply absent from the XML, as Excel writes it
            }

            $xml .= '<row r="'.$line.'">';

            foreach ($cells as $c => $value) {
                if ($value === '') {
                    continue;   // and so is a blank cell
                }

                $ref = chr(65 + $c).$line;

                $xml .= is_int($value)
                    ? '<c r="'.$ref.'"><v>'.$value.'</v></c>'
                    : '<c r="'.$ref.'" t="inlineStr"><is><t>'.htmlspecialchars((string) $value, ENT_XML1).'</t></is></c>';
            }

            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }
}
