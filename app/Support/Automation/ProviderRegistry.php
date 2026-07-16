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
                'authorize_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
                'token_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
                'auth_params' => ['response_mode' => 'query'],
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
                // File-scoped — only files this app creates (least privilege).
                'scopes' => ['https://www.googleapis.com/auth/drive.file'],
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

    public static function isImap(string $id): bool
    {
        return self::authType($id) === 'imap';
    }
}
