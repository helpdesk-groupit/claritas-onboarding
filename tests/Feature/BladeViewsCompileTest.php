<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Every Blade template must compile to PHP that actually parses.
 *
 * This guards a bug class this codebase keeps re-discovering the expensive way — in
 * production, as a 500 on a page nobody touched. `php artisan view:cache` does NOT catch it:
 * it compiles Blade to PHP and writes the result WITHOUT checking the result is valid PHP, so
 * a broken template caches "successfully" and then fatals the first time somebody opens it.
 *
 * Two known shapes, both of which produce a template that looks fine in the editor:
 *
 *  1. `@`-GLUING — Blade's directive pattern uses \B, so an `@endif` glued to the end of a
 *     word (`...quotation@endif`) compiles through as literal text. CLAUDE.md records four
 *     occurrences; the quietest one silently dropped half a feature with no error at all.
 *
 *  2. AN INLINE php DIRECTIVE BESIDE A php BLOCK — raw php blocks are stored with a dotall,
 *     non-greedy /(?<!@)@php(.*?)@endphp/, so an inline "at-php(...)" pairs with a LATER
 *     "at-endphp" and swallows every line between them into one PHP block. On 2026-09-05 this
 *     ate an <iframe> in claim-report-form.blade.php and 500'd the claim review page in
 *     production, hours after "Blade templates cached successfully". (Written here without a
 *     literal leading at-sign: PHP-CS-Fixer reads one at the start of a docblock line as a
 *     PHPDoc tag and reflows the paragraph around it.)
 *
 * Parsing is done with token_get_all(..., TOKEN_PARSE), which raises \ParseError on invalid
 * syntax in-process — no 350 subprocesses, so the whole sweep costs about a second.
 */
class BladeViewsCompileTest extends TestCase
{
    public function test_every_blade_template_compiles_to_parsable_php(): void
    {
        $roots = array_filter([
            resource_path('views'),
        ], 'is_dir');

        $this->assertNotEmpty($roots, 'No view directory found to check.');

        $files = Finder::create()->files()->in($roots)->name('*.blade.php');
        $broken = [];
        $checked = 0;

        foreach ($files as $file) {
            $checked++;
            $compiled = Blade::compileString((string) file_get_contents($file->getRealPath()));

            try {
                // The compiled output is a PHP *file* body, so it is parsed as one: the
                // template's leading markup is inline HTML until the first <?php.
                token_get_all('?>'.$compiled, TOKEN_PARSE);
            } catch (\ParseError $e) {
                $broken[] = $file->getRelativePathname().' — '.$e->getMessage();
            }
        }

        $this->assertGreaterThan(100, $checked, 'Suspiciously few templates were checked.');
        $this->assertSame([], $broken, "These Blade templates compile to invalid PHP:\n- ".implode("\n- ", $broken));
    }
}
