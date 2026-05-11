<?php

namespace App\Mail;

use App\Models\Company;
use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BirthdayWishMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  Employee  $employee
     * @param  int|null  $themeOverride  Optional 0-based theme index to force a
     *                                   specific look (handy for HR previews).
     *                                   When null, the theme is derived from
     *                                   the employee's id for consistency.
     */
    public function __construct(public Employee $employee, public ?int $themeOverride = null) {}

    public function envelope(): Envelope
    {
        $name = $this->resolveGreetingName();
        return new Envelope(subject: "Happy Birthday, {$name}! 🎉");
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.birthday-wish',
            with: [
                'employee'     => $this->employee,
                'greetingName' => $this->resolveGreetingName(),
                'companyName'  => $this->employee->company ?: 'our company',
                'theme'        => $this->resolveTheme(),
                'logoUrl'      => $this->resolveCompanyLogoUrl(),
            ],
        );
    }

    private function resolveGreetingName(): string
    {
        if (!empty($this->employee->preferred_name)) {
            return $this->employee->preferred_name;
        }
        $full = trim((string) ($this->employee->full_name ?? ''));
        if ($full !== '') {
            $parts = preg_split('/\s+/', $full);
            return $parts[0] ?? $full;
        }
        return 'there';
    }

    /**
     * Pick a deterministic visual theme per employee so each person always
     * receives the same card, but different employees get different colours
     * and decorations. Indexed by employee.id so it's reproducible across
     * sends and survives without any DB column.
     */
    private function resolveTheme(): array
    {
        $themes = [
            // Rose Garden
            ['primary'=>'#ec4899', 'accent'=>'#be185d', 'bg'=>'#fdf2f8',
             'cake'=>'&#127874;', // 🎂
             'confetti'=>'&#127880; &#127873; &#127881; &#127882; &#127880;'], // 🎈 🎁 🎉 🎊 🎈
            // Ocean Breeze
            ['primary'=>'#0ea5e9', 'accent'=>'#0369a1', 'bg'=>'#f0f9ff',
             'cake'=>'&#127856;', // 🍰
             'confetti'=>'&#127881; &#127874; &#127873; &#127874; &#127881;'], // 🎉 🎂 🎁 🎂 🎉
            // Mint Fresh
            ['primary'=>'#10b981', 'accent'=>'#047857', 'bg'=>'#ecfdf5',
             'cake'=>'&#129473;', // 🧁
             'confetti'=>'&#127882; &#127880; &#127872; &#127880; &#127882;'], // 🎊 🎈 🎀 🎈 🎊
            // Royal Purple
            ['primary'=>'#8b5cf6', 'accent'=>'#6d28d9', 'bg'=>'#f5f3ff',
             'cake'=>'&#127874;', // 🎂
             'confetti'=>'&#129395; &#127880; &#127873; &#127874; &#129395;'], // 🥳 🎈 🎁 🎂 🥳
            // Sunset Orange
            ['primary'=>'#f97316', 'accent'=>'#c2410c', 'bg'=>'#fff7ed',
             'cake'=>'&#127856;', // 🍰
             'confetti'=>'&#127881; &#127880; &#127873; &#127880; &#127881;'], // 🎉 🎈 🎁 🎈 🎉
            // Tropical Teal
            ['primary'=>'#14b8a6', 'accent'=>'#0f766e', 'bg'=>'#f0fdfa',
             'cake'=>'&#129473;', // 🧁
             'confetti'=>'&#127882; &#127873; &#127872; &#127873; &#127882;'], // 🎊 🎁 🎀 🎁 🎊
        ];
        $count = count($themes);
        $index = $this->themeOverride !== null
            ? (((int) $this->themeOverride) % $count + $count) % $count
            : (int) ($this->employee->id ?? 0) % $count;
        return $themes[$index];
    }

    /**
     * Resolve the public URL for the employee's company logo, matching the
     * pattern used by WelcomeNewHire. Returns null when no logo is on file,
     * so the template can gracefully fall back to a text-only header.
     */
    private function resolveCompanyLogoUrl(): ?string
    {
        if (empty($this->employee->company)) return null;
        $company = Company::where('name', $this->employee->company)->first();
        if ($company?->logo_path) {
            return asset('storage/' . $company->logo_path);
        }
        return null;
    }
}
