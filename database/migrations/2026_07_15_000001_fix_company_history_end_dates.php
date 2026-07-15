<?php

use App\Models\EmployeeCompanyHistory;
use Illuminate\Database\Migrations\Migration;

/**
 * Company-timeline stints were originally closed at the SAME date the next company started (the
 * boundary), so a closed stint and its successor shared that date (looked like the person was at
 * both companies on the change day). The convention is now "the old company's last day is the day
 * BEFORE the new one starts". Back-fill existing closed stints by moving ended_on back one day,
 * clamped so it never falls before the stint's own start (zero-length stints stay valid).
 */
return new class extends Migration
{
    public function up(): void
    {
        EmployeeCompanyHistory::whereNotNull('ended_on')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $end = \Carbon\Carbon::parse($row->ended_on)->subDay();
                $start = \Carbon\Carbon::parse($row->started_on);
                if ($end->lt($start)) {
                    $end = $start->copy();
                }
                $row->newQuery()->whereKey($row->getKey())->update(['ended_on' => $end->toDateString()]);
            }
        });
    }

    public function down(): void
    {
        // Restore the boundary convention (ended_on = day the next company started = ended_on + 1).
        EmployeeCompanyHistory::whereNotNull('ended_on')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $row->newQuery()->whereKey($row->getKey())
                    ->update(['ended_on' => \Carbon\Carbon::parse($row->ended_on)->addDay()->toDateString()]);
            }
        });
    }
};
