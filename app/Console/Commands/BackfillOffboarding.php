<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Offboarding;
use Illuminate\Console\Command;

class BackfillOffboarding extends Command
{
    protected $signature   = 'offboarding:backfill';
    protected $description = 'Create missing offboarding records for resigned/terminated employees';

    public function handle(): void
    {
        $leaving = Employee::whereIn('employment_status', ['resigned', 'terminated', 'contract_ended'])
            ->whereNotNull('exit_date')
            ->get();

        $this->info("Found {$leaving->count()} resigned/terminated employee(s) with exit dates.");

        $created = 0;
        $skipped = 0;

        foreach ($leaving as $emp) {
            // Check if offboarding record already exists
            $exists = Offboarding::where(function ($q) use ($emp) {
                if ($emp->onboarding_id) {
                    $q->where('onboarding_id', $emp->onboarding_id);
                } else {
                    $q->where('employee_id', $emp->id);
                }
            })->exists();

            if ($exists) {
                $this->line("  Skipped (already exists): {$emp->full_name}");
                $skipped++;
                continue;
            }

            try {
                Offboarding::createFromEmployee($emp);
                $this->info("  Created offboarding record for: {$emp->full_name} (exit: {$emp->exit_date->format('d M Y')})");
                $created++;
            } catch (\Throwable $e) {
                $this->error("  FAILED for {$emp->full_name}: " . $e->getMessage());
            }
        }

        $this->info("Done. Created: {$created}, Already existed: {$skipped}.");
    }
}