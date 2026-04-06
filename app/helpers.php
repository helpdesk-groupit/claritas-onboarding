<?php

if (!function_exists('secure_file_url')) {
    /**
     * Generate the URL for a stored file.
     *
     * Sensitive directories are served through the authenticated SecureFileController.
     * Non-sensitive files (profile pictures, logos, etc.) are served via the public storage symlink.
     *
     * @param  string|null  $path  The relative storage path (e.g., "nric_documents/abc.pdf")
     * @return string
     */
    function secure_file_url(?string $path): string
    {
        if (!$path) {
            return '#';
        }

        $sensitiveDirectories = [
            'nric_documents',
            'employee_contracts',
            'employee_documents',
            'education_certificates',
            'leave-attachments',
            'aarfs',
            'invoices',
        ];

        $directory = explode('/', $path)[0] ?? '';

        if (in_array($directory, $sensitiveDirectories, true)) {
            return route('secure.file', $path);
        }

        // Non-sensitive files — serve via public storage symlink
        return asset('storage/' . $path);
    }
}
