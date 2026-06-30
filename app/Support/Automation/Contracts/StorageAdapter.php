<?php

namespace App\Support\Automation\Contracts;

use App\Models\EmailWorkflowConnection;

/**
 * Storage destination contract (Google Drive, OneDrive, Dropbox, S3, …).
 *
 * Resolve a folder from a URL/ID, ensure monthly subfolders, dedupe by name,
 * and upload. The engine depends only on this interface.
 */
interface StorageAdapter
{
    /** Registry id, e.g. 'gdrive'. */
    public function providerId(): string;

    /**
     * Resolve a folder from a pasted URL or raw ID.
     *
     * @return array<string,mixed> folder ref (id, name, …)
     */
    public function resolveFolder(EmailWorkflowConnection $conn, string $urlOrId): array;

    /**
     * Ensure a subfolder (e.g. "2026-06") exists under the parent; create if missing.
     *
     * @param  array<string,mixed>  $parent
     * @return array<string,mixed> subfolder ref
     */
    public function ensureSubfolder(EmailWorkflowConnection $conn, array $parent, string $name): array;

    /**
     * Find a file by name in a folder (dedupe support). Null if absent.
     *
     * @param  array<string,mixed>  $folder
     * @return array<string,mixed>|null
     */
    public function findFile(EmailWorkflowConnection $conn, array $folder, string $name): ?array;

    /**
     * Upload bytes as a file into the folder.
     *
     * @param  array<string,mixed>  $folder
     * @return array<string,mixed> stored file ref (id, url, …)
     */
    public function saveFile(EmailWorkflowConnection $conn, array $folder, string $bytes, string $name, string $mime): array;
}
