<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $fillable = ['name', 'company_group', 'address', 'registration_number', 'phone', 'kwsp_number', 'tin_number', 'socso_number', 'eis_number', 'logo_path'];

    /**
     * Canonical name normalization used for cross-company matching. Mirrors the
     * JS `norm()` in the employee forms: lowercase, non-alphanumerics → single
     * space, trimmed. Keep the two in sync.
     */
    public static function normName(?string $s): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower((string) $s)));
    }

    /**
     * Map of normalized company name → lowercased group label, for companies that
     * belong to a non-empty group. Passed to the reporting-manager pickers so a
     * manager at one grouped company is selectable for another in the same group.
     */
    public static function groupMap(): array
    {
        return static::query()
            ->whereNotNull('company_group')
            ->where('company_group', '!=', '')
            ->get(['name', 'company_group'])
            ->mapWithKeys(fn ($c) => [self::normName($c->name) => strtolower(trim($c->company_group))])
            ->all();
    }

    /**
     * Resolve the Company record for a free-text employee.company value,
     * tolerating "Sdn Bhd" vs "Sdn. Bhd." and punctuation differences.
     */
    public static function forName(?string $name): ?self
    {
        if (! $name) {
            return null;
        }
        $norm = fn ($s) => trim(preg_replace('/\s+/', ' ', strtolower(preg_replace('/[^a-z0-9]+/i', ' ', (string) $s))));
        $target = $norm($name);
        $all = static::all();

        return $all->first(fn ($c) => $norm($c->name) === $target)
            ?? $all->first(fn ($c) => $target !== '' && (str_contains($norm($c->name), $target) || str_contains($target, $norm($c->name))));
    }

    /** Public URL for the company logo, or null. */
    public function logoUrl(): ?string
    {
        return $this->logo_path ? asset('storage/'.$this->logo_path) : null;
    }
}
