<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TicketMessageDedupeTest extends TestCase
{
    use RefreshDatabase;

    private function ticketFor(User $user): Ticket
    {
        $company = Company::create(['name' => 'Acme '.uniqid()]);

        return Ticket::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'department' => 'Group IT',
            'subject' => 'Laptop / Hardware Issue',
            'description' => 'Test',
            'priority' => 'urgent',
            'status' => 'In Progress',
        ]);
    }

    public function test_identical_message_within_window_is_not_duplicated(): void
    {
        Notification::fake();
        $user = User::factory()->superadmin()->withTwoFactor()->create(); // superadmin passes authorizeAccess
        $ticket = $this->ticketFor($user);

        $payload = ['message' => 'hi justine, please confirm the current spec'];

        $first = $this->actingAs($user)->postJson(route('tickets.messages.store', $ticket), $payload);
        $first->assertStatus(201);

        // Three rapid re-sends of the exact same text — should NOT create new rows.
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($user)->postJson(route('tickets.messages.store', $ticket), $payload)->assertOk();
        }

        $this->assertSame(1, TicketMessage::where('ticket_id', $ticket->id)->count(),
            'A rapid duplicate send must not create extra message rows.');
    }

    public function test_a_different_message_is_still_stored(): void
    {
        Notification::fake();
        $user = User::factory()->superadmin()->withTwoFactor()->create();
        $ticket = $this->ticketFor($user);

        $this->actingAs($user)->postJson(route('tickets.messages.store', $ticket), ['message' => 'first'])->assertStatus(201);
        $this->actingAs($user)->postJson(route('tickets.messages.store', $ticket), ['message' => 'second'])->assertStatus(201);

        $this->assertSame(2, TicketMessage::where('ticket_id', $ticket->id)->count());
    }
}
