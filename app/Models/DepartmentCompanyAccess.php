<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Many-to-many: which companies each ticketing department serves.
 *
 *  - A department with NO rows in this table → serves ALL companies (default).
 *  - A department with one or more rows → serves ONLY those companies, which
 *    affects (a) the create-ticket dropdown, (b) the PIC pool, and (c) manager
 *    visibility on the Ticket Management page.
 */
class DepartmentCompanyAccess extends Model
{
    protected $table = 'department_company_access';

    protected $fillable = ['department', 'company_id'];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
