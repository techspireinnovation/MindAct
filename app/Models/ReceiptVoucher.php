<?php

namespace App\Models;

use App\Models\ReceiptVoucherDetail;
use App\Models\Scopes\CompanyIdScope;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class ReceiptVoucher extends Model
{
    use SoftDeletes, HasFactory;

    protected $casts = [
        
        'is_active' => 'boolean'
    ];
    protected $fillable = [
        'company_id',
        'date_ad',
        'date_bs',
        'receipt_voucher_number',
        'reference_number',
        
    ];

    protected $dates = ['deleted_at', 'date_ad', 'date_bs'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }

    public function receiptVoucherDetails(): HasMany
    {
        return $this->hasMany(ReceiptVoucherDetail::class);
    }
    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
