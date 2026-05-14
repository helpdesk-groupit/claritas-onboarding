<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Service-provider routing: which company's team handles tickets from which
 * client company, per department.
 *
 *  - source_company_id = the company whose team provides the service
 *  - department        = the dept name (work-role or app-role gated)
 *  - company_id        = the served / client / raiser company
 *
 * A row reads: "<source>'s <department> also handles tickets from <served>."
 *
 * No rows for a given (source, dept) pair means the source's team serves only
 * its own company's tickets. New tickets created via the form resolve to
 * exactly one source company per (raiser_company, department) pair; ambiguity
 * is surfaced to the raiser through the Routing > Change picker.
 */
class DepartmentCompanyAccess extends Model
{
    protected $table = 'department_company_access';

    protected $fillable = ['source_company_id', 'department', 'company_id'];

    public function sourceCompany()
    {
        return $this->belongsTo(Company::class, 'source_company_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
