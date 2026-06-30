<?php

namespace App\Models;

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
    ];

    protected $casts = [
        'client_secret' => 'encrypted',
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'scopes' => 'array',
        'token_expires_at' => 'datetime',
    ];

    /** Never serialize secrets — keeps tokens out of logs/JSON/error dumps. */
    protected $hidden = ['client_secret', 'access_token', 'refresh_token'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** True once the user has supplied OAuth client credentials. */
    public function hasCredentials(): bool
    {
        return filled($this->client_id) && filled($this->client_secret);
    }

    /** True once a successful OAuth consent has produced live tokens. */
    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED && filled($this->access_token);
    }
}
