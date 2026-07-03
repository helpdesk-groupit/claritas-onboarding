<?php

namespace Tests\Unit;

use App\Models\Employee;
use App\Models\User;
use App\Services\ClaimRulesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClaimApproverGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_cannot_be_their_own_approver(): void
    {
        $owner = Employee::factory()->create(['company' => 'Acme']);
        $other = Employee::factory()->create(['company' => 'Acme']);
        // Give the "other" employee an active login so they are a signable approver.
        $u = User::factory()->create(['is_active' => true]);
        $other->update(['user_id' => $u->id]);

        // Self is rejected even though the owner is an active employee.
        $this->assertFalse(ClaimRulesService::isValidApproverFor($owner->id, $owner->id));

        // A different active employee is accepted.
        $this->assertTrue(ClaimRulesService::isValidApproverFor($owner->id, $other->id));

        // Null / zero is rejected.
        $this->assertFalse(ClaimRulesService::isValidApproverFor($owner->id, null));
        $this->assertFalse(ClaimRulesService::isValidApproverFor($owner->id, 0));
    }
}
