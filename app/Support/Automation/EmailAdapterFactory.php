<?php

namespace App\Support\Automation;

use App\Models\EmailWorkflowConnection;
use App\Support\Automation\Adapters\GmailAdapter;
use App\Support\Automation\Adapters\ImapAdapter;
use App\Support\Automation\Adapters\OutlookAdapter;
use App\Support\Automation\Contracts\EmailSourceAdapter;
use RuntimeException;

/**
 * Resolves the right EmailSourceAdapter for a connection's provider.
 *
 * Adding a provider = one registry entry + one adapter class + one case here.
 * The rest of the app (test-rules, capture run) depends only on the contract.
 */
class EmailAdapterFactory
{
    public function __construct(private readonly OAuthService $oauth) {}

    public function for(EmailWorkflowConnection $conn): EmailSourceAdapter
    {
        return match ($conn->provider_id) {
            'gmail' => new GmailAdapter($this->oauth),
            'outlook' => new OutlookAdapter($this->oauth),
            'imap', 'yahoo' => new ImapAdapter($conn->provider_id),
            default => throw new RuntimeException("No email adapter for provider [{$conn->provider_id}]."),
        };
    }
}
