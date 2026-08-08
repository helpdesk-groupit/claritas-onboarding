<?php

namespace App\Support\Automation;

/**
 * Run a closure with PHP's NON-FATAL diagnostics tolerated instead of escalated.
 *
 * Pure and network-free (like DetectionEngine and ConnectionDiagnosis) so it can
 * be unit-tested — see tests/Unit/ParserWarningsTest.php.
 *
 * WHY THIS EXISTS. Laravel's global error handler turns every PHP notice and
 * warning into a thrown ErrorException. That is the right default for
 * application code, and exactly the wrong one for a MAIL PARSER, whose input is
 * written by third parties we cannot fix. A single malformed header —
 *
 *     imap_rfc822_parse_headers(): Must use comma to separate addresses: Billing
 *
 * — is a cosmetic complaint from a lenient C parser that still returns a usable
 * message. Escalated to an exception it becomes fatal, and because webklex builds
 * a whole page of messages in one loop (Query::populate) and rethrows anything
 * unrecognised as GetMessagesFailedException (Query::curate_messages), one
 * complaint costs the ENTIRE PAGE. On the production mailbox that was ~20
 * documents a day, silently: automated senders emit the same malformed header on
 * every message, so consecutive messages fail together and even per-message
 * salvage rescued none of them.
 *
 * Tolerating means: collect the diagnostic, let PHP carry on, and log it once.
 * The message parses, the attachment is captured, and the operator can still read
 * what the parser complained about. Nothing else in the request is affected — the
 * handler is installed around one closure and restored immediately.
 *
 * Deliberately NARROW: only the non-fatal levels are absorbed. Errors,
 * exceptions, and anything else are handed straight back to whichever handler was
 * already installed, so a real bug still surfaces as loudly as before.
 */
final class ParserWarnings
{
    /**
     * The levels a mail parser is allowed to complain at.
     *
     * E_STRICT is deliberately absent — it is unused since PHP 8.0 and
     * deprecated in 8.4, so naming it would raise a diagnostic of its own.
     */
    public const TOLERATED = E_WARNING | E_NOTICE | E_DEPRECATED
        | E_USER_WARNING | E_USER_NOTICE | E_USER_DEPRECATED;

    /** Ceiling on collected notes — a pathological mailbox must not fill memory. */
    public const MAX_COLLECTED = 20;

    /**
     * Diagnostics this must NEVER absorb, whatever their level.
     *
     * The tolerated set is deliberately level-based, but one family of E_WARNING
     * is not a parser grumbling about someone else's header — it is the TRANSPORT
     * failing mid-read, and swallowing it converts a loud, retried failure into
     * silent data corruption. webklex's ImapProtocol::nextLine() reads a byte at
     * a time and only throws when the line came back EMPTY:
     *
     *     while (($next_char = fread($this->stream, 1)) !== false && …) { $line .= $next_char; }
     *     if ($line === "" && ($next_char === false || $next_char === "")) throw …
     *
     * so a connection that dies part-way through a line exits the loop with
     * partial bytes, appends "\n", and returns the truncated line as if it were
     * whole. The `fread(): SSL: Connection reset by peer` warning is the ONLY
     * signal that it happened. Absorb it and a truncated attachment arrives
     * non-empty, gets stored, gets logged, and is marked complete — so the
     * captures table never retries it. A corrupt invoice filed as a good one is
     * strictly worse than the dropped page this class exists to prevent.
     *
     * Matched case-insensitively against the message text.
     */
    public const NEVER_TOLERATED = [
        'fread(',
        'fwrite(',
        'fgets(',
        'fputs(',
        'stream_get_contents(',
        'stream_socket_',
        'ssl:',
        'connection reset',
        'broken pipe',
        'connection timed out',
        'timed out',
    ];

    /**
     * Run $fn; return whatever it returns.
     *
     * $collected is filled with the DISTINCT diagnostics raised (bounded by
     * MAX_COLLECTED), including any raised before $fn threw — the caller usually
     * wants them precisely when something went wrong.
     *
     * @template TReturn
     *
     * @param  callable():TReturn  $fn
     * @param  array<int,string>  $collected
     * @return TReturn
     */
    public static function tolerate(callable $fn, array &$collected = []): mixed
    {
        $collected = [];
        $seen = [];
        $previous = null;

        // The closure captures $previous by REFERENCE, so it sees the handler
        // that set_error_handler() returns on the line below — which is the one
        // that was active before us (Laravel's, normally).
        $previous = set_error_handler(
            function (int $errno, string $errstr, string $errfile = '', int $errline = 0) use (&$collected, &$seen, &$previous): bool {
                // Honour @-suppression and error_reporting(): PHP zeroes the
                // relevant bits, and a silenced diagnostic was never going to be
                // reported in the first place.
                if ((error_reporting() & $errno) === 0) {
                    return true;
                }

                if (($errno & self::TOLERATED) === 0 || self::isTransportFault($errstr)) {
                    // Not ours to absorb. Returning false would fall through to
                    // PHP's INTERNAL handler and lose Laravel's behaviour, so
                    // delegate explicitly instead.
                    return $previous === null
                        ? false
                        : (bool) ($previous)($errno, $errstr, $errfile, $errline);
                }

                $note = $errstr.' @ '.$errfile.':'.$errline;

                if (! isset($seen[$note])) {
                    $seen[$note] = true;

                    if (count($collected) < self::MAX_COLLECTED) {
                        $collected[] = $note;
                    }
                }

                return true; // handled — PHP continues, nothing is thrown
            }
        );

        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * True when a diagnostic is the transport failing rather than a parser
     * complaining. See NEVER_TOLERATED for why this exception exists.
     */
    public static function isTransportFault(string $message): bool
    {
        $haystack = mb_strtolower($message);

        foreach (self::NEVER_TOLERATED as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
