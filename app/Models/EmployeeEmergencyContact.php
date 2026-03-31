<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeEmergencyContact extends Model
{
    protected $fillable = [
        'employee_id',
        'contact_order',
        'name',
        'tel_no',
        'relationship',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
