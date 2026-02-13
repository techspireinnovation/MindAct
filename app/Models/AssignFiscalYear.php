<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssignFiscalYear extends Model
{
    use SoftDeletes, HasFactory;

    protected $fillable = [
        'title',
        'fiscal_year_id',
        'company_id',
        'is_active',
    ];


    public function fiscalYear()
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
