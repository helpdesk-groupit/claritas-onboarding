<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeContract extends Model
{
    protected $fillable = [
        'employee_id', 'uploaded_by', 'original_filename', 'file_path', 'file_size', 'notes',
    ];

    public function employee()   { return $this->belongsTo(Employee::class); }
    public function uploader()   { return $this->belongsTo(User::class, 'uploaded_by'); }

    /** Human-readable file size e.g. "1.2 MB" */
    public function getFileSizeLabelAttribute(): string
    {
        if (!$this->file_size) return '—';
        $kb = $this->file_size / 1024;
        if ($kb < 1024) return round($kb, 1) . ' KB';
        return round($kb / 1024, 2) . ' MB';
    }
}