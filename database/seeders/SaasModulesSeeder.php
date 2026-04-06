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
        // Annual Leave – s.60E EA 1955
        $al = LeaveType::where('code', 'AL')->first();
        LeaveEntitlement::create(['leave_type_id' => $al->id, 'min_tenure_months' => 0,  'max_tenure_months' => 24,  'entitled_days' => 8,  'carry_forward_limit' => 5]);
        LeaveEntitlement::create(['leave_type_id' => $al->id, 'min_tenure_months' => 24, 'max_tenure_months' => 60,  'entitled_days' => 12, 'carry_forward_limit' => 5]);
        LeaveEntitlement::create(['leave_type_id' => $al->id, 'min_tenure_months' => 60, 'max_tenure_months' => null, 'entitled_days' => 16, 'carry_forward_limit' => 8]);

        // Sick Leave (outpatient) – s.60F(1)(a) EA 1955
        $sl = LeaveType::where('code', 'SL')->first();
        LeaveEntitlement::create(['leave_type_id' => $sl->id, 'min_tenure_months' => 0,  'max_tenure_months' => 24,  'entitled_days' => 14, 'carry_forward_limit' => 0]);
        LeaveEntitlement::create(['leave_type_id' => $sl->id, 'min_tenure_months' => 24, 'max_tenure_months' => 60,  'entitled_days' => 18, 'carry_forward_limit' => 0]);
        LeaveEntitlement::create(['leave_type_id' => $sl->id, 'min_tenure_months' => 60, 'max_tenure_months' => null, 'entitled_days' => 22, 'carry_forward_limit' => 0]);

        // Medical Leave (same as Sick Leave for employers who separate them) – s.60F
        $ml = LeaveType::where('code', 'ML')->first();
        if ($ml) {
            LeaveEntitlement::create(['leave_type_id' => $ml->id, 'min_tenure_months' => 0,  'max_tenure_months' => 24,  'entitled_days' => 14, 'carry_forward_limit' => 0]);
            LeaveEntitlement::create(['leave_type_id' => $ml->id, 'min_tenure_months' => 24, 'max_tenure_months' => 60,  'entitled_days' => 18, 'carry_forward_limit' => 0]);
            LeaveEntitlement::create(['leave_type_id' => $ml->id, 'min_tenure_months' => 60, 'max_tenure_months' => null, 'entitled_days' => 22, 'carry_forward_limit' => 0]);
        }

        // Hospitalisation Leave – s.60F(1)(bb) EA 1955 (60 days inclusive of sick leave)
        $hl = LeaveType::where('code', 'HL')->first();
        LeaveEntitlement::create(['leave_type_id' => $hl->id, 'min_tenure_months' => 0, 'max_tenure_months' => null, 'entitled_days' => 60, 'carry_forward_limit' => 0]);

        // Compassionate Leave – not in EA 1955, common practice 3 days
        $cl = LeaveType::where('code', 'CL')->first();
        LeaveEntitlement::create(['leave_type_id' => $cl->id, 'min_tenure_months' => 0, 'max_tenure_months' => null, 'entitled_days' => 3, 'carry_forward_limit' => 0]);

        // Maternity Leave – s.37(1)(a) EA 1955 (amended 2022: 98 days)
        $matl = LeaveType::where('code', 'MATL')->first();
        LeaveEntitlement::create(['leave_type_id' => $matl->id, 'min_tenure_months' => 0, 'max_tenure_months' => null, 'entitled_days' => 98, 'carry_forward_limit' => 0]);

        // Paternity Leave – s.60FA EA 1955 (amended 2022: 7 days)
        $patl = LeaveType::where('code', 'PATL')->first();
        LeaveEntitlement::create(['leave_type_id' => $patl->id, 'min_tenure_months' => 0, 'max_tenure_months' => null, 'entitled_days' => 7, 'carry_forward_limit' => 0]);

        // Replacement Leave – earned when working on rest day/public holiday
        $rl = LeaveType::where('code', 'RL')->first();
        if ($rl) {
            LeaveEntitlement::create(['leave_type_id' => $rl->id, 'min_tenure_months' => 0, 'max_tenure_months' => null, 'entitled_days' => 0, 'carry_forward_limit' => 0]);
        }

        // Unpaid Leave – no statutory entitlement, tracked at 0
        $ul = LeaveType::where('code', 'UL')->first();
        if ($ul) {
            LeaveEntitlement::create(['leave_type_id' => $ul->id, 'min_tenure_months' => 0, 'max_tenure_months' => null, 'entitled_days' => 0, 'carry_forward_limit' => 0]);
        }

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
