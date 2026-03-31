<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeEducationHistory extends Model
{
    protected $fillable = [
        'employee_id',
        'qualification',
        'institution',
        'year_graduated',
        'years_experience',
        'certificate_path',
        'certificate_paths',
    ];

    protected $casts = [
        'certificate_paths' => 'array',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
