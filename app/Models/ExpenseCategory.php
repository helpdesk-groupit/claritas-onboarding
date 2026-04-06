<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseCategory extends Model
{
    protected $fillable = [
        'company', 'name', 'code', 'description',
        'monthly_limit', 'requires_receipt', 'is_active',
        'sort_order', 'keywords',
    ];

    protected $casts = [
        'monthly_limit' => 'decimal:2',
        'requires_receipt' => 'boolean',
        'is_active' => 'boolean',
        'keywords' => 'array',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    /**
     * Auto-detect the best matching category based on description keywords.
     */
    public static function detectFromDescription(string $description, ?string $company = null): ?self
    {
        $desc = strtolower($description);
        $query = static::where('is_active', true);
        if ($company) {
            $query->where(function ($q) use ($company) {
                $q->where('company', $company)->orWhereNull('company');
            });
        }

        $categories = $query->whereNotNull('keywords')->get();

        foreach ($categories as $category) {
            $keywords = $category->keywords ?? [];
            foreach ($keywords as $keyword) {
                if (str_contains($desc, strtolower($keyword))) {
                    return $category;
                }
            }
        }

        return null;
    }
}
