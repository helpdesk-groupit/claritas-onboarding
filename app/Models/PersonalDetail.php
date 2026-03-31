<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PersonalDetail extends Model
{
    protected $fillable = [
        'onboarding_id',
        'full_name', 'preferred_name', 'official_document_id', 'date_of_birth',
        'sex', 'marital_status', 'religion', 'race',
        'is_disabled',
        'residential_address',
        'personal_contact_number', 'house_tel_no', 'personal_email',
        'bank_account_number', 'bank_name',
        'epf_no', 'income_tax_no', 'socso_no',
        'nric_file_path',
        'nric_file_paths',
        'consent_given_at', 'consent_ip',
        'invite_staging_json',
    ];

    protected $casts = [
        'date_of_birth'    => 'date',
        'is_disabled'      => 'boolean',
        'consent_given_at' => 'datetime',
        'nric_file_paths'  => 'array',
    ];

    public function onboarding() { return $this->belongsTo(Onboarding::class); }
}
