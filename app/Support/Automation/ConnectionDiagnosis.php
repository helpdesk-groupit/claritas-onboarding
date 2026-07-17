<?php

namespace App\Support\Automation;

use App\Models\EmailWorkflowConnection;
use Throwable;

/**
 * Turns a raw provider failure into a sentence the operator can act on.
 *
 * Pure and network-free — like DetectionEngine — so every signature below is
 * unit-tested rather than discovered in production.
 *
 * Why this exists: the module's failures are almost never "the code broke",
 * they are "the mailbox is configured differently than you think". A banner
 * reading `ImapServerErrorException: NO [ALERT] You are yet to enable IMAP for
 * your account (Failure)` names the PHP class that noticed the problem and
 * leaves the operator to guess the remedy. The remedy is knowable from the
 * signature, so say it.
 *
 * ALWAYS match against the whole exception CHAIN, never `getMessage()` alone:
 * webklex rethrows its causes wrapped (GetMessagesFailedException in
 * Query::curate_messages, ConnectionFailedException around socket errors), so
 * the outermost message names the wrapper and hides the fault that identifies
 * the remedy.
 */
class ConnectionDiagnosis
{
    /** Operator-facing strings stay bounded — provider errors can be huge. */
    public const MAX_LENGTH = 500;

    /**
     * Explain a failure in plain language, falling back to a bounded version of
     * the raw message when the signature isn't one we recognise.
     *
     * $conn is optional and only ever used to name the mailbox/host in the
     * message — an unrecognised error is described identically without it.
     */
    public static function explain(Throwable $e, ?EmailWorkflowConnection $conn = null): string
    {
        $chain = mb_strtolower(self::chain($e));
        $mailbox = self::mailbox($conn);

        // ── IMAP switched off for this mailbox ───────────────────────────
        //
        // The failure that prompted this class. IMAP access is a PER-MAILBOX
        // setting at Zoho, Google Workspace and Microsoft 365 — so a second
        // mailbox on the *same host, same provider, same credentials shape* can
        // reject every login while the first one works perfectly. That looks
        // like a bug in the automation and is not one, which is why the message
        // says so explicitly.
        if (self::matches($chain, [
            'yet to enable imap',        // Zoho
            'imap access is disabled',
            'imap is disabled',
            'imap access is not enabled',
            'imap not enabled',
            'imap access disabled',
        ])) {
            return self::cap(
                "IMAP is switched off for {$mailbox} on the mail provider's side, so it rejects every login. "
                .'Enable IMAP for that mailbox ('.self::settingsHint($conn).') — or ask your mail administrator to — '
                .'then add the account again. IMAP is a per-mailbox setting, which is why another mailbox on the '
                .'same host can work while this one does not.'
            );
        }

        // ── Credentials rejected ─────────────────────────────────────────
        if (self::matches($chain, [
            'authenticationfailed',
            'authentication failed',
            'invalid credentials',
            'invalid username or password',
            'login failed',
            'authenticate failed',
        ])) {
            return self::cap(
                "{$mailbox} rejected the username or password. Most providers require an app-specific password "
                .'for IMAP rather than the normal sign-in password — generate one in the mail account\'s security '
                .'settings and add the account again.'
            );
        }

        // ── Never reached the server ─────────────────────────────────────
        if (self::matches($chain, [
            'connection refused',
            'connection timed out',
            'timed out',
            'getaddrinfo',
            'name resolution',
            'no such host',
            'network is unreachable',
            'could not connect',
            'connection failed',
            'certificate verify failed',
            'ssl operation failed',
        ])) {
            return self::cap(
                'Could not reach '.self::server($conn).'. Check the host, port and encryption are right for this '
                .'provider, and that the mail server accepts connections from this network.'
            );
        }

        return self::cap(self::raw($e));
    }

    /**
     * The bounded raw message — what an unrecognised failure reduces to.
     *
     * RuntimeException is ours (thrown by CaptureService with an already
     * operator-facing string), so it is passed through without the class name
     * that would otherwise be noise.
     */
    public static function raw(Throwable $e): string
    {
        return $e instanceof \RuntimeException
            ? $e->getMessage()
            : class_basename($e).': '.$e->getMessage();
    }

    /** Every message in the exception chain, so wrapped causes still match. */
    private static function chain(Throwable $e): string
    {
        $parts = [];
        for ($cursor = $e; $cursor !== null; $cursor = $cursor->getPrevious()) {
            $parts[] = $cursor->getMessage();
            if (count($parts) > 10) {
                break; // defensive: a cyclic/very deep chain must not hang this
            }
        }

        return implode(' | ', $parts);
    }

    /** @param  array<int,string>  $needles */
    private static function matches(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Where this provider hides its IMAP toggle, when we can tell from the host. */
    private static function settingsHint(?EmailWorkflowConnection $conn): string
    {
        $host = mb_strtolower((string) $conn?->imap_host);

        return match (true) {
            str_contains($host, 'zoho') => 'Zoho Mail → Settings → Mail Accounts → IMAP Access',
            str_contains($host, 'gmail') || str_contains($host, 'google') => 'Gmail → Settings → Forwarding and POP/IMAP',
            str_contains($host, 'outlook') || str_contains($host, 'office365') => 'the Microsoft 365 admin centre → the mailbox → Email apps',
            default => "your mail provider's IMAP settings",
        };
    }

    private static function mailbox(?EmailWorkflowConnection $conn): string
    {
        return $conn?->imap_username ?: ($conn?->account_label ?: 'this mailbox');
    }

    private static function server(?EmailWorkflowConnection $conn): string
    {
        if (! $conn || blank($conn->imap_host)) {
            return 'the mail server';
        }

        return $conn->imap_host.':'.($conn->imap_port ?: 993)
            .' over '.($conn->imap_encryption ?: 'no encryption');
    }

    /** Collapse whitespace and bound the length — provider errors can echo PII. */
    private static function cap(string $message): string
    {
        return mb_substr(preg_replace('/\s+/', ' ', $message) ?? $message, 0, self::MAX_LENGTH);
    }
}
