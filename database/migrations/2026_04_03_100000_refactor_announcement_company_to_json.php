<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add new json column
        Schema::table('announcements', function (Blueprint $table) {
            $table->json('companies')->nullable()->after('body');
        });

        // Backfill: convert existing single company string → ['company'] array
        DB::table('announcements')->whereNotNull('company')->get()
            ->each(function ($row) {
                DB::table('announcements')->where('id', $row->id)
                    ->update(['companies' => json_encode([$row->company])]);
            });

        // Drop old column
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn('company');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->string('company')->nullable()->after('body');
        });

        // Backfill back: take first element of companies array
        DB::table('announcements')->whereNotNull('companies')->get()
            ->each(function ($row) {
                $arr = json_decode($row->companies, true);
                DB::table('announcements')->where('id', $row->id)
                    ->update(['company' => $arr[0] ?? null]);
            });

        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn('companies');
        });
    }
};
