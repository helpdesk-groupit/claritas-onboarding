<?php

namespace App\Console\Commands;

use App\Services\LogIntegrity;
use Illuminate\Console\Command;

class VerifyLogIntegrity extends Command
{
    protected $signature   = 'log:verify-integrity';
    protected $description = 'Verify the integrity of the HMAC-protected audit log chain.';

    public function handle(): int
    {
        $this->info('Verifying log integrity...');

        $violations = LogIntegrity::verify();

        if (empty($violations)) {
            $this->info('All log entries verified — no tampering detected.');
            return self::SUCCESS;
        }

        $this->error('INTEGRITY VIOLATIONS DETECTED:');
        foreach ($violations as $v) {
            $this->line("  ⚠ {$v}");
        }

        $this->error(count($violations) . ' violation(s) found.');
        return self::FAILURE;
    }
}
