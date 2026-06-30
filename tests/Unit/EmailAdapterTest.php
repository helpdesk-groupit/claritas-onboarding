<?php

namespace Tests\Unit;

use App\Models\EmailWorkflowConnection;
use App\Models\User;
use App\Support\Automation\Adapters\GmailAdapter;
use App\Support\Automation\Adapters\OutlookAdapter;
use App\Support\Automation\EmailAdapterFactory;
use App\Support\Automation\OAuthService;
use App\Support\Automation\ProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Adapter integration tests against faked HTTP — no live credentials.
 * Proves Gmail/Outlook normalize the provider payloads into the engine's
 * message shape, the factory resolves the right adapter, and the registry
 * advertises the correct auth types for the newly-enabled providers.
 */
class EmailAdapterTest extends TestCase
{
    use RefreshDatabase;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = User::factory()->itManager()->create()->id;
    }

    private function oauthConn(string $provider): EmailWorkflowConnection
    {
        return EmailWorkflowConnection::create([
            'created_by' => $this->userId,
            'category' => 'email',
            'provider_id' => $provider,
            'client_id' => 'cid',
            'client_secret' => 'csecret',
            'access_token' => 'valid-token',
            'token_expires_at' => now()->addHour(),
            'status' => EmailWorkflowConnection::STATUS_CONNECTED,
        ]);
    }

    public function test_registry_enables_all_email_providers_with_correct_auth_types(): void
    {
        $this->assertTrue(ProviderRegistry::isEnabled('gmail'));
        $this->assertTrue(ProviderRegistry::isEnabled('outlook'));
        $this->assertTrue(ProviderRegistry::isEnabled('imap'));
        $this->assertTrue(ProviderRegistry::isEnabled('yahoo'));

        $this->assertTrue(ProviderRegistry::isOAuth('gmail'));
        $this->assertTrue(ProviderRegistry::isOAuth('outlook'));
        $this->assertTrue(ProviderRegistry::isImap('imap'));
        $this->assertTrue(ProviderRegistry::isImap('yahoo'));
    }

    public function test_yahoo_presets_imap_host(): void
    {
        $this->assertSame('imap.mail.yahoo.com', ProviderRegistry::find('yahoo')['imap']['host']);
    }

    public function test_factory_resolves_each_adapter(): void
    {
        $factory = new EmailAdapterFactory(new OAuthService);

        $this->assertSame('gmail', $factory->for($this->oauthConn('gmail'))->providerId());
        $this->assertSame('outlook', $factory->for($this->oauthConn('outlook'))->providerId());

        $imap = EmailWorkflowConnection::create([
            'created_by' => $this->userId, 'category' => 'email', 'provider_id' => 'yahoo',
            'imap_host' => 'imap.mail.yahoo.com', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'imap_username' => 'me@yahoo.com', 'imap_password' => 'app-pass',
            'status' => EmailWorkflowConnection::STATUS_CONNECTED,
        ]);
        $this->assertSame('yahoo', $factory->for($imap)->providerId());
    }

    public function test_gmail_adapter_normalizes_a_message(): void
    {
        Http::fake([
            'gmail.googleapis.com/*/messages?*' => Http::response(['messages' => [['id' => 'm1']]]),
            'gmail.googleapis.com/*/messages/m1*' => Http::response([
                'id' => 'm1',
                'internalDate' => (string) (now()->getTimestampMs()),
                'payload' => [
                    'headers' => [
                        ['name' => 'From', 'value' => 'billing@acme.com'],
                        ['name' => 'Subject', 'value' => 'Invoice for June'],
                    ],
                    'parts' => [
                        ['mimeType' => 'text/plain', 'body' => ['data' => rtrim(strtr(base64_encode('Total RM 1,250.00'), '+/', '-_'), '=')]],
                        ['mimeType' => 'application/pdf', 'filename' => 'invoice.pdf', 'body' => ['attachmentId' => 'att1', 'size' => 2048]],
                    ],
                ],
            ]),
        ]);

        $adapter = new GmailAdapter(new OAuthService);
        $messages = $adapter->search($this->oauthConn('gmail'), [], ['limit' => 5]);

        $this->assertCount(1, $messages);
        $this->assertSame('Invoice for June', $messages[0]['subject']);
        $this->assertSame('billing@acme.com', $messages[0]['from']);
        $this->assertStringContainsString('RM 1,250.00', $messages[0]['body']);
        $this->assertSame('invoice.pdf', $messages[0]['attachments'][0]['name']);
    }

    public function test_outlook_adapter_normalizes_a_message(): void
    {
        Http::fake([
            'graph.microsoft.com/*/messages?*' => Http::response([
                'value' => [[
                    'id' => 'o1',
                    'subject' => 'New invoice from Vendor',
                    'from' => ['emailAddress' => ['address' => 'ap@vendor.io']],
                    'receivedDateTime' => '2026-06-15T08:30:00Z',
                    'bodyPreview' => 'Amount: $89.90',
                    'body' => ['contentType' => 'text', 'content' => 'Amount: $89.90'],
                    'hasAttachments' => true,
                ]],
            ]),
            'graph.microsoft.com/*/messages/o1/attachments*' => Http::response([
                'value' => [['id' => 'att1', 'name' => 'receipt.pdf', 'contentType' => 'application/pdf', 'size' => 1500]],
            ]),
        ]);

        $adapter = new OutlookAdapter(new OAuthService);
        $messages = $adapter->search($this->oauthConn('outlook'), [], ['limit' => 5]);

        $this->assertCount(1, $messages);
        $this->assertSame('New invoice from Vendor', $messages[0]['subject']);
        $this->assertSame('ap@vendor.io', $messages[0]['from']);
        $this->assertSame('receipt.pdf', $messages[0]['attachments'][0]['name']);
    }

    public function test_gmail_attachment_download_decodes_base64url(): void
    {
        Http::fake([
            'gmail.googleapis.com/*/attachments/att1' => Http::response([
                'data' => rtrim(strtr(base64_encode('PDFBYTES'), '+/', '-_'), '='),
            ]),
        ]);

        $bytes = (new GmailAdapter(new OAuthService))
            ->downloadAttachment($this->oauthConn('gmail'), 'm1', 'att1');

        $this->assertSame('PDFBYTES', $bytes);
    }

    public function test_oauth_service_refreshes_an_expired_token(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fresh-token', 'expires_in' => 3600,
            ]),
        ]);

        $conn = EmailWorkflowConnection::create([
            'created_by' => $this->userId, 'category' => 'email', 'provider_id' => 'gmail',
            'client_id' => 'cid', 'client_secret' => 'csecret',
            'access_token' => 'stale', 'refresh_token' => 'rt',
            'token_expires_at' => now()->subMinute(), // expired
            'status' => EmailWorkflowConnection::STATUS_CONNECTED,
        ]);

        $token = (new OAuthService)->freshAccessToken($conn);

        $this->assertSame('fresh-token', $token);
        $this->assertSame('fresh-token', $conn->fresh()->access_token);
    }
}
