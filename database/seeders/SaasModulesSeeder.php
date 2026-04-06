<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\LeaveType;
use App\Models\LeaveEntitlement;
use App\Models\PayrollItem;
use App\Models\WorkSchedule;

class SaasModulesSeeder extends Seeder
{
    public function run(): void
    {
        // ── Leave Types (Malaysian standard) ───────────────────────────────
        $leaveTypes = [
            ['name' => 'Annual Leave',       'code' => 'AL',   'is_paid' => true,  'requires_attachment' => false, 'sort_order' => 1],
            ['name' => 'Sick Leave',         'code' => 'SL',   'is_paid' => true,  'requires_attachment' => true,  'sort_order' => 2],
            ['name' => 'Medical Leave',      'code' => 'ML',   'is_paid' => true,  'requires_attachment' => true,  'sort_order' => 3],
            ['name' => 'Hospitalisation Leave','code' => 'HL', 'is_paid' => true,  'requires_attachment' => true,  'sort_order' => 4],
            ['name' => 'Compassionate Leave','code' => 'CL',   'is_paid' => true,  'requires_attachment' => false, 'sort_order' => 5],
            ['name' => 'Maternity Leave',    'code' => 'MATL', 'is_paid' => true,  'requires_attachment' => true,  'sort_order' => 6],
            ['name' => 'Paternity Leave',    'code' => 'PATL', 'is_paid' => true,  'requires_attachment' => true,  'sort_order' => 7],
            ['name' => 'Replacement Leave',  'code' => 'RL',   'is_paid' => true,  'requires_attachment' => false, 'sort_order' => 8],
            ['name' => 'Unpaid Leave',       'code' => 'UL',   'is_paid' => false, 'requires_attachment' => false, 'sort_order' => 9],
        ];

        foreach ($leaveTypes as $lt) {
            LeaveType::create($lt);
        }

        // ── Leave Entitlements (EA 1955 minimum) ───────────────────────────
        $al = LeaveType::where('code', 'AL')->first();
        LeaveEntitlement::create(['leave_type_id' => $al->id, 'min_tenure_months' => 0,  'max_tenure_months' => 24,  'entitled_days' => 8,  'carry_forward_limit' => 5]);
        LeaveEntitlement::create(['leave_type_id' => $al->id, 'min_tenure_months' => 24, 'max_tenure_months' => 60,  'entitled_days' => 12, 'carry_forward_limit' => 5]);
        LeaveEntitlement::create(['leave_type_id' => $al->id, 'min_tenure_months' => 60, 'max_tenure_months' => 999, 'entitled_days' => 16, 'carry_forward_limit' => 8]);

        $sl = LeaveType::where('code', 'SL')->first();
        LeaveEntitlement::create(['leave_type_id' => $sl->id, 'min_tenure_months' => 0,  'max_tenure_months' => 24,  'entitled_days' => 14, 'carry_forward_limit' => 0]);
        LeaveEntitlement::create(['leave_type_id' => $sl->id, 'min_tenure_months' => 24, 'max_tenure_months' => 60,  'entitled_days' => 18, 'carry_forward_limit' => 0]);
        LeaveEntitlement::create(['leave_type_id' => $sl->id, 'min_tenure_months' => 60, 'max_tenure_months' => 999, 'entitled_days' => 22, 'carry_forward_limit' => 0]);

        $hl = LeaveType::where('code', 'HL')->first();
        LeaveEntitlement::create(['leave_type_id' => $hl->id, 'min_tenure_months' => 0, 'max_tenure_months' => 999, 'entitled_days' => 60, 'carry_forward_limit' => 0]);

        $cl = LeaveType::where('code', 'CL')->first();
        LeaveEntitlement::create(['leave_type_id' => $cl->id, 'min_tenure_months' => 0, 'max_tenure_months' => 999, 'entitled_days' => 3, 'carry_forward_limit' => 0]);

        $matl = LeaveType::where('code', 'MATL')->first();
        LeaveEntitlement::create(['leave_type_id' => $matl->id, 'min_tenure_months' => 0, 'max_tenure_months' => 999, 'entitled_days' => 98, 'carry_forward_limit' => 0]);

        $patl = LeaveType::where('code', 'PATL')->first();
        LeaveEntitlement::create(['leave_type_id' => $patl->id, 'min_tenure_months' => 0, 'max_tenure_months' => 999, 'entitled_days' => 7, 'carry_forward_limit' => 0]);

        // ── Payroll Items (Malaysian statutory) ────────────────────────────
        $payrollItems = [
            ['name' => 'EPF (Employee)',  'code' => 'EPF_EE',   'type' => 'deduction', 'is_statutory' => true,  'is_recurring' => true],
            ['name' => 'EPF (Employer)',  'code' => 'EPF_ER',   'type' => 'deduction', 'is_statutory' => true,  'is_recurring' => true],
            ['name' => 'SOCSO (Employee)','code' => 'SOCSO_EE', 'type' => 'deduction', 'is_statutory' => true,  'is_recurring' => true],
            ['name' => 'SOCSO (Employer)','code' => 'SOCSO_ER', 'type' => 'deduction', 'is_statutory' => true,  'is_recurring' => true],
            ['name' => 'EIS (Employee)',  'code' => 'EIS_EE',   'type' => 'deduction', 'is_statutory' => true,  'is_recurring' => true],
            ['name' => 'EIS (Employer)',  'code' => 'EIS_ER',   'type' => 'deduction', 'is_statutory' => true,  'is_recurring' => true],
            ['name' => 'PCB / MTD',       'code' => 'PCB',      'type' => 'deduction', 'is_statutory' => true,  'is_recurring' => true],
            ['name' => 'HRDF',            'code' => 'HRDF',     'type' => 'deduction', 'is_statutory' => true,  'is_recurring' => true],
            ['name' => 'Transport Allowance', 'code' => 'TRANSPORT', 'type' => 'earning', 'is_statutory' => false, 'is_recurring' => true],
            ['name' => 'Meal Allowance',      'code' => 'MEAL',      'type' => 'earning', 'is_statutory' => false, 'is_recurring' => true],
            ['name' => 'Phone Allowance',     'code' => 'PHONE',     'type' => 'earning', 'is_statutory' => false, 'is_recurring' => true],
            ['name' => 'Parking Allowance',   'code' => 'PARKING',   'type' => 'earning', 'is_statutory' => false, 'is_recurring' => true],
            ['name' => 'Overtime',             'code' => 'OT',        'type' => 'earning', 'is_statutory' => false, 'is_recurring' => false],
            ['name' => 'Bonus',                'code' => 'BONUS',     'type' => 'earning', 'is_statutory' => false, 'is_recurring' => false],
        ];

        foreach ($payrollItems as $pi) {
            PayrollItem::create($pi);
        }

        // ── Default Work Schedule ──────────────────────────────────────────
        WorkSchedule::create([
            'name'               => 'Standard Office Hours',
            'start_time'         => '09:00',
            'end_time'           => '18:00',
            'break_start'        => '13:00',
            'break_end'          => '14:00',
            'work_hours_per_day' => 8,
            'working_days'       => json_encode([1, 2, 3, 4, 5]), // Mon-Fri
            'is_default'         => true,
        ]);
    }
}
