<?php

namespace App\Models;

use App\Support\Automation\ProviderRegistry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A per-provider connection for an Email Workflow automation.
 *
 * Security: client_secret + access/refresh tokens use the Laravel
 * `encrypted` cast so they are AES-encrypted at rest via APP_KEY and
 * never persisted in plaintext. They are also hidden from array/JSON
 * serialization so they can't leak into logs or API responses.
 */
class EmailWorkflowConnection extends Model
{
    use HasFactory;

    public const CATEGORIES = ['email', 'storage', 'log'];

    public const STATUS_UNCONFIGURED = 'unconfigured';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_NEEDS_RECONNECT = 'needs_reconnect';

    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'created_by', 'category', 'provider_id', 'account_label',
        'client_id', 'client_secret', 'access_token', 'refresh_token',
        'scopes', 'status', 'token_expires_at',
        'imap_host', 'imap_port', 'imap_encryption', 'imap_username', 'imap_password',
    ];

    protected $casts = [
        'client_secret' => 'encrypted',
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'imap_password' => 'encrypted',
        'scopes' => 'array',
        'token_expires_at' => 'datetime',
        'imap_port' => 'integer',
    ];

    /** Never serialize secrets — keeps tokens out of logs/JSON/error dumps. */
    protected $hidden = ['client_secret', 'access_token', 'refresh_token', 'imap_password'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** True once the user has supplied OAuth client credentials. */
    public function hasCredentials(): bool
    {
        return filled($this->client_id) && filled($this->client_secret);
    }

    /** True once IMAP host + username + password are present. */
    public function hasImapCredentials(): bool
    {
        return filled($this->imap_host) && filled($this->imap_username) && filled($this->imap_password);
    }

    /** True once the connection can actually talk to its provider. */
    public function isConnected(): bool
    {
        if ($this->status !== self::STATUS_CONNECTED) {
            return false;
        }

        return $this->isImap() ? $this->hasImapCredentials() : filled($this->access_token);
    }

    public function isOAuth(): bool
    {
        return ProviderRegistry::isOAuth($this->provider_id);
    }

    public function isImap(): bool
    {
        return ProviderRegistry::isImap($this->provider_id);
    }

    /**
     * Connection config the webklex IMAP client needs.
     *
     * @return array<string,mixed>
     */
    public function imapConfig(): array
    {
        return [
            'host' => $this->imap_host,
            'port' => $this->imap_port ?: 993,
            'encryption' => $this->imap_encryption ?: 'ssl',
            'validate_cert' => true,
            'username' => $this->imap_username,
            'password' => $this->imap_password, // decrypted by the cast
            'protocol' => 'imap',
        ];
    }
}
