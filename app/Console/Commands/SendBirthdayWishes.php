<?php

namespace App\Console\Commands;

use App\Mail\BirthdayWishMail;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendBirthdayWishes extends Command
{
    protected $signature   = 'birthdays:send-wishes';
    protected $description = 'Send a birthday e-card to every active employee whose birthday is today (Feb 29 babies receive theirs on Feb 28 in non-leap years).';

    public function handle(): int
    {
        $today = Carbon::today();
        $month = $today->month;
        $day   = $today->day;
        $year  = $today->year;

        $query = Employee::whereNull('active_until')
            ->whereNotNull('company_email')
            ->where('company_email', '!=', '')
            ->whereNotNull('date_of_birth')
            ->where(function ($q) use ($year) {
                $q->whereNull('birthday_email_sent_year')
                  ->orWhere('birthday_email_sent_year', '!=', $year);
            })
            ->where(function ($q) use ($month, $day, $today) {
                $q->where(function ($q2) use ($month, $day) {
                    $q2->whereRaw('MONTH(date_of_birth) = ?', [$month])
                       ->whereRaw('DAY(date_of_birth) = ?', [$day]);
                });
                if ($month === 2 && $day === 28 && !$today->isLeapYear()) {
                    $q->orWhere(function ($q2) {
                        $q2->whereRaw('MONTH(date_of_birth) = ?', [2])
                           ->whereRaw('DAY(date_of_birth) = ?', [29]);
                    });
                }
            });

        $birthdayEmployees = $query->get();
        $this->info("Birthday check for {$today->toDateString()} — found {$birthdayEmployees->count()} employee(s).");

        $sent = 0;
        $failed = 0;
        foreach ($birthdayEmployees as $emp) {
            try {
                Mail::to($emp->company_email)->send(new BirthdayWishMail($emp));
                $emp->update(['birthday_email_sent_year' => $year]);
                $sent++;
                $this->info("  Sent → {$emp->full_name} ({$emp->company_email})");
            } catch (\Throwable $e) {
                $failed++;
                Log::warning("BirthdayWishMail failed for employee #{$emp->id} ({$emp->company_email}): " . $e->getMessage());
                $this->error("  Failed → {$emp->full_name}: {$e->getMessage()}");
            }
        }

        $this->info("Done. Sent: {$sent}, Failed: {$failed}.");
        return self::SUCCESS;
    }
}
