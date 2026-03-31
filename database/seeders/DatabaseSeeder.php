<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\User;
use App\Models\Employee;
use App\Models\Onboarding;
use App\Models\PersonalDetail;
use App\Models\WorkDetail;
use App\Models\AssetProvisioning;
use App\Models\AssetInventory;
use App\Models\AssetAssignment;
use App\Models\Aarf;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ── Users ──────────────────────────────────────────────────────────
        $superadmin  = User::create(['name'=>'Super Admin',     'work_email'=>'superadmin@claritas.asia',      'password'=>Hash::make('password123'),'role'=>'superadmin']);
        $sysadmin    = User::create(['name'=>'System Admin',    'work_email'=>'sysadmin@claritas.asia',        'password'=>Hash::make('password123'),'role'=>'system_admin']);
        $hrManager   = User::create(['name'=>'Aisha Rahman',   'work_email'=>'aisha.rahman@claritas.asia',    'password'=>Hash::make('password123'),'role'=>'hr_manager']);
        $hrExec      = User::create(['name'=>'Nurul Huda',      'work_email'=>'nurul.huda@claritas.asia',      'password'=>Hash::make('password123'),'role'=>'hr_executive']);
        $hrIntern    = User::create(['name'=>'Farah Zain',      'work_email'=>'farah.zain@claritas.asia',      'password'=>Hash::make('password123'),'role'=>'hr_intern']);
        $itManager   = User::create(['name'=>'Raj Kumar',       'work_email'=>'raj.kumar@claritas.asia',       'password'=>Hash::make('password123'),'role'=>'it_manager']);
        $itExec      = User::create(['name'=>'Wei Liang',       'work_email'=>'wei.liang@claritas.asia',       'password'=>Hash::make('password123'),'role'=>'it_executive']);
        $itIntern    = User::create(['name'=>'Siti Noor',       'work_email'=>'siti.noor@claritas.asia',       'password'=>Hash::make('password123'),'role'=>'it_intern']);
        $employee1   = User::create(['name'=>'Ahmad Fadzil',    'work_email'=>'ahmad.fadzil@claritas.asia',    'password'=>Hash::make('password123'),'role'=>'employee']);
        $employee2   = User::create(['name'=>'Priya Menon',     'work_email'=>'priya.menon@claritas.asia',     'password'=>Hash::make('password123'),'role'=>'employee']);

        // ── Employee stubs for all staff (so Profile page works) ───────────
        // Note: PHP does not allow objects as array keys, so we use a plain list
        $staffProfiles = [
            [$superadmin, 'Super Admin',    'superadmin',    'System Administration'],
            [$sysadmin,   'System Admin',   'system_admin',  'System Administration'],
            [$hrManager,  'HR Manager',     'hr_manager',    'Human Resources'],
            [$hrExec,     'HR Executive',   'hr_executive',  'Human Resources'],
            [$hrIntern,   'HR Intern',      'hr_intern',     'Human Resources'],
            [$itManager,  'IT Manager',     'it_manager',    'Information Technology'],
            [$itExec,     'IT Executive',   'it_executive',  'Information Technology'],
            [$itIntern,   'IT Intern',      'it_intern',     'Information Technology'],
        ];
        foreach ($staffProfiles as [$user, $designation, $role, $dept]) {
            Employee::create([
                'user_id'         => $user->id,
                'active_from'     => '2023-01-01',
                'full_name'       => $user->name,
                'designation'     => $designation,
                'department'      => $dept,
                'company'         => 'Claritas Asia Sdn. Bhd.',
                'office_location' => 'Kuala Lumpur HQ',
                'company_email'   => $user->work_email,
                'work_role'       => $role,
                'employment_type' => 'permanent',
                'start_date'      => '2023-01-01',
            ]);
        }

        // ── Asset inventory ────────────────────────────────────────────────
        $laptop1 = AssetInventory::create([
            'asset_tag'=>'LPT-001','asset_name'=>'Dell Latitude 5540','asset_type'=>'laptop',
            'brand'=>'Dell','model'=>'Latitude 5540','serial_number'=>'SN-DELL-001',
            'status'=>'available','asset_condition'=>'new',
            'processor'=>'Intel Core i7-1365U','ram_size'=>'16GB DDR5',
            'storage'=>'512GB NVMe SSD','operating_system'=>'Windows 11 Pro',
            'screen_size'=>'15.6 inch','purchase_vendor'=>'Dell Malaysia',
            'purchase_cost'=>4500.00,'purchase_date'=>'2024-01-15',
            'warranty_expiry_date'=>'2027-01-15','maintenance_status'=>'none',
        ]);
        $laptop2 = AssetInventory::create([
            'asset_tag'=>'LPT-002','asset_name'=>'HP EliteBook 840 G10','asset_type'=>'laptop',
            'brand'=>'HP','model'=>'EliteBook 840 G10','serial_number'=>'SN-HP-002',
            'status'=>'available','asset_condition'=>'new',
            'processor'=>'Intel Core i5-1345U','ram_size'=>'8GB DDR5',
            'storage'=>'256GB NVMe SSD','operating_system'=>'Windows 11 Pro',
            'screen_size'=>'14 inch','purchase_vendor'=>'HP Malaysia',
            'purchase_cost'=>3800.00,'purchase_date'=>'2024-02-01',
            'warranty_expiry_date'=>'2027-02-01','maintenance_status'=>'none',
        ]);
        $monitor1 = AssetInventory::create([
            'asset_tag'=>'MON-001','asset_name'=>'Dell 24" Monitor','asset_type'=>'monitor',
            'brand'=>'Dell','model'=>'P2422H','serial_number'=>'SN-MON-001',
            'status'=>'available','asset_condition'=>'good',
            'purchase_vendor'=>'Dell Malaysia','purchase_cost'=>800.00,
            'purchase_date'=>'2023-06-01','warranty_expiry_date'=>'2026-06-01','maintenance_status'=>'none',
        ]);
        AssetInventory::create([
            'asset_tag'=>'MON-002','asset_name'=>'Dell 24" Monitor','asset_type'=>'monitor',
            'brand'=>'Dell','model'=>'P2422H','serial_number'=>'SN-MON-002',
            'status'=>'available','asset_condition'=>'good',
            'purchase_vendor'=>'Dell Malaysia','purchase_cost'=>800.00,
            'purchase_date'=>'2023-06-01','warranty_expiry_date'=>'2026-06-01','maintenance_status'=>'none',
        ]);
        AssetInventory::create([
            'asset_tag'=>'CNV-001','asset_name'=>'USB-C Converter Hub','asset_type'=>'converter',
            'brand'=>'Anker','model'=>'7-in-1 Hub','serial_number'=>'SN-CNV-001',
            'status'=>'available','asset_condition'=>'new',
            'purchase_vendor'=>'Shopee','purchase_cost'=>150.00,
            'purchase_date'=>'2024-01-01','maintenance_status'=>'none',
        ]);
        AssetInventory::create([
            'asset_tag'=>'ACS-001','asset_name'=>'RFID Access Card','asset_type'=>'access_card',
            'brand'=>'Internal','model'=>'RFID Card','serial_number'=>'SN-ACS-001',
            'status'=>'available','asset_condition'=>'new',
            'purchase_vendor'=>'Internal','purchase_cost'=>10.00,
            'purchase_date'=>'2024-01-01','maintenance_status'=>'none',
        ]);

        // ── Onboarding Record 1: Ahmad Fadzil — start date passed ─────────
        // Status = active, employee record already created with data copied in
        $ob1 = Onboarding::create([
            'status'   => 'active',
            'hr_email' => $hrManager->work_email,
            'it_email' => $itManager->work_email,
        ]);
        PersonalDetail::create([
            'onboarding_id'=>$ob1->id,'full_name'=>'Ahmad Fadzil bin Aziz',
            'official_document_id'=>'900101-14-5678','date_of_birth'=>'1990-01-01',
            'sex'=>'male','marital_status'=>'married','religion'=>'Islam','race'=>'Malay',
            'residential_address'=>'No 12, Jalan Setia, Petaling Jaya, Selangor',
            'personal_contact_number'=>'0123456789','personal_email'=>'ahmad.fadzil@gmail.com',
            'bank_account_number'=>'1234567890',
        ]);
        WorkDetail::create([
            'onboarding_id'=>$ob1->id,'employee_status'=>'active','staff_status'=>'new',
            'employment_type'=>'permanent','designation'=>'Software Engineer',
            'company'=>'Claritas Asia Sdn. Bhd.','office_location'=>'Kuala Lumpur HQ',
            'reporting_manager'=>'Raj Kumar','start_date'=>'2024-03-01',
            'company_email'=>'ahmad.fadzil@claritas.asia','department'=>'Technology',
            'role'=>'executive_associate',
        ]);
        AssetProvisioning::create([
            'onboarding_id'=>$ob1->id,'laptop_provision'=>true,'monitor_set'=>true,
            'converter'=>true,'company_phone'=>false,'sim_card'=>false,'access_card_request'=>true,
        ]);
        AssetAssignment::create(['onboarding_id'=>$ob1->id,'asset_inventory_id'=>$laptop1->id,'assigned_date'=>'2024-03-01','status'=>'assigned']);
        AssetAssignment::create(['onboarding_id'=>$ob1->id,'asset_inventory_id'=>$monitor1->id,'assigned_date'=>'2024-03-01','status'=>'assigned']);
        $laptop1->update(['status'=>'unavailable']);
        $monitor1->update(['status'=>'unavailable']);
        $aarf1 = Aarf::create([
            'onboarding_id'        => $ob1->id,
            'aarf_reference'       => 'AARF-FDZ001-2024',
            'acknowledgement_token'=> Str::random(64),
        ]);
        // Employee record: data copied from onboarding (start date has passed)
        Employee::create([
            'onboarding_id'           => $ob1->id,
            'user_id'                 => $employee1->id,
            'active_from'             => '2024-03-01',
            'full_name'               => 'Ahmad Fadzil bin Aziz',
            'official_document_id'    => '900101-14-5678',
            'date_of_birth'           => '1990-01-01',
            'sex'                     => 'male',
            'marital_status'          => 'married',
            'religion'                => 'Islam',
            'race'                    => 'Malay',
            'residential_address'     => 'No 12, Jalan Setia, Petaling Jaya, Selangor',
            'personal_contact_number' => '0123456789',
            'personal_email'          => 'ahmad.fadzil@gmail.com',
            'bank_account_number'     => '1234567890',
            'designation'             => 'Software Engineer',
            'department'              => 'Technology',
            'company'                 => 'Claritas Asia Sdn. Bhd.',
            'office_location'         => 'Kuala Lumpur HQ',
            'reporting_manager'       => 'Raj Kumar',
            'company_email'           => 'ahmad.fadzil@claritas.asia',
            'start_date'              => '2024-03-01',
            'employment_type'         => 'permanent',
            'work_role'               => 'executive_associate',
        ]);

        // ── Onboarding Record 2: Priya Menon — start date in future ───────
        // Status = pending, no employee record yet (not started)
        $ob2 = Onboarding::create([
            'status'   => 'pending',
            'hr_email' => $hrExec->work_email,
            'it_email' => $itExec->work_email,
        ]);
        PersonalDetail::create([
            'onboarding_id'=>$ob2->id,'full_name'=>'Priya a/p Menon',
            'official_document_id'=>'950505-10-1234','date_of_birth'=>'1995-05-05',
            'sex'=>'female','marital_status'=>'single','religion'=>'Hindu','race'=>'Indian',
            'residential_address'=>'Unit 3B, Sri Damansara, Kuala Lumpur',
            'personal_contact_number'=>'0198765432','personal_email'=>'priya.menon@gmail.com',
            'bank_account_number'=>'9876543210',
        ]);
        WorkDetail::create([
            'onboarding_id'=>$ob2->id,'employee_status'=>'active','staff_status'=>'new',
            'employment_type'=>'permanent','designation'=>'Marketing Executive',
            'company'=>'Claritas Asia Sdn. Bhd.','office_location'=>'Kuala Lumpur HQ',
            'reporting_manager'=>'Aisha Rahman','start_date'=>'2026-06-01',
            'company_email'=>'priya.menon@claritas.asia','department'=>'Marketing',
            'role'=>'executive_associate',
        ]);
        AssetProvisioning::create([
            'onboarding_id'=>$ob2->id,'laptop_provision'=>true,'monitor_set'=>false,
            'converter'=>false,'company_phone'=>false,'sim_card'=>false,'access_card_request'=>true,
        ]);
        AssetAssignment::create(['onboarding_id'=>$ob2->id,'asset_inventory_id'=>$laptop2->id,'assigned_date'=>'2026-05-28','status'=>'assigned']);
        $laptop2->update(['status'=>'unavailable']);
        Aarf::create([
            'onboarding_id'        => $ob2->id,
            'aarf_reference'       => 'AARF-PRY002-2026',
            'acknowledgement_token'=> Str::random(64),
        ]);
        // No Employee record for Priya — start date not yet reached
    }
}