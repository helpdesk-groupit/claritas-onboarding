<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Legitimate MIME types (from finfo) for each file extension.
     */
    private const MIME_MAP = [
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

    public function boot(): void
    {
        Paginator::useBootstrapFive();

        // Rate-limit file uploads: 10 requests per minute per user/IP
        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        // Magic-bytes validation: ensures uploaded file content matches its extension.
        // Usage: 'field' => 'file|mimes:pdf,jpg|valid_file_content'
        Validator::extend('valid_file_content', function ($attribute, $value, $parameters, $validator) {
            if (!$value instanceof UploadedFile || !$value->isValid()) {
                return true; // Let 'file' / 'required' rules handle this
            }

            $extension  = strtolower($value->getClientOriginalExtension());
            $allowedMimes = self::MIME_MAP[$extension] ?? null;

            if ($allowedMimes === null) {
                return true; // Unknown extension — skip magic-byte check
            }

            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detectedMime = $finfo->file($value->getRealPath());

            return in_array($detectedMime, $allowedMimes, true);
        });

        Validator::replacer('valid_file_content', function ($message, $attribute) {
            return "The {$attribute} file content does not match its extension.";
        });
    }
}