<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seed Malaysian national public holidays for 2026.
     * Based on Employment Act 1955 s.60D — minimum 11 gazetted public holidays.
     * Only inserts if no public holidays exist for 2026 yet.
     */
    public function up(): void
    {
        $existing = DB::table('public_holidays')->where('year', 2026)->count();
        if ($existing > 0) {
            return;
        }

        $now = now();

        // Malaysian gazetted public holidays 2026
        // Dates for Islamic holidays are approximate (subject to moon sighting)
        $holidays = [
            ['name' => 'New Year\'s Day',                   'date' => '2026-01-01', 'is_recurring' => true],
            ['name' => 'Thaipusam',                         'date' => '2026-01-25', 'is_recurring' => false],
            ['name' => 'Israk and Mikraj',                  'date' => '2026-02-06', 'is_recurring' => false],
            ['name' => 'Nuzul Al-Quran',                    'date' => '2026-03-09', 'is_recurring' => false],
            ['name' => 'Hari Raya Aidilfitri',              'date' => '2026-03-30', 'is_recurring' => false],
            ['name' => 'Hari Raya Aidilfitri (2nd Day)',    'date' => '2026-03-31', 'is_recurring' => false],
            ['name' => 'Labour Day',                        'date' => '2026-05-01', 'is_recurring' => true],
            ['name' => 'Wesak Day',                         'date' => '2026-05-12', 'is_recurring' => false],
            ['name' => 'Yang di-Pertuan Agong Birthday',    'date' => '2026-06-01', 'is_recurring' => false],
            ['name' => 'Hari Raya Haji',                    'date' => '2026-06-06', 'is_recurring' => false],
            ['name' => 'Hari Raya Haji (2nd Day)',          'date' => '2026-06-07', 'is_recurring' => false],
            ['name' => 'Awal Muharram',                     'date' => '2026-06-27', 'is_recurring' => false],
            ['name' => 'Malaysia Day',                      'date' => '2026-09-16', 'is_recurring' => true],
            ['name' => 'Maulidur Rasul',                    'date' => '2026-09-05', 'is_recurring' => false],
            ['name' => 'Deepavali',                         'date' => '2026-10-20', 'is_recurring' => false],
            ['name' => 'Christmas Day',                     'date' => '2026-12-25', 'is_recurring' => true],
            ['name' => 'Merdeka Day',                       'date' => '2026-08-31', 'is_recurring' => true],
        ];

        foreach ($holidays as $holiday) {
            DB::table('public_holidays')->insert([
                'company'      => null,
                'name'         => $holiday['name'],
                'date'         => $holiday['date'],
                'year'         => 2026,
                'is_recurring' => $holiday['is_recurring'],
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('public_holidays')->where('year', 2026)->whereNull('company')->delete();
    }
};
