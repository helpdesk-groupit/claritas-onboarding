<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Find all resigned/terminated/contract_ended employees with no offboarding record
        $employees = DB::table('employees')
            ->whereIn('employment_status', ['resigned', 'terminated', 'contract_ended'])
            ->whereNull('active_until') // still in employee listing
            ->get();

        foreach ($employees as $emp) {
            // Check if offboarding record already exists
            $exists = DB::table('offboardings')
                ->where(function($q) use ($emp) {
                    if ($emp->onboarding_id) {
                        $q->where('onboarding_id', $emp->onboarding_id);
                    } else {
                        $q->where('employee_id', $emp->id);
                    }
                })
                ->exists();

            if (!$exists) {
                DB::table('offboardings')->insert([
                    'onboarding_id'  => $emp->onboarding_id,
                    'employee_id'    => $emp->id,
                    'full_name'      => $emp->full_name,
                    'company'        => $emp->company,
                    'department'     => $emp->department,
                    'designation'    => $emp->designation,
                    'company_email'  => $emp->company_email,
                    'personal_email' => $emp->personal_email,
                    'exit_date'      => $emp->exit_date, // may be null
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Not reversible — backfill records are legitimate data
    }
};