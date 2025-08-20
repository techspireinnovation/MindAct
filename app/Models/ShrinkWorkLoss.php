<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ShrinkWorkLoss extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'branch_id',
        'shrinking_loss_percent',
        'working_loss_percent',
        'internal_loss_percent',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }
}