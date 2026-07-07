<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Grocery / mini-market receipts (99 Speedmart, KK Mart, etc.) of food, milk, snacks and
 * pantry items were being classified as Printing & Stationery. Enrich the "Office Food &
 * Refreshment" (922-000) keyword list with grocery-item and convenience-store terms so the
 * deterministic override catches them (the seeder carries the same set for fresh installs).
 * Merges without clobbering; safe to re-run.
 */
return new class extends Migration
{
    private array $add = [
        'mineral water', 'milk', 'groceries', 'grocery', 'provision', 'sundry', 'biscuit', 'snack',
        'bread', 'honey', 'madu', 'speed mart', 'speedmart', 'kk mart', 'kk super mart', 'mynews',
        'mini market', 'minimarket',
    ];

    public function up(): void
    {
        foreach (DB::table('expense_categories')->where('code', '922-000')->get() as $cat) {
            $existing = json_decode($cat->keywords ?? '[]', true);
            $existing = is_array($existing) ? $existing : [];
            $merged = array_values(array_unique(array_merge($existing, $this->add)));
            DB::table('expense_categories')->where('id', $cat->id)->update(['keywords' => json_encode($merged)]);
        }
    }

    public function down(): void
    {
        // Non-destructive: keep the added keywords.
    }
};
