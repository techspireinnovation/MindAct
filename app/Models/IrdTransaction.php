<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class IrdTransaction extends Model
{
    use SoftDeletes, HasFactory;

    protected $casts = [
        'is_active' => 'boolean',
        'is_primary' => 'boolean',
    ];

    protected $fillable = [
        'fiscal_yaer',
        'company_id',
        'bill_no',
        'customer_name',
        'customer_pan',
        'amount',
        'discount',
        'taxable_amount',
        'tax_amount',
        'total_amount',
        'sync_with_ird',
        'is_bill_printed',
        'is_bill_active',
        'printed_time',
        'entered_by',
        'printed_by',
        'is_realtime',
        'payment_method',
        'transaction_id',
        'vat_refund_amount',
    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }
}
