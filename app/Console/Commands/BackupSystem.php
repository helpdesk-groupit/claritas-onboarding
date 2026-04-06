<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BackupSystem extends Command
{
    protected $signature   = 'backup:run
                              {--type=full : Backup type: full, database, code}
                              {--encrypt : Encrypt the backup with AES-256-CBC}
                              {--keep=30 : Number of days to retain backups}';

    protected $description = 'Create encrypted snapshots of the database and/or codebase for disaster recovery.';

    public function handle(): int
    {
        $type     = $this->option('type');
        $encrypt  = $this->option('encrypt');
        $keepDays = (int) $this->option('keep');
        $timestamp = now()->format('Y-m-d_His');
        $backupDir = storage_path('app/backups');

        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0700, true);
        }

        $this->info("Starting {$type} backup...");

        $files = [];

        try {
            if (in_array($type, ['full', 'database'])) {
                $dbFile = $this->backupDatabase($backupDir, $timestamp);
                if ($dbFile) {
                    $files[] = $dbFile;
                }
            }

            if (in_array($type, ['full', 'code'])) {
                $codeFile = $this->backupCodebase($backupDir, $timestamp);
                if ($codeFile) {
                    $files[] = $codeFile;
                }
            }

            // Encrypt backups if requested
            if ($encrypt && !empty($files)) {
                foreach ($files as $i => $file) {
                    $encrypted = $this->encryptFile($file);
                    if ($encrypted) {
                        // Remove unencrypted original
                        @unlink($file);
                        $files[$i] = $encrypted;
                        $this->info("  Encrypted: " . basename($encrypted));
                    }
                }
            }

            // Log backup manifest
            $this->writeManifest($backupDir, $timestamp, $files);

            // Prune old backups
            $this->pruneBackups($backupDir, $keepDays);

            $this->info("Backup completed successfully.");
            Log::info('BackupSystem: completed', ['type' => $type, 'files' => array_map('basename', $files)]);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Backup failed: {$e->getMessage()}");
            Log::error('BackupSystem: failed', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }
    }

    private function backupDatabase(string $dir, string $timestamp): ?string
    {
        $connection = config('database.default');
        $config     = config("database.connections.{$connection}");

        if (!in_array($config['driver'], ['mysql', 'mariadb'])) {
            $this->warn("Database backup only supports MySQL/MariaDB. Skipping.");
            return null;
        }

        $filename = "db_{$timestamp}.sql.gz";
        $filepath = "{$dir}/{$filename}";

        $host     = $config['host'];
        $port     = $config['port'] ?? '3306';
        $database = $config['database'];
        $username = $config['username'];
        $password = $config['password'] ?? '';

        // Dump directly to a .sql file, then compress with PHP's gzencode
        $sqlFile = "{$dir}/db_{$timestamp}.sql";
        $filename = "db_{$timestamp}.sql.gz";
        $filepath = "{$dir}/{$filename}";

        // Build mysqldump command (output to file, avoid pipe for Windows compat)
        $cmd = sprintf(
            'mysqldump --host=%s --port=%s --user=%s %s --single-transaction --routines --triggers --quick --result-file=%s %s',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            $password !== '' ? '--password=' . escapeshellarg($password) : '--skip-password',
            escapeshellarg($sqlFile),
            escapeshellarg($database)
        );

        $this->info("  Dumping database...");
        $result = null;
        $output = [];
        exec($cmd . ' 2>&1', $output, $result);

        // mysqldump returns warnings on stderr but may still succeed
        if (!file_exists($sqlFile) || filesize($sqlFile) < 100) {
            $this->error("  mysqldump failed: " . implode("\n", $output));
            Log::error('BackupSystem: mysqldump failed', ['output' => $output]);
            @unlink($sqlFile);
            return null;
        }

        // Compress with PHP (gzip not guaranteed on Windows)
        $sqlContent = file_get_contents($sqlFile);
        file_put_contents($filepath, gzencode($sqlContent, 9));
        unlink($sqlFile);

        $sizeMb = round(filesize($filepath) / 1024 / 1024, 2);
        $this->info("  Database snapshot: {$filename} ({$sizeMb} MB)");
        return $filepath;
    }

    private function backupCodebase(string $dir, string $timestamp): ?string
    {
        $basePath = base_path();

        $excludeDirs = ['vendor', 'node_modules', 'storage/logs', 'storage/framework/cache',
            'storage/framework/sessions', 'storage/framework/views', 'storage/app/backups', '.git'];

        // Try tar first (available on Linux/NAS), fall back to ZIP (always available via PHP)
        if ($this->commandExists('tar')) {
            $filename = "code_{$timestamp}.tar.gz";
            $filepath = "{$dir}/{$filename}";

            $excludeStr = implode(' ', array_map(fn($d) => "--exclude={$d}", $excludeDirs));
            $cmd = sprintf(
                'tar czf %s %s -C %s .',
                escapeshellarg($filepath),
                $excludeStr,
                escapeshellarg($basePath)
            );

            $this->info("  Archiving codebase (tar)...");
            exec($cmd . ' 2>&1', $output, $result);
        } else {
            $filename = "code_{$timestamp}.zip";
            $filepath = "{$dir}/{$filename}";

            $this->info("  Archiving codebase (zip)...");
            $zip = new \ZipArchive();
            if ($zip->open($filepath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                $this->error("  Could not create ZIP archive.");
                return null;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $item->getPathname());
                $relativePath = str_replace('\\', '/', $relativePath);

                // Skip excluded directories
                $skip = false;
                foreach ($excludeDirs as $exc) {
                    if (str_starts_with($relativePath, $exc . '/') || $relativePath === $exc) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) continue;

                if ($item->isDir()) {
                    $zip->addEmptyDir($relativePath);
                } else {
                    $zip->addFile($item->getPathname(), $relativePath);
                }
            }
            $zip->close();
        }

        if (!file_exists($filepath) || filesize($filepath) < 100) {
            $this->error("  Codebase archive failed.");
            return null;
        }

        $sizeMb = round(filesize($filepath) / 1024 / 1024, 2);
        $this->info("  Codebase snapshot: {$filename} ({$sizeMb} MB)");
        return $filepath;
    }

    private function commandExists(string $command): bool
    {
        $test = PHP_OS_FAMILY === 'Windows'
            ? "where {$command} 2>NUL"
            : "which {$command} 2>/dev/null";
        exec($test, $output, $result);
        return $result === 0;
    }

    /**
     * Encrypt a file using AES-256-CBC with the application key.
     * Output: <original>.enc  (format: IV_16_bytes + ciphertext)
     */
    private function encryptFile(string $filepath): ?string
    {
        $key = $this->getEncryptionKey();
        if (!$key) {
            $this->warn("  BACKUP_ENCRYPTION_KEY not set; falling back to APP_KEY.");
        }

        $encPath  = $filepath . '.enc';
        $iv       = random_bytes(16);
        $cipher   = 'aes-256-cbc';

        $inHandle  = fopen($filepath, 'rb');
        $outHandle = fopen($encPath, 'wb');

        if (!$inHandle || !$outHandle) {
            $this->error("  Cannot open file for encryption.");
            return null;
        }

        // Write IV as first 16 bytes
        fwrite($outHandle, $iv);

        // Compute HMAC over the ciphertext for integrity
        $hmacCtx = hash_init('sha256', HASH_HMAC, $key);

        // Encrypt in 8KB chunks to handle large files
        while (!feof($inHandle)) {
            $chunk = fread($inHandle, 8192);
            if ($chunk === false) break;

            $encrypted = openssl_encrypt($chunk, $cipher, $key, OPENSSL_RAW_DATA, $iv);
            // Pack length prefix for each chunk (4 bytes, big-endian)
            $lenPacked = pack('N', strlen($encrypted));
            fwrite($outHandle, $lenPacked);
            fwrite($outHandle, $encrypted);
            hash_update($hmacCtx, $lenPacked . $encrypted);
        }

        // Append HMAC at the end (32 bytes)
        $hmac = hash_final($hmacCtx, true);
        fwrite($outHandle, $hmac);

        fclose($inHandle);
        fclose($outHandle);

        // Set restrictive permissions
        chmod($encPath, 0600);

        return $encPath;
    }

    private function getEncryptionKey(): string
    {
        // Prefer dedicated backup encryption key; fall back to APP_KEY
        $key = env('BACKUP_ENCRYPTION_KEY') ?: config('app.key');

        // Strip Laravel's base64: prefix if present
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        return $key;
    }

    private function writeManifest(string $dir, string $timestamp, array $files): void
    {
        $manifest = [
            'timestamp'  => $timestamp,
            'created_at' => now()->toIso8601String(),
            'hostname'   => gethostname(),
            'files'      => array_map(fn($f) => [
                'name' => basename($f),
                'size' => filesize($f),
                'sha256' => hash_file('sha256', $f),
            ], $files),
        ];

        $manifestPath = "{$dir}/manifest_{$timestamp}.json";
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT));
        chmod($manifestPath, 0600);

        $this->info("  Manifest: manifest_{$timestamp}.json");
    }

    private function pruneBackups(string $dir, int $keepDays): void
    {
        $cutoff = now()->subDays($keepDays)->timestamp;
        $pruned = 0;

        foreach (glob("{$dir}/*") as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                unlink($file);
                $pruned++;
            }
        }

        if ($pruned > 0) {
            $this->info("  Pruned {$pruned} backup(s) older than {$keepDays} days.");
            Log::info("BackupSystem: pruned {$pruned} old backups");
        }
    }
}
