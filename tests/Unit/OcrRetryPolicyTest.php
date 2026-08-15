<?php

namespace Tests\Unit;

use App\Services\ClaimReceiptOcrService;
use App\Services\VendorDocumentInsightService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Tests\TestCase;

/**
 * How long the vision transport waits, and what it does when the wait runs out.
 *
 * The 45s default was sized for this class's own work — one photo of a receipt, ~2000
 * tokens out. The vendor document reader asks the same transport to transcribe a whole
 * multi-page PDF, and the request is not streamed, so the client sits at zero bytes until
 * the entire generation finishes. On live that read timed out twice and was reported to the
 * operator as an unreadable document, which it was not.
 *
 * Extends Tests\TestCase rather than PHPUnit's: everything here reads config, and without
 * the container that dies with "Target class [config] does not exist".
 */
class OcrRetryPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('vendors.ai.enabled', true);
        config()->set('claims.ocr.enabled', true);
        config()->set('claims.ocr.provider', 'anthropic');
        config()->set('claims.ocr.api_key', 'test-key');
        config()->set('claims.ocr.model', 'claude-haiku-4-5');
    }

    // ── The ceiling ───────────────────────────────────────────────────────────

    /**
     * The reader must ask for its own ceiling rather than inherit the receipt budget.
     *
     * Pinned through the transport call itself, not by reading the config back: the config
     * key existing proves nothing if `read()` never passes it, which is exactly the state
     * this was in when a signed contract came back "Could not be read".
     */
    public function test_the_document_reader_asks_for_far_longer_than_a_receipt_scan_does(): void
    {
        RecordingInsightService::$lastCall = [];

        RecordingInsightService::read(__FILE__, 'image/png', 'contract', null);

        $this->assertNotSame([], RecordingInsightService::$lastCall, 'the gate refused before the transport was reached');
        $this->assertGreaterThanOrEqual(
            120,
            RecordingInsightService::$lastCall['timeout'],
            'a full transcription cannot finish inside a receipt scan budget'
        );
        // The ceiling is worthless if the reply is capped where a contract cannot fit.
        $this->assertGreaterThan(2048, RecordingInsightService::$lastCall['maxTokens']);
    }

    /** The value is configurable, and the caller honours the configuration. */
    public function test_the_read_timeout_is_configurable(): void
    {
        config()->set('vendors.ai.read_timeout', 240);
        RecordingInsightService::$lastCall = [];

        RecordingInsightService::read(__FILE__, 'image/png', 'contract', null);

        $this->assertSame(240, RecordingInsightService::$lastCall['timeout']);
    }

    // ── What happens when the ceiling is reached ──────────────────────────────

    /**
     * A caller that sized its own wait is telling us the work is genuinely long. Trying the
     * same ceiling a second time cannot succeed — it doubles the wait the operator sits
     * through (91s on live, for a document that was never going to arrive in 45) and may be
     * billed twice for a generation we then discard.
     */
    public function test_a_timeout_is_not_retried_when_the_caller_sized_the_wait(): void
    {
        $this->assertFalse(RetryProbe::retryable($this->timeout(), false));
    }

    /**
     * Left to the transport's own guess, a timeout stays retryable — that guess may simply
     * have been unlucky, and this is the behaviour every existing caller already has.
     */
    public function test_a_timeout_is_still_retried_when_the_ceiling_was_ours_to_guess(): void
    {
        $this->assertTrue(RetryProbe::retryable($this->timeout(), true));
    }

    /**
     * The distinction is between a wait that expired and a connection that never came up.
     * A refused or dropped connection is a blip whatever the ceiling was, and retrying it
     * costs nothing — suppressing that too would turn one dropped packet into a document
     * the operator is told cannot be read.
     */
    public function test_a_connection_that_never_came_up_is_retried_whatever_the_ceiling(): void
    {
        $refused = new ConnectionException('cURL error 7: Failed to connect to api.anthropic.com port 443');

        $this->assertTrue(RetryProbe::retryable($refused, true));
        $this->assertTrue(RetryProbe::retryable($refused, false));
    }

    /** Capacity wobbles retry on both paths; the long ceiling changes nothing about them. */
    public function test_an_overloaded_provider_is_retried_on_either_path(): void
    {
        $overloaded = $this->httpError(529);

        $this->assertTrue(RetryProbe::retryable($overloaded, true, [500, 502, 503, 529]));
        $this->assertTrue(RetryProbe::retryable($overloaded, false, [500, 502, 503, 529]));
    }

    /**
     * 429 is quota, and quota needs a real wait. A fast retry only burns more of it, so the
     * read fails open to a summary typed by hand instead. Unchanged by any of the above.
     */
    public function test_a_rate_limit_is_never_retried(): void
    {
        $this->assertFalse(RetryProbe::retryable($this->httpError(429), true, [500, 502, 503, 529]));
        $this->assertFalse(RetryProbe::retryable($this->httpError(429), false, [500, 502, 503, 529]));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /** The exact shape live produced: cURL 28, nothing received, ceiling reached. */
    private function timeout(): ConnectionException
    {
        return new ConnectionException(
            'cURL error 28: Operation timed out after 45001 milliseconds with 0 bytes received '
            .'for https://api.anthropic.com/v1/messages'
        );
    }

    private function httpError(int $status): RequestException
    {
        return new RequestException(new Response(new \GuzzleHttp\Psr7\Response($status, [], '{}')));
    }
}

/**
 * Reaches the protected retry predicate without loosening its visibility in the service —
 * it is an internal decision, not an API.
 */
class RetryProbe extends ClaimReceiptOcrService
{
    public static function retryable(\Throwable $e, bool $retryTimeouts, array $statuses = [500, 502, 503]): bool
    {
        return static::isRetryable($e, $statuses, $retryTimeouts);
    }
}

/** Captures what the reader asks the transport for, without making a request. */
class RecordingInsightService extends VendorDocumentInsightService
{
    public static array $lastCall = [];

    protected static function callVisionMeta(
        string $prompt,
        string $absolutePath,
        string $mimeType,
        ?string $company,
        int $maxTokens = 2048,
        string $feature = 'claim_receipt_scan',
        ?int $timeout = null
    ): array {
        self::$lastCall = compact('maxTokens', 'feature', 'timeout');

        return [
            'json' => ['summary' => 'read', 'key_points' => [], 'text' => 'read'],
            'stop_reason' => 'end_turn',
        ];
    }
}
