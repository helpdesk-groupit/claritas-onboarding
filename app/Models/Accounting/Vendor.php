<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    protected $table = 'acc_vendors';

    protected $fillable = [
        'company', 'vendor_code', 'name', 'email', 'phone',
        'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country',
        'tax_id', 'payment_terms_days', 'currency',
        'bank_name', 'bank_account_number', 'bank_swift', 'notes', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function bills()    { return $this->hasMany(Bill::class, 'vendor_id')->latest('date'); }
    public function payments() { return $this->hasMany(VendorPayment::class, 'vendor_id'); }
    public function purchaseOrders() { return $this->hasMany(PurchaseOrder::class, 'vendor_id'); }
    public function fixedAssets()    { return $this->hasMany(FixedAsset::class, 'vendor_id'); }

    public function scopeActive($query) { return $query->where('is_active', true); }

    public function getOutstandingBalanceAttribute(): float
    {
        return (float) $this->bills()->whereNotIn('status', ['paid', 'void'])->sum('balance_due');
    }
}
