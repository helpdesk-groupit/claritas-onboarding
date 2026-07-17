<?php

namespace App\Support\Automation;

/**
 * Provider registry for the Email Workflow automation module.
 *
 * The wizard renders provider cards from this list. Adding a new provider =
 * one registry entry + one adapter class implementing the matching interface
 * (EmailSourceAdapter / StorageAdapter / LogAdapter) — zero UI/engine changes.
 *
 * `enabled = false` providers render as "coming soon" cards, gated behind the
 * same interface so they slot in without refactors.
 */
class ProviderRegistry
{
    public const CATEGORY_EMAIL = 'email';

    public const CATEGORY_STORAGE = 'storage';

    public const CATEGORY_LOG = 'log';

    /**
     * @return array<int, array{
     *   id:string, name:string, category:string, icon:string,
     *   auth_type:string, scopes:array<int,string>, enabled:bool, blurb:string
     * }>
     */
    public static function all(): array
    {
        return [
            // ── EMAIL SOURCES ──────────────────────────────────────────
            [
                'id' => 'gmail',
                'name' => 'Gmail',
                'category' => self::CATEGORY_EMAIL,
                'icon' => 'bi-envelope-at',
                'auth_type' => 'oauth',
                // Read-only + modify-for-label (least privilege — §6).
                'scopes' => [
                    'https://www.googleapis.com/auth/gmail.readonly',
                    'https://www.googleapis.com/auth/gmail.modify',
                ],
                'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token_url' => 'https://oauth2.googleapis.com/token',
                // Google requires offline access + consent prompt to return a refresh token.
                'auth_params' => ['access_type' => 'offline', 'prompt' => 'consent'],
                'enabled' => true,
                'blurb' => 'Read invoices & receipts from a Gmail inbox.',
            ],
            [
                'id' => 'outlook',
                'name' => 'Microsoft Outlook / 365',
                'category' => self::CATEGORY_EMAIL,
                'icon' => 'bi-microsoft',
                'auth_type' => 'oauth',
                // Microsoft Graph delegated scopes. offline_access → refresh token.
                'scopes' => ['offline_access', 'Mail.Read'],
                // {tenant} is substituted per connection from `oauth_tenant`,
                // falling back to tenant_default. It is NOT decoration:
                // Microsoft refuses the shared /common endpoint for a
                // single-tenant app registration created after 15/10/2018
                // (AADSTS50194) — which is the default when you register an app
                // in your own directory. Hardcoding /common here made every
                // Outlook connection fail in production on 2026-07-17 with
                // permissions fully granted, and the operator had no field to
                // fix it with. See OAuthService::endpoint().
                'authorize_url' => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize',
                'token_url' => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token',
                // Only correct for an app marked multi-tenant; a single-tenant
                // registration must set oauth_tenant to its directory ID.
                'tenant_default' => 'common',
                // select_account is load-bearing, not polish. Microsoft's default
                // is to "sign in the sole current user" — no picker — so an
                // operator who is already signed in (they have just come from the
                // Azure portal to copy the client id and tenant) silently
                // authorizes THAT session. Delegated Graph reads whoever
                // consented, so the wrong mailbox gets connected without a single
                // prompt, and the workflow's label still says the mailbox they
                // meant. On 2026-07-17 that surfaced as 404
                // MailboxNotEnabledForRESTAPI: an admin identity, no Exchange
                // Online licence, nothing to read. Forcing the picker makes the
                // operator say WHICH mailbox out loud.
                'auth_params' => ['response_mode' => 'query', 'prompt' => 'select_account'],
                'enabled' => true,
                'blurb' => 'Read mail from an Outlook / Microsoft 365 mailbox.',
            ],
            [
                'id' => 'imap',
                'name' => 'Generic IMAP',
                'category' => self::CATEGORY_EMAIL,
                'icon' => 'bi-inbox',
                'auth_type' => 'imap',
                'scopes' => [],
                // No fixed host — the user supplies host/port. Sensible SSL defaults.
                'imap' => ['host' => '', 'port' => 993, 'encryption' => 'ssl'],
                'enabled' => true,
                'blurb' => 'Connect any IMAP mailbox with host + app password.',
            ],
            [
                'id' => 'yahoo',
                'name' => 'Yahoo Mail',
                'category' => self::CATEGORY_EMAIL,
                'icon' => 'bi-envelope',
                // Yahoo discontinued basic-auth IMAP; third-party access is via an
                // app password over IMAP SSL (not OAuth). Preset the Yahoo host.
                'auth_type' => 'imap',
                'scopes' => [],
                'imap' => ['host' => 'imap.mail.yahoo.com', 'port' => 993, 'encryption' => 'ssl'],
                'enabled' => true,
                'blurb' => 'Yahoo Mail via IMAP — needs a Yahoo app password.',
            ],

            // ── STORAGE DESTINATIONS ───────────────────────────────────
            [
                'id' => 'gdrive',
                'name' => 'Google Drive',
                'category' => self::CATEGORY_STORAGE,
                'icon' => 'bi-google',
                'auth_type' => 'oauth',
                // Full Drive access — DELIBERATE, and a step down from least privilege.
                //
                // The tighter 'drive.file' scope reaches ONLY files this app created, so a
                // folder the operator made in the browser is invisible to us and a pasted
                // folder link 404s. The product requirement is "file into OUR existing
                // invoices folder", which drive.file cannot satisfy without the Google
                // Picker (a large frontend lift). Chosen by the operator 2026-07-16.
                //
                // Consequences to keep in mind:
                //  - This is a Google RESTRICTED scope. Fine while the OAuth app is in
                //    Testing / Internal; an External+Production app needs CASA review.
                //  - It grants read/write across the whole Drive of the connected account,
                //    so connect a service/finance account rather than a personal one.
                // To revert: set this back to '…/auth/drive.file' and have operators enter a
                // folder NAME instead of a link — GoogleDriveAdapter::resolveFolder()
                // supports both, and find-or-creates a name-based folder the app then owns.
                'scopes' => ['https://www.googleapis.com/auth/drive'],
                // Same Google OAuth endpoints as Gmail — offline access for a refresh token.
                'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token_url' => 'https://oauth2.googleapis.com/token',
                'auth_params' => ['access_type' => 'offline', 'prompt' => 'consent'],
                'enabled' => true,
                'blurb' => 'Save attachments into a Drive folder, by month.',
            ],
            [
                'id' => 'onedrive',
                'name' => 'OneDrive',
                'category' => self::CATEGORY_STORAGE,
                'icon' => 'bi-cloud',
                'auth_type' => 'oauth',
                'scopes' => ['Files.ReadWrite'],
                'enabled' => false,
                'blurb' => 'Coming soon.',
            ],
            [
                'id' => 'dropbox',
                'name' => 'Dropbox',
                'category' => self::CATEGORY_STORAGE,
                'icon' => 'bi-dropbox',
                'auth_type' => 'oauth',
                'scopes' => [],
                'enabled' => false,
                'blurb' => 'Coming soon.',
            ],
            [
                'id' => 's3',
                'name' => 'S3-compatible',
                'category' => self::CATEGORY_STORAGE,
                'icon' => 'bi-bucket',
                'auth_type' => 'credentials',
                'scopes' => [],
                'enabled' => false,
                'blurb' => 'Coming soon.',
            ],

            // ── LOG / DOCUMENT DESTINATIONS ────────────────────────────
            [
                'id' => 'gsheets',
                'name' => 'Google Sheets',
                'category' => self::CATEGORY_LOG,
                'icon' => 'bi-file-earmark-spreadsheet',
                'auth_type' => 'oauth',
                'scopes' => ['https://www.googleapis.com/auth/spreadsheets'],
                // Same Google OAuth endpoints as Gmail — offline access for a refresh token.
                'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token_url' => 'https://oauth2.googleapis.com/token',
                'auth_params' => ['access_type' => 'offline', 'prompt' => 'consent'],
                'enabled' => true,
                'blurb' => 'Log each document to a Sheet, a tab per month.',
            ],
            [
                'id' => 'excel',
                'name' => 'Microsoft Excel (OneDrive)',
                'category' => self::CATEGORY_LOG,
                'icon' => 'bi-file-earmark-excel',
                'auth_type' => 'oauth',
                'scopes' => ['Files.ReadWrite'],
                'enabled' => false,
                'blurb' => 'Coming soon.',
            ],
            [
                'id' => 'airtable',
                'name' => 'Airtable',
                'category' => self::CATEGORY_LOG,
                'icon' => 'bi-table',
                'auth_type' => 'credentials',
                'scopes' => [],
                'enabled' => false,
                'blurb' => 'Coming soon.',
            ],
            [
                'id' => 'notion',
                'name' => 'Notion',
                'category' => self::CATEGORY_LOG,
                'icon' => 'bi-journal-text',
                'auth_type' => 'oauth',
                'scopes' => [],
                'enabled' => false,
                'blurb' => 'Coming soon.',
            ],
        ];
    }

    /** @return array<int, array<string,mixed>> providers in one category. */
    public static function forCategory(string $category): array
    {
        return array_values(array_filter(
            self::all(),
            fn ($p) => $p['category'] === $category
        ));
    }

    /** @return array<string,mixed>|null a single provider by id. */
    public static function find(string $id): ?array
    {
        foreach (self::all() as $p) {
            if ($p['id'] === $id) {
                return $p;
            }
        }

        return null;
    }

    public static function isEnabled(string $id): bool
    {
        return (bool) (self::find($id)['enabled'] ?? false);
    }

    /** Display name for a provider id, or the id itself as a fallback. */
    public static function name(string $id): string
    {
        return self::find($id)['name'] ?? $id;
    }

    /** Auth type for a provider: 'oauth' | 'imap' (or '' if unknown). */
    public static function authType(string $id): string
    {
        return (string) (self::find($id)['auth_type'] ?? '');
    }

    public static function isOAuth(string $id): bool
    {
        return self::authType($id) === 'oauth';
    }

    /**
     * True when this provider's endpoints are scoped to a directory/tenant —
     * i.e. the connection may carry an `oauth_tenant`.
     *
     * Derived from the URL template rather than a provider-id allow-list, so a
     * future tenant-scoped provider works by declaring {tenant} and nothing
     * else. Drives both the wizard field and what the controller will persist.
     */
    public static function isTenantScoped(string $id): bool
    {
        $provider = self::find($id);

        return str_contains((string) ($provider['authorize_url'] ?? ''), '{tenant}')
            || str_contains((string) ($provider['token_url'] ?? ''), '{tenant}');
    }

    public static function isImap(string $id): bool
    {
        return self::authType($id) === 'imap';
    }
}
