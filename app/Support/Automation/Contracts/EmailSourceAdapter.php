<?php

namespace App\Support\Automation\Contracts;

use App\Models\EmailWorkflowConnection;

/**
 * Email source provider contract (Gmail, Outlook, IMAP, …).
 *
 * Every email provider implements this interface; the capture/reconcile
 * engine (Phase 2) and the wizard depend only on the contract, never on a
 * concrete provider. Adding a provider = one class implementing this + one
 * ProviderRegistry entry.
 *
 * NOTE: Phase 1 ships the contract + registry + detection engine. Concrete
 * network-calling adapters (GmailAdapter, …) land in Phase 2 with their
 * integration tests against recorded fixtures.
 */
interface EmailSourceAdapter
{
    /** Registry id, e.g. 'gmail'. */
    public function providerId(): string;

    /**
     * Prove the connection can actually sign in. Returns cleanly or THROWS.
     *
     * Part of the contract because a stored `connected` must mean "we logged
     * in", never "the operator filled the form in". IMAP takes host + username
     * + app-password with no consent round-trip to validate them, so without
     * this the first honest signal is a failed capture run hours later — with
     * the workflow already Active and reporting nothing missing. That is what
     * this method exists to prevent; see EmailWorkflowController::saveConnection.
     *
     * Keep it CHEAP (one round-trip) — it runs synchronously in a web request.
     */
    public function verify(EmailWorkflowConnection $conn): void;

    /**
     * Search the mailbox, NEWEST FIRST.
     *
     * Ordering is part of the contract, not an implementation detail: a capture
     * run that reads oldest-first stops seeing new mail the moment the mailbox
     * outgrows the limit, while still reporting success.
     *
     * `$paging['limit']` is a ceiling, and **0 means unlimited** — return every
     * message in the window. Implementations MUST paginate to honour it rather
     * than passing it to the provider as a page size; every provider here caps
     * a single page (Gmail maxResults, Graph $top, IMAP fetch batch), so a
     * one-shot request silently truncates the sweep.
     *
     * @param  array<string,mixed>  $query  provider-agnostic query (window, keywords)
     * @param  array<string,mixed>  $paging  ['limit' => int]  0 = unlimited
     * @return array<int, array<string,mixed>> normalized messages, newest first
     */
    public function search(EmailWorkflowConnection $conn, array $query, array $paging = []): array;

    /**
     * Fetch a full message (body + attachment metadata).
     *
     * @return array<string,mixed>
     */
    public function getMessage(EmailWorkflowConnection $conn, string $messageId): array;

    /** Download one attachment's bytes. */
    public function downloadAttachment(EmailWorkflowConnection $conn, string $messageId, string $attachmentId): string;

    /** Optionally label/mark a message processed (idempotency aid). */
    public function markProcessed(EmailWorkflowConnection $conn, string $messageId, string $label): void;
}
