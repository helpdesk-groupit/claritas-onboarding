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
    /**
     * Bound for RAW provider text only — see cap().
     *
     * We bound what the PROVIDER wrote, never what WE wrote: provider text is
     * untrusted, unbounded, and can echo tokens or PII. Our own explanations
     * are literals plus our own validated columns (mailbox, host), so capping
     * them buys no safety and only truncates the remedy mid-sentence — which is
     * the one part of the string that has to survive.
     */
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
        //
        // The admin-console sentence is load-bearing, not padding. An earlier
        // draft read "or ask your mail administrator to", which sent the
        // operator to Zoho's Admin Console → Mail Settings → Email Policy →
        // Restrictions. That screen says "IMAP Access: Enabled" and means
        // "users are ALLOWED to switch IMAP on" — not "IMAP is on". So the
        // remedy looked already-done while every login kept being refused. Any
        // future edit must keep the permit-vs-enable distinction.
        if (self::matches($chain, [
            'yet to enable imap',        // Zoho
            'imap access is disabled',
            'imap is disabled',
            'imap access is not enabled',
            'imap not enabled',
            'imap access disabled',
        ])) {
            return "IMAP is switched off for {$mailbox}, so the mail server refuses every login. "
                .'Sign in as that mailbox and switch it on: '.self::settingsHint($conn).'. '
                .'An org-wide IMAP policy in the admin console only PERMITS IMAP — it does not switch it on for '
                .'a mailbox, so "Enabled" there is not enough on its own. This is a per-mailbox setting, which is '
                .'why another mailbox on the same host can work while this one does not.';
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
            return "{$mailbox} rejected the username or password. Most providers require an app-specific password "
                .'for IMAP rather than the normal sign-in password — generate one in the mail account\'s security '
                .'settings and add the account again.';
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
            return 'Could not reach '.self::server($conn).'. Check the host, port and encryption are right for this '
                .'provider, and that the mail server accepts connections from this network.';
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

    /**
     * Collapse whitespace and bound the length. Applied to RAW provider text
     * only — our own explanations are trusted and must not be truncated.
     */
    private static function cap(string $message): string
    {
        return mb_substr(preg_replace('/\s+/', ' ', $message) ?? $message, 0, self::MAX_LENGTH);
    }
}
