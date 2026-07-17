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
     * Explain an OAuth authorization rejection — the `error` + `error_description`
     * the provider redirects back with when consent does not complete.
     *
     * Same contract as explain(): name the remedy, fall back to a bounded copy
     * of the provider's own words. Kept here rather than in the controller so
     * the signatures are unit-tested, and so Gmail/Drive/Sheets get the generic
     * OAuth codes for free.
     *
     * WHY THIS EXISTS: the callback used to answer every rejection with a fixed
     * "Authorization was cancelled or failed" and log nothing at all. Microsoft
     * had already sent the reason — `error_description` carries the AADSTS code
     * and, per Microsoft's own docs, "most of the useful information about why
     * the error occurred" — and we dropped it on the floor. A Microsoft
     * connection could then fail every attempt for hours while the operator saw
     * "cancelled" (it was not) and the log stayed empty. Never reduce a provider
     * diagnosis to a fixed string: it is the only copy.
     *
     * Order matters — AADSTS codes are matched before the generic OAuth codes,
     * because the interesting Microsoft failures all masquerade as a plain
     * `access_denied` / `invalid_request` and are only separable by their code.
     */
    public static function explainOAuthError(string $error, ?string $description = null): string
    {
        $haystack = mb_strtolower($error.' '.$description);

        // ── Consent withheld: the tenant reserves it for an admin ────────
        //
        // The signature failure of "authorize works but the connection fails":
        // sign-in genuinely succeeds, then Microsoft refuses to hand over the
        // scopes. Most Microsoft 365 tenants ship with user consent turned off,
        // so a non-admin can never complete this flow no matter how many times
        // they retry — and AADSTS90094 arrives labelled `access_denied`, which
        // is indistinguishable from a cancel without reading the code.
        //
        // Both paths to the remedy are named on purpose: pressing "Grant admin
        // consent" is the durable fix, but an admin completing the sign-in and
        // ticking "Consent on behalf of your organization" also works, and is
        // often the faster one when an admin is already at the keyboard.
        if (self::matches($haystack, ['aadsts90094', 'aadsts65001', 'consent_required'])) {
            return 'Microsoft accepted the sign-in but would not grant the permissions: this tenant requires an '
                .'administrator to approve them, which is the default for most Microsoft 365 tenants. Retrying will '
                .'keep failing until consent is granted once. Ask a Microsoft 365 admin to open Azure → App '
                .'registrations → this app → API permissions and press "Grant admin consent for <your tenant>" — or '
                .'to complete this sign-in themselves and tick "Consent on behalf of your organization".';
        }

        // ── Single-tenant registration on the shared /common endpoint ────
        //
        // A trap in our own registry, not operator error: the authorize URL is
        // hardcoded to /common, and Microsoft refuses /common for a
        // single-tenant app created after 15/10/2018 — which is the default
        // when you register an app in your own tenant. The app registration
        // looks perfect (permissions present, secret valid), so the remedy has
        // to name the exact setting.
        if (self::matches($haystack, ['aadsts50194', 'aadsts700016', 'not configured as a multi-tenant application'])) {
            return 'This module signs in through Microsoft\'s shared /common endpoint, which only accepts an app '
                .'registration marked multitenant — and this one is single-tenant, so Microsoft rejects the sign-in '
                .'before it reaches us. In Azure → App registrations → this app → Authentication → Supported account '
                .'types, choose "Accounts in any organizational directory (Any Microsoft Entra ID tenant — '
                .'Multitenant)", then connect again.';
        }

        // ── Mailbox lives in another tenant ──────────────────────────────
        if (self::matches($haystack, ['aadsts50020', 'does not exist in tenant', 'aadsts50128'])) {
            return 'The mailbox that signed in belongs to a different Microsoft tenant than the one this app is '
                .'registered in, and the registration only accepts its own tenant. Either sign in with a mailbox in '
                .'the same tenant as the app registration, or set Authentication → Supported account types to '
                .'multitenant.';
        }

        // ── Scopes rejected ──────────────────────────────────────────────
        if (self::matches($haystack, ['aadsts70011', 'invalid_scope', 'invalid scope'])) {
            return 'Microsoft rejected the permissions this module asked for. The app registration needs the '
                .'DELEGATED Microsoft Graph permissions Mail.Read and offline_access (offline_access is what keeps '
                .'the connection alive without a daily re-login). Add them under API permissions, grant admin '
                .'consent, then connect again.';
        }

        // ── App not available to this account ────────────────────────────
        if (self::matches($haystack, ['unauthorized_client', 'aadsts700016', 'aadsts50011', 'redirect uri', 'reply url'])) {
            return 'Microsoft would not accept this app registration for the account that signed in — usually the '
                .'client ID belongs to a different tenant, or the redirect URI on the registration does not exactly '
                .'match this site\'s callback URL. Check both under App registrations → Authentication.';
        }

        // ── Transient ────────────────────────────────────────────────────
        if (self::matches($haystack, ['temporarily_unavailable', 'server_error', 'aadsts50058'])) {
            return 'The provider reported a temporary problem and did not complete the sign-in. Please try again in '
                .'a moment; if it keeps happening, the details are in the log.';
        }

        // ── A genuine cancel — and the ambiguity that survives it ─────────
        //
        // Deliberately still mentions consent policy: a blocked consent can
        // arrive as a bare access_denied with no code, and "you cancelled it"
        // would then be a confident lie.
        if (self::matches($haystack, ['access_denied'])) {
            return 'The sign-in was cancelled, or consent was declined, so no account was connected. If you did not '
                .'cancel it, this tenant most likely blocks users from consenting to apps — ask an admin to press '
                .'"Grant admin consent" on the app registration\'s API permissions.';
        }

        // Unrecognised: the provider's own words, bounded. Never a fixed string.
        //
        // Capped ONCE over the whole provider portion rather than per field —
        // two independently-capped fields can still add up past the bound, and
        // the bound exists to hold untrusted text as a whole. The leading
        // sentence is ours, so it sits outside the cap (see cap()).
        return 'The provider refused the authorization. '
            .self::cap(trim($error.(blank($description) ? '' : ': '.$description)));
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
