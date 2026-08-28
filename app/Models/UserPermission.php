<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPermission extends Model
{
    protected $fillable = ['user_id', 'resource', 'access_level'];

    /**
     * Every level the Manage Access UI can offer, in render order.
     * '' is not stored — it means "no override row", i.e. fall back to the
     * employee's role-based permissions. The other four are the
     * user_permissions.access_level enum.
     */
    public const ACCESS_LEVELS = ['', 'full', 'view', 'edit', 'none'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Full hierarchy: module → section → individual fields.
     * Used by the view to render tabs and by validResources() for whitelisting.
     *
     * Structure:
     *   [module_key => [
     *       'label'    => string,
     *       'icon'     => bootstrap-icons class,
     *       'levels'   => optional subset of ACCESS_LEVELS (defaults to all),
     *       'sections' => [
     *           section_key => [
     *               'label'  => string,
     *               'fields' => [ field_key => label, ... ],
     *           ],
     *       ],
     *   ]]
     *
     * A module may legitimately have NO sections — see 'kol_management' — in
     * which case it appears on the By Page tab only. The By Section / By Field
     * tabs skip it rather than rendering an empty accordion.
     */
    public static function fieldMap(): array
    {
        return [
            'onboarding' => [
                'label' => 'Onboarding',
                'icon' => 'bi-person-plus',
                'sections' => [
                    'personal_details' => [
                        'label' => 'Personal Details',
                        'fields' => [
                            'full_name' => 'Full Name',
                            'official_document_id' => 'NRIC / Passport No.',
                            'nric_files' => 'NRIC / Passport Files',
                            'date_of_birth' => 'Date of Birth',
                            'sex' => 'Sex',
                            'marital_status' => 'Marital Status',
                            'religion' => 'Religion',
                            'race' => 'Race / Ethnicity',
                            'is_disabled' => 'Disability Status',
                            'residential_address' => 'Residential Address',
                            'personal_contact_number' => 'Personal Contact No.',
                            'house_tel_no' => 'House Tel No.',
                            'personal_email' => 'Personal Email',
                            'bank_account_number' => 'Bank Account No.',
                            'bank_name' => 'Bank Name',
                            'epf_no' => 'EPF No.',
                            'income_tax_no' => 'Income Tax No.',
                            'socso_no' => 'SOCSO No.',
                        ],
                    ],
                    'work_details' => [
                        'label' => 'Work Details',
                        'fields' => [
                            'designation' => 'Designation',
                            'department' => 'Department',
                            'company' => 'Company',
                            'office_location' => 'Office Location',
                            'reporting_manager' => 'Reporting Manager',
                            'employment_type' => 'Employment Type',
                            'start_date' => 'Start Date',
                            'exit_date' => 'Exit Date',
                            'company_email' => 'Company Email',
                        ],
                    ],
                    'assets' => [
                        'label' => 'Asset Assignment',
                        'fields' => [
                            'laptop' => 'Laptop',
                            'monitor' => 'Monitor / Monitor Set',
                            'company_phone' => 'Company Phone',
                            'sim_card' => 'SIM Card',
                            'access_card' => 'Access Card',
                            'converter' => 'Converter / Accessories',
                        ],
                    ],
                    'education' => [
                        'label' => 'Education History',
                        'fields' => [
                            'institution_name' => 'Institution Name',
                            'highest_qualification' => 'Highest Qualification',
                            'field_of_study' => 'Field of Study',
                            'year_graduated' => 'Year Graduated',
                            'certificate' => 'Certificate Upload',
                        ],
                    ],
                    'spouse' => [
                        'label' => 'Spouse Details',
                        'fields' => [
                            'spouse_name' => 'Spouse Name',
                            'spouse_nric' => 'Spouse NRIC / Passport',
                            'spouse_contact' => 'Spouse Contact No.',
                            'spouse_occupation' => 'Spouse Occupation',
                        ],
                    ],
                    'emergency' => [
                        'label' => 'Emergency Contacts',
                        'fields' => [
                            'contact_name' => 'Contact Name',
                            'contact_relationship' => 'Relationship',
                            'contact_number' => 'Contact Number',
                            'contact_email' => 'Contact Email',
                        ],
                    ],
                    'children' => [
                        'label' => 'Children Registration',
                        'fields' => [
                            'child_name' => 'Child Name',
                            'child_dob' => 'Date of Birth',
                            'child_gender' => 'Gender',
                        ],
                    ],
                ],
            ],

            'employees' => [
                'label' => 'Employee Listing',
                'icon' => 'bi-people',
                'sections' => [
                    'personal_info' => [
                        'label' => 'Personal Information',
                        'fields' => [
                            'full_name' => 'Full Name',
                            'preferred_name' => 'Preferred Name',
                            'official_document_id' => 'NRIC / Passport No.',
                            'date_of_birth' => 'Date of Birth',
                            'sex' => 'Sex',
                            'marital_status' => 'Marital Status',
                            'religion' => 'Religion',
                            'race' => 'Race / Ethnicity',
                            'is_disabled' => 'Disability Status',
                            'residential_address' => 'Residential Address',
                            'personal_contact_number' => 'Personal Contact No.',
                            'house_tel_no' => 'House Tel No.',
                            'personal_email' => 'Personal Email',
                        ],
                    ],
                    'work_info' => [
                        'label' => 'Work Information',
                        'fields' => [
                            'designation' => 'Designation',
                            'department' => 'Department',
                            'company' => 'Company',
                            'office_location' => 'Office Location',
                            'reporting_manager' => 'Reporting Manager',
                            'employment_type' => 'Employment Type',
                            'start_date' => 'Start Date',
                            'exit_date' => 'Exit Date',
                            'company_email' => 'Company Email',
                        ],
                    ],
                    'financial_info' => [
                        'label' => 'Financial Information',
                        'fields' => [
                            'bank_account_number' => 'Bank Account No.',
                            'bank_name' => 'Bank Name',
                            'epf_no' => 'EPF No.',
                            'income_tax_no' => 'Income Tax No.',
                            'socso_no' => 'SOCSO No.',
                        ],
                    ],
                    'documents' => [
                        'label' => 'Documents',
                        'fields' => [
                            'nric_files' => 'NRIC / Passport Files',
                            'handbook' => 'Employee Handbook',
                            'orientation' => 'Orientation Materials',
                        ],
                    ],
                ],
            ],

            'assets' => [
                'label' => 'Asset Management',
                'icon' => 'bi-laptop',
                'sections' => [
                    'asset_details' => [
                        'label' => 'Asset Details',
                        'fields' => [
                            'asset_type' => 'Asset Type',
                            'asset_tag' => 'Asset Tag',
                            'brand' => 'Brand',
                            'model' => 'Model',
                            'asset_name' => 'Asset Name',
                            'serial_number' => 'Serial Number',
                            'asset_condition' => 'Condition',
                            'ownership_type' => 'Ownership Type',
                            'purchase_date' => 'Purchase Date',
                            'warranty_expiry' => 'Warranty Expiry',
                            'notes' => 'Notes',
                        ],
                    ],
                    'assignment' => [
                        'label' => 'Assignment',
                        'fields' => [
                            'assigned_employee' => 'Assigned Employee',
                            'assigned_date' => 'Assigned Date',
                            'expected_return_date' => 'Expected Return Date',
                        ],
                    ],
                    'photos' => [
                        'label' => 'Photos',
                        'fields' => [
                            'asset_photos' => 'Asset Photos',
                        ],
                    ],
                ],
            ],

            'offboarding' => [
                'label' => 'Offboarding',
                'icon' => 'bi-box-arrow-right',
                'sections' => [
                    'employee_info' => [
                        'label' => 'Employee Information',
                        'fields' => [
                            'full_name' => 'Full Name',
                            'designation' => 'Designation',
                            'department' => 'Department',
                            'company' => 'Company',
                            'start_date' => 'Start Date',
                            'exit_date' => 'Exit Date',
                        ],
                    ],
                    'exit_details' => [
                        'label' => 'Exit Details',
                        'fields' => [
                            'resignation_reason' => 'Resignation Reason',
                            'last_working_day' => 'Last Working Day',
                            'exit_interview_notes' => 'Exit Interview Notes',
                            'handover_notes' => 'Handover Notes',
                        ],
                    ],
                    'it_tasks' => [
                        'label' => 'IT Tasks',
                        'fields' => [
                            'task_list' => 'Task Checklist',
                            'asset_return' => 'Asset Return Status',
                            'it_notes' => 'IT Notes',
                        ],
                    ],
                ],
            ],

            /*
             * ADM-06 — the "KOL Management" sidebar link, which hands the user
             * over to the KOL Management Portal (a SEPARATE application) by SSO.
             *
             * Page-level only, and grant/deny only, for the same reason: there
             * is no form in THIS app to gate, and what happens once the user
             * lands over there is decided by the KOL Portal's own staff_users
             * table — so "View Only" and "Edit Only" would have nothing to mean.
             * The override is read by User::canAccessKolPortal(), which both the
             * sidebar and KolPortalRedirectController go through.
             */
            'kol_management' => [
                'label' => 'KOL Management',
                'icon' => 'bi-megaphone',
                'levels' => ['', 'full', 'none'],
                'sections' => [],
            ],
        ];
    }

    /**
     * All valid resource keys — auto-generated from fieldMap().
     * Covers page-level, section-level, and field-level.
     */
    public static function validResources(): array
    {
        $resources = [];
        foreach (static::fieldMap() as $moduleKey => $module) {
            $resources[] = $moduleKey; // page-level
            foreach ($module['sections'] as $sectionKey => $section) {
                $resources[] = "{$moduleKey}.{$sectionKey}"; // section-level
                foreach ($section['fields'] as $fieldKey => $_) {
                    $resources[] = "{$moduleKey}.{$sectionKey}.{$fieldKey}"; // field-level
                }
            }
        }

        return $resources;
    }

    /**
     * The levels selectable for one resource. Declared per module and inherited
     * by its sections and fields, so a module that only supports grant/deny
     * cannot be handed a 'view'/'edit' row by a crafted POST.
     */
    public static function levelsFor(string $resource): array
    {
        $moduleKey = explode('.', $resource)[0];

        return static::fieldMap()[$moduleKey]['levels'] ?? self::ACCESS_LEVELS;
    }
}
