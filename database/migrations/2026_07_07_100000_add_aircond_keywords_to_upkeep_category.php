<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The eClaim OCR classifier missed aircond-service invoices because the "Upkeep of Office
 * & Warehouse" (903-000) keyword list only had "aircon" — which the word-boundary matcher
 * won't match against the common Malaysian spelling "aircond". Backfill the fuller service
 * keyword set on existing rows (the seeder already carries it for fresh installs). Merges
 * without clobbering any customised keywords, and is safe to re-run.
 */
return new class extends Migration
{
    private array $add = ['aircond', 'cleaning', 'wiring', 'plumbing', 'pest control', 'renovation'];

    public function up(): void
    {
        foreach (DB::table('expense_categories')->where('code', '903-000')->get() as $cat) {
            $existing = json_decode($cat->keywords ?? '[]', true);
            $existing = is_array($existing) ? $existing : [];
            $merged = array_values(array_unique(array_merge($existing, $this->add)));
            DB::table('expense_categories')->where('id', $cat->id)->update(['keywords' => json_encode($merged)]);
        }
    }

    public function down(): void
    {
        // Non-destructive: leave the added keywords in place (removing them could drop
        // keywords a user later added by hand, and they cause no harm).
    }
};
