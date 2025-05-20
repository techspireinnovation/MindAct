<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class JournalVoucher extends Model
{
    use SoftDeletes, HasFactory;

    protected $fillable = [
        'name',
        'company_id',
        'project_id',
        'salesman_id',
        'date',
        'voucher_number',
        'reference_number',
        'deleted_at'
    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }
}
