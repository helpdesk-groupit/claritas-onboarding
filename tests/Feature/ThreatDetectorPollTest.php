<?php

namespace Tests\Feature;

use App\Models\SecurityAuditLog;
use App\Models\User;
use App\Services\ThreatDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ThreatDetectorPollTest extends TestCase
{
    use RefreshDatabase;

    private function privEscAlerts(): int
    {
        return SecurityAuditLog::where('event_type', 'threat_alert')
            ->where('details', 'like', '%Privilege Escalation%')
            ->count();
    }

    public function test_ticket_chat_poll_403s_do_not_raise_a_privilege_escalation_alert(): void
    {
        Mail::fake();
        $user = User::factory()->create(['role' => 'it_manager']);

        $ctx = [
            'user_id' => $user->id,
            'work_email' => $user->work_email,
            'role' => 'it_manager',
            'url' => 'https://ep.claritasapp.com/tickets/61/messages?after_id=343',
            'method' => 'GET',
            'ip_address' => '::1',
        ];

        // A stale tab polls several times — none of these should count as an attack.
        for ($i = 0; $i < 6; $i++) {
            ThreatDetector::analyze('unauthorized_access', $ctx);
        }

        $this->assertSame(0, $this->privEscAlerts(), 'Benign ticket chat polls must not trip the alert.');
    }

    public function test_real_403_burst_on_a_restricted_page_still_alerts(): void
    {
        Mail::fake();
        $user = User::factory()->create(['role' => 'employee']);

        $ctx = [
            'user_id' => $user->id,
            'work_email' => $user->work_email,
            'role' => 'employee',
            'url' => 'https://ep.claritasapp.com/superadmin/accounts',
            'method' => 'GET',
            'ip_address' => '::1',
        ];

        for ($i = 0; $i < 3; $i++) {
            ThreatDetector::analyze('unauthorized_access', $ctx);
        }

        $this->assertGreaterThanOrEqual(1, $this->privEscAlerts(), 'A genuine 403 burst should still alert.');
    }
}
