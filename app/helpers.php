<?php

if (!function_exists('fmt_date')) {
    /**
     * The system-standard DISPLAY format for a full date: MM-DD-YYYY (e.g. 07-15-2026).
     * Use this for every human-facing date so the whole app reads consistently. Do NOT use it
     * for <input type="date"> values (those must stay YYYY-MM-DD) or machine-readable exports.
     *
     * Accepts a Carbon instance, a date string, or null. Returns $fallback for empty/invalid input.
     */
    function fmt_date($date, string $fallback = '—'): string
    {
        if (empty($date)) {
            return $fallback;
        }
        try {
            $c = $date instanceof \Carbon\Carbon ? $date : \Carbon\Carbon::parse($date);
        } catch (\Throwable $e) {
            return $fallback;
        }

        return $c->format('m-d-Y');
    }
}

if (!function_exists('fmt_datetime')) {
    /** System-standard date+time display: MM-DD-YYYY, h:mma (e.g. 07-15-2026, 4:26pm). */
    function fmt_datetime($date, string $fallback = '—'): string
    {
        if (empty($date)) {
            return $fallback;
        }
        try {
            $c = $date instanceof \Carbon\Carbon ? $date : \Carbon\Carbon::parse($date);
        } catch (\Throwable $e) {
            return $fallback;
        }

        return $c->format('m-d-Y, g:ia');
    }
}

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
            'rental_contracts',
            'claim_receipts',
        ];

        $directory = explode('/', $path)[0] ?? '';

        if (in_array($directory, $sensitiveDirectories, true)) {
            return route('secure.file', $path);
        }

        // Non-sensitive files — serve via public storage symlink
        return asset('storage/' . $path);
    }
}
