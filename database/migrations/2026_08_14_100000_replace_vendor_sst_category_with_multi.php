<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A vendor is registered under one OR MORE taxable-service groups, not one.
 *
 * `sst_category` (string) could only ever hold the first of them, so a vendor registered
 * under both Group G (professional) and Group K (rental) had to be filed under one and the
 * other silently lost — which is exactly the half of the record the B2B exemption turns on.
 *
 * Add → copy rather than widening the column in place, the same shape used for
 * `rental_vendor_id` → `vendor_id`: the plural name is what makes every read site obviously
 * in need of updating, instead of a singular column quietly returning JSON to code that
 * still expects a string. Mirrors `vendor_types`: json, cast to array, whereJsonContains.
 *
 * THE DROP IS DELIBERATELY DEFERRED — this is the EXPAND half only.
 * The release that introduces it rewrites every reader onto `sst_categories`, so the old
 * column is dead the moment this runs. It is nevertheless left in place for one release,
 * because the PREVIOUSLY deployed code still reads `vendors.sst_category` in ~10 places
 * (`Vendor::$fillable`, `sstCategoryLabel()`, `sstVerdict()`, `VendorController` validation,
 * the registration form, the profile partial). Dropping it here would mean a rollback to
 * that code could only be done by ALSO reversing this migration by hand over SSH — and that
 * reversal is lossy, since a vendor registered under several groups cannot be expressed by
 * the old column at all. Keeping one dead column for one release buys a rollback that is a
 * single `git push`, with no schema surgery and nothing lost.
 *
 * FOLLOW-UP: once this release has held on live, a separate contract migration drops
 * `sst_category` and its index. Nothing reads the column in the meantime. The index is
 * dropped with it and deliberately not recreated on `sst_categories` — nothing filters or
 * searches on the category (the directory searches name/PIC/reg. no/SST no/TIN), and a
 * generated column would be the tool for that if it is ever wanted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('vendors', 'sst_categories')) {
            Schema::table('vendors', function (Blueprint $table) {
                $table->json('sst_categories')->nullable()->after('sst_number');
            });
        }

        // Every existing row carries at most one category, so the backfill is exact —
        // nothing is inferred and nothing is lost. Guarded on the source column still
        // being present so this stays runnable after the follow-up contract migration.
        if (Schema::hasColumn('vendors', 'sst_category')) {
            foreach (DB::table('vendors')->select('id', 'sst_category')->get() as $row) {
                if (filled($row->sst_category)) {
                    DB::table('vendors')->where('id', $row->id)
                        ->update(['sst_categories' => json_encode([$row->sst_category])]);
                }
            }
        }
    }

    public function down(): void
    {
        // `sst_category` was never dropped here, so there is nothing to restore — it still
        // holds exactly what it held before this ran. Reversing is therefore clean and,
        // unlike the drop-and-restore shape, loses nothing.
        if (Schema::hasColumn('vendors', 'sst_categories')) {
            Schema::table('vendors', function (Blueprint $table) {
                $table->dropColumn('sst_categories');
            });
        }
    }
};
