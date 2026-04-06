<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BackupRestore extends Command
{
    protected $signature   = 'backup:restore
                              {file : Path to the backup file (absolute or relative to storage/app/backups)}
                              {--decrypt : Decrypt AES-256-CBC encrypted backup before restoring}
                              {--type= : Override type detection: database, code}
                              {--dry-run : Validate the backup without actually restoring}';

    protected $description = 'Restore a database or codebase snapshot from backup.';

    public function handle(): int
    {
        $file   = $this->argument('file');
        $decrypt = $this->option('decrypt');
        $dryRun = $this->option('dry-run');

        // Resolve relative paths
        if (!str_starts_with($file, '/') && !str_starts_with($file, '\\') && !preg_match('/^[A-Za-z]:/', $file)) {
            $file = storage_path("app/backups/{$file}");
        }

        if (!file_exists($file)) {
            $this->error("Backup file not found: {$file}");
            return self::FAILURE;
        }

        $basename = basename($file);
        $this->info("Restoring from: {$basename}");

        // Verify integrity via manifest
        $this->verifyManifest($file);

        try {
            // Decrypt if needed
            $workingFile = $file;
            if ($decrypt || str_ends_with($file, '.enc')) {
                $workingFile = $this->decryptFile($file);
                if (!$workingFile) {
                    return self::FAILURE;
                }
                $basename = basename($workingFile);
            }

            // Detect type
            $type = $this->option('type') ?: $this->detectType($basename);

            if ($dryRun) {
                $this->info("Dry run — backup is valid ({$type} type). No changes made.");
                $this->cleanupTemp($workingFile, $file);
                return self::SUCCESS;
            }

            if (!$this->confirm("This will restore a {$type} backup. Existing data may be overwritten. Continue?")) {
                $this->cleanupTemp($workingFile, $file);
                return self::SUCCESS;
            }

            match ($type) {
                'database' => $this->restoreDatabase($workingFile),
                'code'     => $this->restoreCodebase($workingFile),
                default    => throw new \RuntimeException("Unknown backup type: {$type}"),
            };

            $this->cleanupTemp($workingFile, $file);
            $this->info("Restore completed successfully.");
            Log::info('BackupRestore: completed', ['file' => $basename, 'type' => $type]);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Restore failed: {$e->getMessage()}");
            Log::error('BackupRestore: failed', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }
    }

    private function decryptFile(string $filepath): ?string
    {
        $key    = $this->getEncryptionKey();
        $cipher = 'aes-256-cbc';

        $inHandle = fopen($filepath, 'rb');
        if (!$inHandle) {
            $this->error("Cannot open encrypted file.");
            return null;
        }

        // Read IV (first 16 bytes)
        $iv = fread($inHandle, 16);
        if (strlen($iv) !== 16) {
            $this->error("Invalid encrypted file (missing IV).");
            fclose($inHandle);
            return null;
        }

        $decPath   = preg_replace('/\.enc$/', '', $filepath);
        $outHandle = fopen($decPath, 'wb');
        if (!$outHandle) {
            $this->error("Cannot create decrypted output file.");
            fclose($inHandle);
            return null;
        }

        // Read ciphertext and collect for HMAC verification
        $hmacCtx    = hash_init('sha256', HASH_HMAC, $key);
        $cipherData = '';

        // Read everything except last 32 bytes (HMAC)
        $fileSize  = filesize($filepath);
        $dataEnd   = $fileSize - 32; // HMAC is last 32 bytes
        $pos       = 16; // After IV

        while ($pos < $dataEnd) {
            // Read chunk length prefix
            $lenPacked = fread($inHandle, 4);
            if (strlen($lenPacked) !== 4) break;
            $pos += 4;

            $chunkLen = unpack('N', $lenPacked)[1];
            $encrypted = fread($inHandle, $chunkLen);
            $pos += $chunkLen;

            hash_update($hmacCtx, $lenPacked . $encrypted);

            $decrypted = openssl_decrypt($encrypted, $cipher, $key, OPENSSL_RAW_DATA, $iv);
            if ($decrypted === false) {
                $this->error("Decryption failed — wrong key or corrupted file.");
                fclose($inHandle);
                fclose($outHandle);
                @unlink($decPath);
                return null;
            }
            fwrite($outHandle, $decrypted);
        }

        // Verify HMAC
        $computedHmac = hash_final($hmacCtx, true);
        $storedHmac   = fread($inHandle, 32);

        fclose($inHandle);
        fclose($outHandle);

        if (!hash_equals($computedHmac, $storedHmac)) {
            $this->error("HMAC verification failed — backup may have been tampered with.");
            @unlink($decPath);
            return null;
        }

        $this->info("  Decryption and integrity check passed.");
        return $decPath;
    }

    private function getEncryptionKey(): string
    {
        $key = env('BACKUP_ENCRYPTION_KEY') ?: config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }
        return $key;
    }

    private function detectType(string $basename): string
    {
        if (str_starts_with($basename, 'db_')) return 'database';
        if (str_starts_with($basename, 'code_')) return 'code';
        if (str_contains($basename, '.sql')) return 'database';
        if (str_contains($basename, '.tar')) return 'code';
        throw new \RuntimeException("Cannot detect backup type from filename: {$basename}. Use --type= to specify.");
    }

    private function restoreDatabase(string $filepath): void
    {
        $config   = config('database.connections.' . config('database.default'));
        $host     = escapeshellarg($config['host']);
        $port     = escapeshellarg($config['port'] ?? '3306');
        $database = escapeshellarg($config['database']);
        $username = escapeshellarg($config['username']);
        $password = escapeshellarg($config['password']);

        // Detect if gzipped
        $catCmd = str_ends_with($filepath, '.gz') ? 'gunzip -c' : 'cat';

        $cmd = sprintf(
            '%s %s | mysql --host=%s --port=%s --user=%s --password=%s %s',
            $catCmd, escapeshellarg($filepath), $host, $port, $username, $password, $database
        );

        $this->info("  Restoring database...");
        $output = [];
        $result = null;
        exec($cmd . ' 2>&1', $output, $result);

        if ($result !== 0) {
            throw new \RuntimeException("Database restore failed: " . implode("\n", $output));
        }
        $this->info("  Database restored successfully.");
    }

    private function restoreCodebase(string $filepath): void
    {
        $basePath = base_path();

        $cmd = sprintf(
            'tar xzf %s -C %s',
            escapeshellarg($filepath),
            escapeshellarg($basePath)
        );

        $this->info("  Restoring codebase...");
        $output = [];
        $result = null;
        exec($cmd . ' 2>&1', $output, $result);

        if ($result !== 0) {
            throw new \RuntimeException("Codebase restore failed: " . implode("\n", $output));
        }
        $this->info("  Codebase restored successfully.");
    }

    private function verifyManifest(string $file): void
    {
        $dir = dirname($file);
        $manifests = glob("{$dir}/manifest_*.json");

        foreach ($manifests as $mf) {
            $data = json_decode(file_get_contents($mf), true);
            if (!$data || !isset($data['files'])) continue;

            foreach ($data['files'] as $entry) {
                if ($entry['name'] === basename($file)) {
                    $actualHash = hash_file('sha256', $file);
                    if ($actualHash === $entry['sha256']) {
                        $this->info("  Manifest integrity check: PASSED");
                    } else {
                        $this->warn("  Manifest integrity check: FAILED — file may have been modified.");
                    }
                    return;
                }
            }
        }

        $this->warn("  No manifest found for this backup file.");
    }

    private function cleanupTemp(string $workingFile, string $originalFile): void
    {
        if ($workingFile !== $originalFile && file_exists($workingFile)) {
            @unlink($workingFile);
        }
    }
}
