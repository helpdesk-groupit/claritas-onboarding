<?php

namespace App\Support\VendorImport;

use RuntimeException;

/**
 * The uploaded file could not be turned into rows.
 *
 * Always carries a sentence fit to show an operator — "what do I do about it" — because the
 * upload form prints it verbatim. A stack-trace-flavoured message ("ZipArchive::open
 * returned 19") tells the person holding the spreadsheet nothing they can act on.
 */
class SpreadsheetReadException extends RuntimeException {}
