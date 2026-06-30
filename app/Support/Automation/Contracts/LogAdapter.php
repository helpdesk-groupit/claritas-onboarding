<?php

namespace App\Support\Automation\Contracts;

use App\Models\EmailWorkflowConnection;

/**
 * Log / document destination contract (Google Sheets, Excel, Airtable, Notion, …).
 *
 * Resolve a target from a URL/ID, ensure a monthly partition (tab/table) with
 * headers, read already-logged idempotency keys, and append rows.
 */
interface LogAdapter
{
    /** Registry id, e.g. 'gsheets'. */
    public function providerId(): string;

    /**
     * Resolve a spreadsheet/base from a pasted URL or raw ID; validate access.
     *
     * @return array<string,mixed> target ref
     */
    public function resolveTarget(EmailWorkflowConnection $conn, string $urlOrId): array;

    /**
     * Ensure a monthly partition (e.g. tab "2026-06") with the given headers.
     *
     * @param  array<string,mixed>  $target
     * @param  array<int,string>  $headers
     * @return array<string,mixed> partition ref
     */
    public function ensurePartition(EmailWorkflowConnection $conn, array $target, string $name, array $headers): array;

    /**
     * Read idempotency keys already present in the target (across partitions).
     *
     * @param  array<string,mixed>  $target
     * @return array<int,string>
     */
    public function listKeys(EmailWorkflowConnection $conn, array $target): array;

    /**
     * Append one row to a partition.
     *
     * @param  array<string,mixed>  $partition
     * @param  array<string,mixed>  $row  label => value
     */
    public function appendRow(EmailWorkflowConnection $conn, array $partition, array $row): void;
}
