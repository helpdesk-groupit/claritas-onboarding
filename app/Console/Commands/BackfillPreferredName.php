<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Employee;

class BackfillPreferredName extends Command
{
    protected $signature   = 'employees:backfill-preferred-name';
    protected $description = 'Backfill preferred_name from personal_details into existing employee records';

    public function handle(): void
    {
        $employees = Employee::with('onboarding.personalDetail')
            ->whereNull('preferred_name')
            ->get();

        $updated = 0;
        foreach ($employees as $emp) {
            $preferred = $emp->onboarding?->personalDetail?->preferred_name;
            if ($preferred) {
                $emp->update(['preferred_name' => $preferred]);
                $updated++;
            }
        }

        $this->info("Done. Updated preferred_name for {$updated} employee(s).");
    }
}