<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseCategory extends Model
{
    protected $fillable = [
        'company', 'name', 'code', 'gl_code', 'description',
        'monthly_limit', 'rate_type', 'rate_amount', 'limit_period', 'applies_to_role',
        'applies_to_employee_ids', 'requires_receipt', 'is_active',
        'sort_order', 'keywords',
    ];

    protected $casts = [
        'monthly_limit' => 'decimal:2',
        'rate_amount' => 'decimal:2',
        'requires_receipt' => 'boolean',
        'is_active' => 'boolean',
        'keywords' => 'array',
        'applies_to_employee_ids' => 'array',
    ];

    /** True when the line amount is computed/derived rather than the receipt total. */
    public function isComputed(): bool
    {
        return in_array($this->rate_type, ['per_km', 'per_day', 'per_hour', 'fixed'], true);
    }

    /** A flat-subsidy category whose claimable amount is always rate_amount (e.g. season parking RM80). */
    public function isFixed(): bool
    {
        return $this->rate_type === 'fixed';
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    /**
     * Auto-detect the best matching category for a free-text description.
     *
     * Scores every eligible category against the description and returns the highest
     * scorer (tie-broken by sort_order). Scoring is whole-word/phrase aware so short
     * keywords don't false-match inside longer words ("ot" no longer hits "promotion"),
     * and it falls back to the category NAME's own words so categories without explicit
     * keywords are still detectable. Returns null when nothing clears the threshold.
     */
    public static function detectFromDescription(string $description, ?string $company = null): ?self
    {
        // Normalise: strip punctuation to spaces, pad so \b works at the ends.
        $desc = ' '.strtolower(preg_replace('/[^a-z0-9\s]/i', ' ', $description)).' ';
        if (trim($desc) === '') {
            return null;
        }

        $query = static::where('is_active', true)->orderBy('sort_order');
        if ($company) {
            // Category.company is the short entity token ("Claritas"); employees store the full
            // registered name. Match the token as a prefix of the employee's company (same rule
            // as ClaimRulesService::categoriesFor) so entity-scoped categories — e.g. the Claritas
            // Optical & Dental benefit — actually compete in detection.
            $query->where(function ($q) use ($company) {
                $q->whereNull('company')
                    ->orWhereRaw("LOWER(TRIM(?)) LIKE LOWER(CONCAT(`company`, '%'))", [$company]);
            });
        }

        $best = null;
        $bestScore = 0.0;
        foreach ($query->get() as $category) {
            $score = $category->descriptionMatchScore($desc);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $category;
            }
        }

        return $bestScore >= 1.0 ? $best : null;
    }

    /**
     * Relevance score of this category for a normalised (lower-cased, space-padded)
     * description. Explicit keywords weigh most (phrases more than single words); the
     * category name's own significant words give a weaker fallback signal.
     */
    public function descriptionMatchScore(string $paddedLowerDesc): float
    {
        $stop = ['and', 'the', 'of', 'fees', 'fee', 'expense', 'expenses', 'local',
            'oversea', 'other', 'general', 'allowance', 'charges', 'amp'];
        $score = 0.0;

        foreach ((array) ($this->keywords ?? []) as $kw) {
            $kw = strtolower(trim((string) $kw));
            if ($kw === '') {
                continue;
            }
            if (str_contains($kw, ' ')) {
                // Multi-word keyword phrase — strongest, most specific signal.
                if (str_contains($paddedLowerDesc, ' '.$kw.' ') || str_contains($paddedLowerDesc, $kw)) {
                    $score += 3 + strlen($kw) * 0.1;
                }
            } elseif (preg_match('/\b'.preg_quote($kw, '/').'\b/', $paddedLowerDesc)) {
                $score += 2 + strlen($kw) * 0.1;
            }
        }

        // Fallback: significant words from the category name.
        foreach (preg_split('/[^a-z0-9]+/', strtolower((string) $this->name)) as $tok) {
            if (strlen($tok) < 4 || in_array($tok, $stop, true)) {
                continue;
            }
            if (preg_match('/\b'.preg_quote($tok, '/').'\b/', $paddedLowerDesc)) {
                $score += 1;
            }
        }

        return $score;
    }
}
