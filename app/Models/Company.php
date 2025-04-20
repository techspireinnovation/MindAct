<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'name',
        'licence_issue_date',
        'working_date',
        'reg_number',
        'pan_number',
        'full_address',
        'email_address',
        'website',
        'fax',
        'logo',
        'province',
        'district',
        'palika_name',
        'ward_number',
        'contact_person',
        'contact_person_position',
        'agreement_holder_name',
        'phone',
        'position',
        'license_number',
        'activation_key',
        'url_link',
    ];

}
