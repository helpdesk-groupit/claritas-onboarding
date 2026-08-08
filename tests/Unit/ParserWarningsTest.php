<?php

namespace Tests\Unit;

use App\Support\Automation\ParserWarnings;
use ErrorException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pure-logic tests for the mail-parser warning scope — no DB, no network.
 *
 * The behaviour under test is the one that used to cost ~20 captured documents a
 * day: a third-party header makes the parser emit a PHP notice, Laravel's global
 * handler promotes it to an ErrorException, and webklex loses the whole page of
 * messages it was building. Each test installs its own sentinel handler so the
 * assertions describe ParserWarnings, not whatever handler the harness happens
 * to have installed.
 */
class ParserWarningsTest extends TestCase
{
    private int $errorReporting;

    /**
     * Reproduce the level the app actually runs at.
     *
     * Laravel's HandleExceptions::bootstrap() forces `error_reporting(-1)` and
     * then throws an ErrorException for anything the level covers — which is why
     * a parser notice is fatal in production. The CLI's own php.ini here reports
     * far less (no E_WARNING, no E_NOTICE, no E_USER_*), so without this these
     * tests would pass by never raising anything at all.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->errorReporting = error_reporting(E_ALL);
    }

    protected function tearDown(): void
    {
        error_reporting($this->errorReporting);
        parent::tearDown();
    }

    public function test_a_tolerated_warning_is_collected_instead_of_escalated(): void
    {
        set_error_handler(function (int $errno, string $errstr) {
            throw new ErrorException($errstr, 0, $errno);
        });

        try {
            $warnings = [];

            $value = ParserWarnings::tolerate(function () {
                // The real one, verbatim: imap_rfc822_parse_headers() raises this
                // for a malformed address header and still returns a usable message.
                trigger_error('Must use comma to separate addresses: Billing', E_USER_WARNING);

                return 'message parsed anyway';
            }, $warnings);

            $this->assertSame('message parsed anyway', $value, 'The closure must run to completion.');
            $this->assertCount(1, $warnings);
            $this->assertStringContainsString('Must use comma to separate addresses', $warnings[0]);
        } finally {
            restore_error_handler();
        }
    }

    public function test_a_real_php_warning_does_not_abort_the_work_in_progress(): void
    {
        $warnings = [];

        $value = ParserWarnings::tolerate(function () {
            $header = [];
            $from = $header['from'];   // E_WARNING: Undefined array key "from"

            return 'still here'.$from;
        }, $warnings);

        $this->assertSame('still here', $value);
        $this->assertNotSame([], $warnings, 'The diagnostic must still be reported to the caller.');
    }

    public function test_the_previous_handler_is_restored_when_the_scope_ends(): void
    {
        set_error_handler(function (int $errno, string $errstr) {
            throw new RuntimeException('sentinel: '.$errstr);
        });

        try {
            ParserWarnings::tolerate(fn () => trigger_error('inside', E_USER_WARNING));

            // Tolerance is scoped to the closure — outside it, the app's own
            // handling must be exactly what it was before.
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('sentinel: outside');

            trigger_error('outside', E_USER_WARNING);
        } finally {
            restore_error_handler();
        }
    }

    public function test_the_previous_handler_is_restored_even_when_the_closure_throws(): void
    {
        set_error_handler(function (int $errno, string $errstr) {
            throw new RuntimeException('sentinel: '.$errstr);
        });

        try {
            try {
                ParserWarnings::tolerate(function () {
                    throw new RuntimeException('parse gave up');
                });
                $this->fail('The closure’s own exception must propagate.');
            } catch (RuntimeException $e) {
                $this->assertSame('parse gave up', $e->getMessage());
            }

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('sentinel: after');

            trigger_error('after', E_USER_WARNING);
        } finally {
            restore_error_handler();
        }
    }

    public function test_a_level_outside_the_tolerated_set_is_handed_back_to_the_previous_handler(): void
    {
        // Deliberately narrow: this absorbs a parser's grumbling, not real faults.
        set_error_handler(function (int $errno, string $errstr) {
            throw new RuntimeException('delegated: '.$errstr);
        });

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('delegated: boom');

            ParserWarnings::tolerate(fn () => trigger_error('boom', E_USER_ERROR));
        } finally {
            restore_error_handler();
        }
    }

    public function test_a_transport_fault_is_never_absorbed_even_though_its_level_is_tolerated(): void
    {
        // THE HAZARD THIS GUARDS. webklex's ImapProtocol::nextLine() reads a byte
        // at a time and only throws when the line came back EMPTY, so a socket
        // that dies part-way through a line returns TRUNCATED bytes as if they
        // were whole — and the fread() warning is the only evidence it happened.
        // Absorb it and a truncated attachment arrives non-empty, gets stored,
        // gets logged, and is marked complete, so it is never retried. A corrupt
        // invoice filed as a good one is worse than the dropped page this class
        // exists to prevent.
        set_error_handler(function (int $errno, string $errstr) {
            throw new RuntimeException('escalated: '.$errstr);
        });

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('escalated: fread(): SSL: Connection reset by peer');

            ParserWarnings::tolerate(
                fn () => trigger_error('fread(): SSL: Connection reset by peer', E_USER_WARNING)
            );
        } finally {
            restore_error_handler();
        }
    }

    public function test_transport_signatures_are_recognised_and_parser_complaints_are_not(): void
    {
        foreach ([
            'fread(): SSL: Connection reset by peer',
            'fwrite(): send of 8192 bytes failed with errno=32 Broken pipe',
            'stream_get_contents(): SSL operation failed',
            'fgets(): SSL: Connection timed out',
        ] as $transport) {
            $this->assertTrue(ParserWarnings::isTransportFault($transport), $transport);
        }

        foreach ([
            'Must use comma to separate addresses: Billing',
            'Array to string conversion',
            'Undefined array key "from"',
        ] as $parser) {
            $this->assertFalse(ParserWarnings::isTransportFault($parser), $parser);
        }
    }

    public function test_diagnostics_already_silenced_by_the_caller_are_not_reported(): void
    {
        $warnings = [];

        $value = ParserWarnings::tolerate(
            fn () => @file_get_contents(__DIR__.'/no-such-file-'.__FUNCTION__.'.tmp'),
            $warnings
        );

        $this->assertFalse($value);
        $this->assertSame([], $warnings, 'An @-suppressed diagnostic was never going to be reported.');
    }

    public function test_a_repeat_offender_is_recorded_once_not_once_per_message(): void
    {
        // An automated sender emits the same malformed header on every message —
        // which is exactly why whole pages failed together — so the note must
        // collapse rather than fill memory and the log.
        $warnings = [];

        ParserWarnings::tolerate(function () {
            for ($i = 0; $i < 200; $i++) {
                trigger_error('Must use comma to separate addresses: Billing', E_USER_WARNING);
            }
        }, $warnings);

        $this->assertCount(1, $warnings);
    }

    public function test_the_collected_list_is_bounded(): void
    {
        $warnings = [];

        ParserWarnings::tolerate(function () {
            for ($i = 0; $i < ParserWarnings::MAX_COLLECTED + 25; $i++) {
                // Distinct texts, so dedupe cannot do the bounding for us.
                trigger_error('complaint number '.$i, E_USER_WARNING);
            }
        }, $warnings);

        $this->assertCount(ParserWarnings::MAX_COLLECTED, $warnings);
    }

    public function test_the_collected_list_survives_a_closure_that_throws(): void
    {
        // The caller wants the parser's complaints precisely when something went
        // wrong — that is the only clue to WHY the message could not be read.
        $warnings = [];

        try {
            ParserWarnings::tolerate(function () {
                trigger_error('Must use comma to separate addresses: Billing', E_USER_WARNING);

                throw new RuntimeException('and then it gave up');
            }, $warnings);
        } catch (RuntimeException) {
            // expected
        }

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Must use comma', $warnings[0]);
    }
}
