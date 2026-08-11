<?php

if (!function_exists('fmt_date')) {
    /**
     * The system-standard DISPLAY format for a full date: DD-MM-YYYY (e.g. 15-07-2026).
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

        return $c->format('d-m-Y');
    }
}

if (!function_exists('fmt_datetime')) {
    /** System-standard date+time display: DD-MM-YYYY, h:mma (e.g. 15-07-2026, 4:26pm). */
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

        return $c->format('d-m-Y, g:ia');
    }
}

if (! function_exists('asset_onboarding_option_label')) {
    /**
     * How a not-yet-started new hire reads in the asset "Assigned To" picker.
     *
     * Shared by the Add-Asset modal and the asset edit form so one person cannot appear
     * under two different descriptions on the two screens that assign them kit. The start
     * date is part of the label on purpose: it is what tells IT this person has no login
     * yet and will acknowledge the AARF from their email instead.
     */
    function asset_onboarding_option_label(\App\Models\Onboarding $onboarding): string
    {
        $name = $onboarding->personalDetail?->full_name ?: 'New hire #'.$onboarding->id;
        $email = $onboarding->workDetail?->company_email ?: $onboarding->personalDetail?->personal_email;
        $start = $onboarding->workDetail?->start_date;

        $label = $email ? "{$name} — {$email}" : $name;
        $label .= ' · New hire';

        return $start ? $label.', starts '.fmt_date($start) : $label;
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
