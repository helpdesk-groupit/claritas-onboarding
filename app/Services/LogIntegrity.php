<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * HMAC-based log integrity protection.
 *
 * Each log entry gets an HMAC-SHA256 signature appended. A chain hash links
 * each entry to all previous entries, making it impossible to alter or delete
 * a single entry without breaking the chain. This provides tamper-evidence
 * for audit logs and security events.
 *
 * Verification: `php artisan log:verify-integrity`
 */
class LogIntegrity
{
    private static ?string $lastChainHash = null;

    /**
     * Write a tamper-evident log entry for security-sensitive operations.
     */
    public static function write(string $channel, string $level, string $message, array $context = []): void
    {
        $key = self::getSigningKey();

        $entry = [
            'timestamp' => now()->toIso8601String(),
            'level'     => $level,
            'message'   => $message,
            'context'   => $context,
            'seq'       => self::getNextSequence(),
        ];

        // Chain hash: HMAC of current entry + previous chain hash
        $previousHash = self::$lastChainHash ?? self::loadLastChainHash();
        $entry['prev_hash'] = $previousHash;

        $payload = json_encode($entry, JSON_UNESCAPED_SLASHES);
        $hmac    = hash_hmac('sha256', $payload, $key);

        $entry['hmac'] = $hmac;
        self::$lastChainHash = $hmac;

        // Persist chain state
        self::saveChainHash($hmac, $entry['seq']);

        // Write signed entry to the integrity log
        $logLine = json_encode($entry, JSON_UNESCAPED_SLASHES);
        file_put_contents(
            self::getLogPath(),
            $logLine . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Verify integrity of the entire log file. Returns array of violations.
     */
    public static function verify(): array
    {
        $key        = self::getSigningKey();
        $logPath    = self::getLogPath();
        $violations = [];

        if (!file_exists($logPath)) {
            return ['Log file does not exist.'];
        }

        $lines    = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $prevHash = null;
        $prevSeq  = 0;

        foreach ($lines as $lineNum => $line) {
            $entry = json_decode($line, true);
            if (!$entry) {
                $violations[] = "Line " . ($lineNum + 1) . ": Invalid JSON.";
                continue;
            }

            // Extract HMAC and reconstruct payload without it
            $storedHmac = $entry['hmac'] ?? null;
            $entryForVerification = $entry;
            unset($entryForVerification['hmac']);

            $expectedPayload = json_encode($entryForVerification, JSON_UNESCAPED_SLASHES);
            $expectedHmac    = hash_hmac('sha256', $expectedPayload, $key);

            if (!$storedHmac || !hash_equals($expectedHmac, $storedHmac)) {
                $violations[] = "Line " . ($lineNum + 1) . ": HMAC mismatch — entry has been tampered with.";
            }

            // Verify chain continuity
            if ($entry['prev_hash'] !== $prevHash) {
                if ($lineNum > 0) {
                    $violations[] = "Line " . ($lineNum + 1) . ": Chain hash broken — entries may have been deleted or reordered.";
                }
            }

            // Verify sequence
            $seq = $entry['seq'] ?? 0;
            if ($seq !== $prevSeq + 1 && $lineNum > 0) {
                $violations[] = "Line " . ($lineNum + 1) . ": Sequence gap ({$prevSeq} → {$seq}) — entries may have been deleted.";
            }

            $prevHash = $storedHmac;
            $prevSeq  = $seq;
        }

        return $violations;
    }

    private static function getSigningKey(): string
    {
        $key = env('LOG_INTEGRITY_KEY') ?: config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }
        return $key;
    }

    private static function getLogPath(): string
    {
        return storage_path('logs/integrity.log');
    }

    private static function getChainStatePath(): string
    {
        return storage_path('logs/.integrity-chain-state');
    }

    private static function loadLastChainHash(): ?string
    {
        $path = self::getChainStatePath();
        if (!file_exists($path)) return null;

        $data = json_decode(file_get_contents($path), true);
        self::$lastChainHash = $data['last_hash'] ?? null;
        return self::$lastChainHash;
    }

    private static function saveChainHash(string $hash, int $seq): void
    {
        file_put_contents(self::getChainStatePath(), json_encode([
            'last_hash' => $hash,
            'last_seq'  => $seq,
        ]), LOCK_EX);
    }

    private static function getNextSequence(): int
    {
        $path = self::getChainStatePath();
        if (!file_exists($path)) return 1;

        $data = json_decode(file_get_contents($path), true);
        return ($data['last_seq'] ?? 0) + 1;
    }
}
