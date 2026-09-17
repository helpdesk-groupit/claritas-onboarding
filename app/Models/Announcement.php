<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    /**
     * Maximum message length, in CHARACTERS — the single source for the
     * validation rule, the textarea's maxlength and the on-screen counter, so
     * the three can never disagree about when a message is too long.
     *
     * `body` is a MySQL TEXT column (65,535 BYTES), so this leaves room even
     * for a message written entirely in 4-byte characters.
     */
    public const BODY_MAX_LENGTH = 10000;

    protected $fillable = [
        'title',
        'body',
        'companies',
        'attachment_paths',
        'created_by',
    ];

    protected $casts = [
        'companies'        => 'array',
        'attachment_paths' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Company-name variants for tolerant matching. Targets stored on an announcement (from the
     * Company list) and an employee's stored company can drift between "Sdn Bhd" and "Sdn. Bhd."
     * (a 2026-06 employee-company normalisation flipped Claritas to the no-period form, which
     * broke exact matching against announcements still targeting the period form). Returns the
     * value plus both period variants so visibility/email matching survives the difference.
     */
    public static function companyNameVariants(string $company): array
    {
        $company = trim($company);
        if ($company === '') {
            return [];
        }

        return array_values(array_unique(array_filter([
            $company,
            str_ireplace('Sdn. Bhd.', 'Sdn Bhd', $company),
            str_ireplace('Sdn Bhd', 'Sdn. Bhd.', $company),
        ])));
    }

    /**
     * Scope: announcements visible to a given company.
     * null companies column = all companies; otherwise check if company is in the array
     * (tolerant of the Sdn Bhd / Sdn. Bhd. variant).
     */
    public function scopeVisibleTo($query, ?string $company): void
    {
        $query->where(function ($q) use ($company) {
            $q->whereNull('companies');
            if ($company) {
                foreach (self::companyNameVariants($company) as $variant) {
                    $q->orWhereJsonContains('companies', $variant);
                }
            }
        });
    }
}
