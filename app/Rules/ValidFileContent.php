<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Validates that an uploaded file's actual content (magic bytes) matches
 * the declared MIME types — prevents extension-spoofing attacks.
 */
class ValidFileContent implements ValidationRule
{
    /**
     * Mapping of allowed extensions to their legitimate MIME types (from finfo).
     */
    protected const MIME_MAP = [
        'pdf'  => ['application/pdf'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'svg'  => ['image/svg+xml', 'text/xml', 'text/html', 'text/plain', 'application/xml'],
        'doc'  => ['application/msword', 'application/vnd.ms-office', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
    ];

    protected array $allowedExtensions;

    public function __construct(array $allowedExtensions = [])
    {
        $this->allowedExtensions = array_map('strtolower', $allowedExtensions);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!$value instanceof UploadedFile || !$value->isValid()) {
            return; // Let other rules handle missing/invalid files
        }

        $extension = strtolower($value->getClientOriginalExtension());

        // If no extensions specified, allow all that we know about
        $extensions = !empty($this->allowedExtensions) ? $this->allowedExtensions : array_keys(self::MIME_MAP);

        if (!in_array($extension, $extensions, true)) {
            return; // Extension validation is handled by 'mimes:' rule
        }

        $allowedMimes = self::MIME_MAP[$extension] ?? null;
        if ($allowedMimes === null) {
            return; // Unknown extension — skip magic-byte check
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($value->getRealPath());

        if (!in_array($detectedMime, $allowedMimes, true)) {
            $fail("The :attribute file content does not match its extension ({$extension}).");
        }
    }
}
