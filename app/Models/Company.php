<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $fillable = ['name', 'address', 'registration_number', 'phone', 'kwsp_number', 'tin_number', 'socso_number', 'eis_number', 'logo_path'];

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
