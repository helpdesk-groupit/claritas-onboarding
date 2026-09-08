<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPermission extends Model
{
    protected $fillable = ['user_id', 'resource', 'access_level'];

    /**
     * fieldMap() is a pure literal with no config or DB reads, and the Manage
     * Access modal asks levelsFor() once per rendered row (~250 of them), so
     * rebuilding the array each time is pure waste. Callers get a copy, so the
     * cache cannot be mutated from outside.
     */
    private static ?array $fieldMapCache = null;

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
     *               'levels' => optional subset, narrowing the module's for this
     *                           section AND its fields (see 'announcements'),
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
        return self::$fieldMapCache ??= [
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
            /*
             * News & Announcements (the HR-side composer at /hr/announcements —
             * NOT the dashboard widget, which stays open to every employee; see
             * User::canViewAnnouncements()).
             *
             * 'Edit Only' is deliberately absent from the module: it would mean
             * "may change but may not read", and this is a listing you have to
             * open before you can act on anything in it. Same reasoning as
             * kol_management's narrower set, applied to a different gap.
             *
             * Two sections with deliberately different level sets:
             *   'compose' — the form's own fields, where Full / View Only / No
             *               Access each mean something (editable / read-only /
             *               hidden), so it inherits the module's set.
             *   'actions' — capabilities, which are held or not held. 'View
             *               Only' on "Delete" has nothing to mean, so it is not
             *               offered rather than rendered as a choice the server
             *               would refuse.
             */
            'announcements' => [
                'label' => 'Announcements',
                'icon' => 'bi-megaphone',
                'levels' => ['', 'full', 'view', 'none'],
                'sections' => [
                    'compose' => [
                        'label' => 'Announcement Form',
                        'fields' => [
                            'title' => 'Title',
                            'body' => 'Message',
                            'companies' => 'Target Companies',
                            'attachments' => 'Attachments',
                        ],
                    ],
                    'actions' => [
                        'label' => 'Actions',
                        'levels' => ['', 'full', 'none'],
                        'fields' => [
                            'publish' => 'Publish a New Announcement',
                            'edit' => 'Edit a Published Announcement',
                            'delete' => 'Delete an Announcement',
                            'others' => "See & Manage Colleagues' Announcements",
                        ],
                    ],
                ],
            ],

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
     * The levels selectable for one resource — the MOST SPECIFIC declaration
     * that covers it, section before module. A section that narrows the set
     * narrows it for its fields too, since a field cannot mean more than the
     * section it lives in.
     *
     * This is what stops a module (or section) that only supports grant/deny
     * being handed a 'view'/'edit' row by a crafted POST, and it is the same
     * method the Manage Access modal renders each row's columns from — so the
     * UI can never offer a level the server would refuse.
     */
    public static function levelsFor(string $resource): array
    {
        $parts = explode('.', $resource);
        $module = static::fieldMap()[$parts[0]] ?? null;

        if ($module === null) {
            return self::ACCESS_LEVELS;
        }

        $section = isset($parts[1]) ? ($module['sections'][$parts[1]] ?? null) : null;

        return $section['levels'] ?? $module['levels'] ?? self::ACCESS_LEVELS;
    }
}
