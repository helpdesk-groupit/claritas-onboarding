<?php

namespace Tests\Feature;

use App\Mail\CalendarInvite;
use App\Models\Onboarding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OnboardingCalendarInviteRecipientsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Reproduces the real-world case: a resigned HR Executive (is_active=false)
     * must not be CC'd on the onboarding calendar invite just because no
     * explicit hr_emails were picked (fallback-by-role path).
     */
    public function test_a_deactivated_hr_executive_is_excluded_from_the_fallback_recipients(): void
    {
        Mail::fake();

        $active = User::factory()->create([
            'role' => 'hr_executive', 'is_active' => true, 'work_email' => 'active.hr@claritas.test',
        ]);
        $resigned = User::factory()->create([
            'role' => 'hr_executive', 'is_active' => false, 'work_email' => 'resigned.hr@claritas.test',
        ]);

        // No hr_emails selected -> resolveRecipients() falls back to role query.
        $onboarding = Onboarding::factory()->create(['hr_emails' => [], 'it_emails' => []]);

        $this->invokeSendCalendarInvites($onboarding);

        Mail::assertSent(CalendarInvite::class, function ($mail) use ($active, $resigned) {
            if ($mail->recipientName !== 'HR Team') {
                return false;
            }
            $addresses = $this->allAddresses($mail);

            return in_array($active->work_email, $addresses) && ! in_array($resigned->work_email, $addresses);
        });
    }

    /** The "always guarantee every HR Manager" block must also exclude a deactivated one. */
    public function test_a_deactivated_hr_manager_is_excluded_from_the_guaranteed_list(): void
    {
        Mail::fake();

        $activeManager = User::factory()->create([
            'role' => 'hr_manager', 'is_active' => true, 'work_email' => 'active.manager@claritas.test',
        ]);
        $resignedManager = User::factory()->create([
            'role' => 'hr_manager', 'is_active' => false, 'work_email' => 'resigned.manager@claritas.test',
        ]);

        $onboarding = Onboarding::factory()->create([
            'hr_emails' => ['someone.else@claritas.test'], // explicit selection, unrelated to either manager
            'it_emails' => [],
        ]);

        $this->invokeSendCalendarInvites($onboarding);

        Mail::assertSent(CalendarInvite::class, function ($mail) use ($activeManager, $resignedManager) {
            if ($mail->recipientName !== 'HR Team') {
                return false;
            }
            $addresses = $this->allAddresses($mail);

            return in_array($activeManager->work_email, $addresses) && ! in_array($resignedManager->work_email, $addresses);
        });
    }

    /** The IT-manager guarantee block has the identical fix and must behave the same way. */
    public function test_a_deactivated_it_manager_is_excluded_from_the_guaranteed_list(): void
    {
        Mail::fake();

        $activeItManager = User::factory()->create([
            'role' => 'it_manager', 'is_active' => true, 'work_email' => 'active.it@claritas.test',
        ]);
        $resignedItManager = User::factory()->create([
            'role' => 'it_manager', 'is_active' => false, 'work_email' => 'resigned.it@claritas.test',
        ]);

        $onboarding = Onboarding::factory()->create(['hr_emails' => [], 'it_emails' => []]);

        $this->invokeSendCalendarInvites($onboarding);

        Mail::assertSent(CalendarInvite::class, function ($mail) use ($activeItManager, $resignedItManager) {
            if ($mail->recipientName !== 'IT Team') {
                return false;
            }
            $addresses = $this->allAddresses($mail);

            return in_array($activeItManager->work_email, $addresses) && ! in_array($resignedItManager->work_email, $addresses);
        });
    }

    private function allAddresses(CalendarInvite $mail): array
    {
        $to = collect($mail->to ?? [])->pluck('address')->all();
        $cc = collect($mail->cc ?? [])->pluck('address')->all();

        return array_merge($to, $cc);
    }

    private function invokeSendCalendarInvites(Onboarding $onboarding): void
    {
        $controller = app(\App\Http\Controllers\OnboardingController::class);
        $method = new \ReflectionMethod($controller, 'sendCalendarInvites');
        $method->setAccessible(true);
        $method->invoke($controller, $onboarding);
    }
}
