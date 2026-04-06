<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves files from private (non-public) storage with authentication and authorization checks.
 * Prevents direct URL access to sensitive documents (NRIC, contracts, certificates, etc.).
 */
class SecureFileController extends Controller
{
    /**
     * Allowed directory prefixes and their required roles.
     * 'self' means the employee themselves can also access the file.
     */
    private const DIRECTORY_PERMISSIONS = [
        'nric_documents'        => ['hr_manager', 'hr_executive', 'superadmin', 'system_admin', 'self'],
        'employee_contracts'    => ['hr_manager', 'superadmin', 'system_admin', 'self'],
        'employee_documents'    => ['hr_manager', 'hr_executive', 'superadmin', 'system_admin', 'self'],
        'education_certificates'=> ['hr_manager', 'hr_executive', 'hr_intern', 'superadmin', 'system_admin', 'self'],
        'leave-attachments'     => ['hr_manager', 'hr_executive', 'superadmin', 'system_admin', 'self'],
        'aarfs'                 => ['hr_manager', 'hr_executive', 'it_manager', 'it_executive', 'superadmin', 'system_admin', 'self'],
        'invoices'              => ['hr_manager', 'it_manager', 'it_executive', 'superadmin', 'system_admin'],
        'claim_receipts'        => ['hr_manager', 'hr_executive', 'superadmin', 'system_admin', 'self'],
    ];

    /**
     * Download/stream a file from secure (private) storage.
     *
     * @param  string  $path  The relative path within private storage (e.g., "nric_documents/abc123.pdf")
     */
    public function serve(Request $request, string $path): StreamedResponse
    {
        $user = $request->user();

        if (!$user) {
            abort(403, 'Authentication required.');
        }

        // Prevent path traversal attacks
        $path = str_replace(['..', "\0"], '', $path);

        // Check private storage first, fall back to public for backward compatibility
        $disk = 'local';
        if (!Storage::disk('local')->exists($path)) {
            if (Storage::disk('public')->exists($path)) {
                $disk = 'public';
            } else {
                abort(404);
            }
        }

        // Determine the directory prefix
        $directory = explode('/', $path)[0] ?? '';

        // Check directory-level permission
        if (!$this->hasAccess($user, $directory, $path)) {
            abort(403);
        }

        $mimeType = Storage::disk($disk)->mimeType($path);
        $fileName = basename($path);

        return Storage::disk($disk)->download($path, $fileName, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="' . $fileName . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    /**
     * Check if the user has access to the given directory/file.
     */
    private function hasAccess($user, string $directory, string $path): bool
    {
        $permissions = self::DIRECTORY_PERMISSIONS[$directory] ?? null;

        // If directory not in permissions map, deny by default
        if ($permissions === null) {
            return false;
        }

        // Check role-based access
        if (in_array($user->role, $permissions)) {
            return true;
        }

        // Check 'self' access — employee can view their own files
        if (in_array('self', $permissions) && $user->employee) {
            return $this->isOwnFile($user, $path);
        }

        return false;
    }

    /**
     * Determine if a file belongs to the authenticated user's employee record.
     */
    private function isOwnFile($user, string $path): bool
    {
        $employee = $user->employee;
        if (!$employee) {
            return false;
        }

        // Check NRIC files
        $nricPaths = is_array($employee->nric_file_paths) ? $employee->nric_file_paths : json_decode($employee->nric_file_paths ?? '[]', true);
        if (in_array($path, $nricPaths ?: [])) {
            return true;
        }

        // Check contract files
        foreach ($employee->contracts ?? [] as $contract) {
            if ($contract->file_path === $path) {
                return true;
            }
        }

        // Check education certificate files
        foreach ($employee->educationHistories ?? [] as $edu) {
            $certPaths = is_array($edu->certificate_paths) ? $edu->certificate_paths : json_decode($edu->certificate_paths ?? '[]', true);
            if (in_array($path, $certPaths ?: [])) {
                return true;
            }
        }

        // Check leave attachment files
        foreach ($employee->leaveApplications ?? [] as $leave) {
            if ($leave->attachment_path === $path) {
                return true;
            }
        }

        return false;
    }
}
