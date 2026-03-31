<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeSpouseDetail extends Model
{
    protected $fillable = [
        'employee_id',
        'name',
        'address',
        'nric_no',
        'tel_no',
        'occupation',
        'income_tax_no',
        'is_working',
        'is_disabled',
    ];

    protected $casts = [
        'is_working'  => 'boolean',
        'is_disabled' => 'boolean',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
