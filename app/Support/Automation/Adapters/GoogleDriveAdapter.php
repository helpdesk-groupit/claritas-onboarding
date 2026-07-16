<?php

namespace App\Support\Automation\Adapters;

use App\Models\EmailWorkflowConnection;
use App\Support\Automation\Contracts\StorageAdapter;
use App\Support\Automation\OAuthService;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Drive storage adapter — Drive REST v3 via the Laravel HTTP client
 * (no Google SDK dependency, matching GmailAdapter).
 *
 * Endpoints:
 *   GET  drive/v3/files/{id}                    — resolve a folder
 *   GET  drive/v3/files?q=…                     — find subfolder / dedupe by name
 *   POST drive/v3/files                         — create a folder (metadata only)
 *   POST upload/drive/v3/files?uploadType=resumable → PUT session URI  — upload bytes
 *
 * Uploads are resumable, not multipart: Google's uploadType=multipart requires a
 * `multipart/related` body, and Laravel's ->attach() emits `multipart/form-data`,
 * which Drive rejects. Resumable is two plain requests and handles large files.
 *
 * Shared-drive safe: every call passes supportsAllDrives.
 */
class GoogleDriveAdapter implements StorageAdapter
{
    private const BASE = 'https://www.googleapis.com/drive/v3';

    private const UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';

    private const FOLDER_MIME = 'application/vnd.google-apps.folder';

    /** Fields we want back on any file/folder. */
    private const FIELDS = 'id,name,mimeType,webViewLink,parents';

    public function __construct(private readonly OAuthService $oauth) {}

    public function providerId(): string
    {
        return 'gdrive';
    }

    /**
     * Resolve the destination folder from a pasted Drive link, a raw folder ID,
     * or a plain folder name.
     *
     * A name (anything that isn't a link or an ID-shaped string) is
     * find-or-created under My Drive. That path works even under the tight
     * `drive.file` scope, because the app then owns the folder it made.
     *
     * @return array<string,mixed>
     */
    public function resolveFolder(EmailWorkflowConnection $conn, string $urlOrId): array
    {
        $ref = trim($urlOrId);
        if ($ref === '') {
            throw new RuntimeException('No destination folder configured — set one in step 3 of the wizard.');
        }

        $id = $this->extractId($ref);

        // Plain name → find-or-create under My Drive.
        if ($id === null) {
            return $this->findOrCreateFolder($conn, $ref, null);
        }

        $token = $this->oauth->freshAccessToken($conn);

        $res = Http::withToken($token)->get(self::BASE.'/files/'.rawurlencode($id), [
            'fields' => self::FIELDS,
            'supportsAllDrives' => 'true',
        ]);

        if ($res->status() === 404 || $res->status() === 403) {
            // Almost always a stale token rather than a bad link: a connection
            // authorized while the registry still asked for `drive.file` carries
            // that narrow grant, which cannot see folders the app didn't create.
            // Re-consenting mints a token with the current scope. Say so plainly.
            throw new RuntimeException(
                'Google Drive folder is not accessible (HTTP '.$res->status().'). '
                .'Press "Connect" on the Drive connection to re-authorize — a connection authorized '
                .'before this app requested full Drive access can only reach folders it created itself. '
                .'Otherwise check the link, and that the connected account can open that folder.'
            );
        }

        $folder = $res->throw()->json();

        if (($folder['mimeType'] ?? '') !== self::FOLDER_MIME) {
            throw new RuntimeException('That Drive link points to a file, not a folder.');
        }

        return $this->refFrom($folder);
    }

    /** @param array<string,mixed> $parent */
    public function ensureSubfolder(EmailWorkflowConnection $conn, array $parent, string $name): array
    {
        return $this->findOrCreateFolder($conn, $name, (string) ($parent['id'] ?? ''));
    }

    /**
     * @param  array<string,mixed>  $folder
     * @return array<string,mixed>|null
     */
    public function findFile(EmailWorkflowConnection $conn, array $folder, string $name): ?array
    {
        $token = $this->oauth->freshAccessToken($conn);

        $q = sprintf(
            "name = '%s' and '%s' in parents and trashed = false and mimeType != '%s'",
            $this->escapeQuery($name),
            $this->escapeQuery((string) ($folder['id'] ?? '')),
            self::FOLDER_MIME
        );

        $found = $this->queryFiles($token, $q);

        return $found ? $this->refFrom($found) : null;
    }

    /**
     * Upload bytes via a resumable session (init with metadata, then PUT the body).
     *
     * @param  array<string,mixed>  $folder
     * @return array<string,mixed>
     */
    public function saveFile(EmailWorkflowConnection $conn, array $folder, string $bytes, string $name, string $mime): array
    {
        $token = $this->oauth->freshAccessToken($conn);
        $mime = $mime !== '' ? $mime : 'application/octet-stream';

        // 1 — open the session. Metadata goes here; Location carries the session URI.
        $init = Http::withToken($token)
            ->withHeaders([
                'X-Upload-Content-Type' => $mime,
                'X-Upload-Content-Length' => (string) strlen($bytes),
            ])
            ->post(self::UPLOAD.'?uploadType=resumable&supportsAllDrives=true&fields='.self::FIELDS, [
                'name' => $name,
                'parents' => array_filter([$folder['id'] ?? null]),
            ])
            ->throw();

        $session = $init->header('Location');
        if (! $session) {
            throw new RuntimeException('Google Drive did not return an upload session URI.');
        }

        // 2 — send the bytes. withBody() keeps this a raw PUT, not form-encoded.
        $uploaded = Http::withToken($token)
            ->withBody($bytes, $mime)
            ->put($session)
            ->throw()
            ->json();

        return $this->refFrom($uploaded);
    }

    // ── Internals ────────────────────────────────────────────────────────

    /**
     * Find a folder by name (optionally under a parent), creating it if absent.
     *
     * @return array<string,mixed>
     */
    private function findOrCreateFolder(EmailWorkflowConnection $conn, string $name, ?string $parentId): array
    {
        $token = $this->oauth->freshAccessToken($conn);

        $clauses = [
            sprintf("name = '%s'", $this->escapeQuery($name)),
            sprintf("mimeType = '%s'", self::FOLDER_MIME),
            'trashed = false',
        ];
        if (filled($parentId)) {
            $clauses[] = sprintf("'%s' in parents", $this->escapeQuery($parentId));
        }

        if ($existing = $this->queryFiles($token, implode(' and ', $clauses))) {
            return $this->refFrom($existing);
        }

        $created = Http::withToken($token)
            ->post(self::BASE.'/files?supportsAllDrives=true&fields='.self::FIELDS, [
                'name' => $name,
                'mimeType' => self::FOLDER_MIME,
                'parents' => array_filter([$parentId]),
            ])
            ->throw()
            ->json();

        return $this->refFrom($created);
    }

    /**
     * Run a Drive query and return the first hit, or null.
     *
     * @return array<string,mixed>|null
     */
    private function queryFiles(string $token, string $q): ?array
    {
        $res = Http::withToken($token)->get(self::BASE.'/files', [
            'q' => $q,
            'fields' => 'files('.self::FIELDS.')',
            'pageSize' => 1,
            'spaces' => 'drive',
            'supportsAllDrives' => 'true',
            'includeItemsFromAllDrives' => 'true',
        ])->throw()->json();

        return $res['files'][0] ?? null;
    }

    /**
     * Pull a Drive folder/file ID out of a pasted link, or recognise a raw ID.
     * Returns null when the input looks like a plain folder name.
     */
    private function extractId(string $ref): ?string
    {
        // .../folders/<id>, .../d/<id>/edit, ?id=<id>&…
        foreach (['#/folders/([a-zA-Z0-9_-]+)#', '#/d/([a-zA-Z0-9_-]+)#', '#[?&]id=([a-zA-Z0-9_-]+)#'] as $pattern) {
            if (preg_match($pattern, $ref, $m)) {
                return $m[1];
            }
        }

        // A bare ID: long, and drawn only from the Drive ID alphabet. Real folder
        // names are shorter and/or contain spaces, so this stays unambiguous.
        if (preg_match('/^[a-zA-Z0-9_-]{20,}$/', $ref)) {
            return $ref;
        }

        return null;
    }

    /** @param array<string,mixed> $file */
    private function refFrom(array $file): array
    {
        return [
            'id' => (string) ($file['id'] ?? ''),
            'name' => (string) ($file['name'] ?? ''),
            'mime' => (string) ($file['mimeType'] ?? ''),
            'url' => (string) ($file['webViewLink'] ?? ''),
        ];
    }

    /** Escape a value for a Drive `q` string literal. */
    private function escapeQuery(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
