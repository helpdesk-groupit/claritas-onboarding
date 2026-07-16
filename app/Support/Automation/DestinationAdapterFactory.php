<?php

namespace App\Support\Automation;

use App\Models\EmailWorkflowConnection;
use App\Support\Automation\Adapters\GoogleDriveAdapter;
use App\Support\Automation\Adapters\GoogleSheetsAdapter;
use App\Support\Automation\Contracts\LogAdapter;
use App\Support\Automation\Contracts\StorageAdapter;
use RuntimeException;

/**
 * Resolves StorageAdapter / LogAdapter implementations for a connection —
 * the destination-side twin of EmailAdapterFactory.
 *
 * Adding a provider = one registry entry + one adapter class + one case here.
 * CaptureService depends only on the contracts.
 */
class DestinationAdapterFactory
{
    public function __construct(private readonly OAuthService $oauth) {}

    public function storage(EmailWorkflowConnection $conn): StorageAdapter
    {
        return match ($conn->provider_id) {
            'gdrive' => new GoogleDriveAdapter($this->oauth),
            default => throw new RuntimeException(
                "No storage adapter for provider [{$conn->provider_id}]."
            ),
        };
    }

    public function log(EmailWorkflowConnection $conn): LogAdapter
    {
        return match ($conn->provider_id) {
            'gsheets' => new GoogleSheetsAdapter($this->oauth),
            default => throw new RuntimeException(
                "No log adapter for provider [{$conn->provider_id}]."
            ),
        };
    }
}
