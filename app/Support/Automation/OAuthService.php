<?php

namespace App\Support\Automation;

use App\Models\EmailWorkflowConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * OAuth 2.0 authorization-code helper for the Email Workflow connections.
 *
 * Provider-agnostic: reads authorize/token endpoints + scopes from the
 * ProviderRegistry. The user supplies their own client id/secret per
 * connection (stored encrypted); we drive the consent round-trip and keep the
 * access/refresh tokens fresh. Tokens are never logged.
 */
class OAuthService
{
    /** Build the provider authorize URL to redirect the user to (Step: connect). */
    public function authorizeUrl(EmailWorkflowConnection $conn, string $redirectUri, string $state): string
    {
        $provider = ProviderRegistry::find($conn->provider_id);
        if (! $provider || ($provider['auth_type'] ?? '') !== 'oauth' || empty($provider['authorize_url'])) {
            throw new RuntimeException('This provider is not configured for OAuth sign-in.');
        }

        $params = array_merge([
            'client_id' => $conn->client_id,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $provider['scopes'] ?? []),
            'state' => $state,
        ], $provider['auth_params'] ?? []);

        return $this->endpoint($provider, $conn, 'authorize_url').'?'.http_build_query($params);
    }

    /**
     * Resolve a provider endpoint for this connection, substituting {tenant}.
     *
     * EVERY endpoint must go through here — authorize, code exchange AND
     * refresh. Microsoft binds the code to the directory that issued it, so an
     * authorize on /{tenant} redeemed against /common (or the reverse) fails at
     * the token step, which reads as "consent worked but the connection didn't"
     * — the exact class of bug this whole path already cost a day to.
     *
     * The tenant is rawurlencode()d because it lands in the URL PATH: it is
     * operator-supplied, and a value containing `/` would otherwise rewrite the
     * endpoint rather than name a directory. Validation at the form is the first
     * gate; this is the one that cannot be bypassed.
     *
     * @param  array<string,mixed>  $provider
     */
    private function endpoint(array $provider, EmailWorkflowConnection $conn, string $key): string
    {
        $url = (string) ($provider[$key] ?? '');
        if (! str_contains($url, '{tenant}')) {
            return $url; // Google et al — no tenant in the URL at all.
        }

        $tenant = trim((string) $conn->oauth_tenant) ?: (string) ($provider['tenant_default'] ?? 'common');

        return str_replace('{tenant}', rawurlencode($tenant), $url);
    }

    /**
     * Exchange an authorization code for tokens and persist them on the
     * connection (encrypted). Returns true on success.
     */
    public function exchangeCode(EmailWorkflowConnection $conn, string $code, string $redirectUri): bool
    {
        $provider = ProviderRegistry::find($conn->provider_id);
        if (! $provider || empty($provider['token_url'])) {
            Log::warning('Email Workflow OAuth code exchange skipped — provider has no token endpoint', [
                'provider' => $conn->provider_id,
            ]);
            $conn->update(['status' => EmailWorkflowConnection::STATUS_ERROR]);

            return false;
        }

        $res = Http::asForm()->post($this->endpoint($provider, $conn, 'token_url'), [
            'client_id' => $conn->client_id,
            'client_secret' => $conn->client_secret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ]);

        if ($res->failed()) {
            // Log the status only — never the body (may echo secrets).
            Log::warning('Email Workflow OAuth code exchange failed', [
                'provider' => $conn->provider_id, 'status' => $res->status(),
            ]);
            $conn->update(['status' => EmailWorkflowConnection::STATUS_ERROR]);

            return false;
        }

        $this->storeTokens($conn, $res->json());

        return true;
    }

    /**
     * Return a valid access token, refreshing it first if expired/near-expiry.
     */
    public function freshAccessToken(EmailWorkflowConnection $conn): string
    {
        $expired = $conn->token_expires_at && $conn->token_expires_at->isPast();
        if (! $expired && filled($conn->access_token)) {
            return $conn->access_token;
        }

        if (blank($conn->refresh_token)) {
            $conn->update(['status' => EmailWorkflowConnection::STATUS_NEEDS_RECONNECT]);
            throw new RuntimeException('Connection needs to be re-authorized.');
        }

        $provider = ProviderRegistry::find($conn->provider_id);
        $res = Http::asForm()->post($this->endpoint($provider, $conn, 'token_url'), [
            'client_id' => $conn->client_id,
            'client_secret' => $conn->client_secret,
            'refresh_token' => $conn->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if ($res->failed()) {
            Log::warning('Email Workflow OAuth refresh failed', [
                'provider' => $conn->provider_id, 'status' => $res->status(),
            ]);
            $conn->update(['status' => EmailWorkflowConnection::STATUS_NEEDS_RECONNECT]);
            throw new RuntimeException('Token refresh failed — reconnect required.');
        }

        $this->storeTokens($conn, $res->json());

        return $conn->access_token;
    }

    /** @param array<string,mixed> $token */
    private function storeTokens(EmailWorkflowConnection $conn, array $token): void
    {
        $attrs = [
            'access_token' => $token['access_token'] ?? $conn->access_token,
            'status' => EmailWorkflowConnection::STATUS_CONNECTED,
        ];
        // Refresh token is only returned on the first consent (or rotation).
        if (! empty($token['refresh_token'])) {
            $attrs['refresh_token'] = $token['refresh_token'];
        }
        if (! empty($token['expires_in'])) {
            $attrs['token_expires_at'] = now()->addSeconds((int) $token['expires_in'] - 60);
        }
        // Record what the provider ACTUALLY granted (space-delimited), not what the
        // registry asked for. The two diverge whenever a registry scope changes
        // after a connection was authorized — the old token keeps its old grant
        // until the user re-consents, and the UI must not claim otherwise.
        if (! empty($token['scope'])) {
            $attrs['scopes'] = array_values(array_filter(explode(' ', (string) $token['scope'])));
        }

        $conn->update($attrs);
    }
}
