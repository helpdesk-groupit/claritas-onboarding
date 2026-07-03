<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClaimHrNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_claim_hr_role_scope_includes_hr_executive_and_excludes_others(): void
    {
        $mgr = User::factory()->hrManager()->create();
        $exec = User::factory()->hrExecutive()->create();
        $super = User::factory()->superadmin()->create();
        $intern = User::factory()->hrIntern()->create();
        $it = User::factory()->itManager()->create();
        $employee = User::factory()->create(['role' => 'employee']);

        $ids = User::claimHrRole()->pluck('id');

        // HR approvers + superadmin are notified...
        $this->assertTrue($ids->contains($mgr->id));
        $this->assertTrue($ids->contains($exec->id), 'HR Executive must be an HR-claim notification recipient');
        $this->assertTrue($ids->contains($super->id));

        // ...but interns, IT and regular employees are not.
        $this->assertFalse($ids->contains($intern->id));
        $this->assertFalse($ids->contains($it->id));
        $this->assertFalse($ids->contains($employee->id));
    }
}
