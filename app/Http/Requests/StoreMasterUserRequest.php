<?php
// app/Http/Requests/StoreMasterUserRequest.php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMasterUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('super_admin');
    }

    public function rules(): array
    {
        return [
            'name'                   => 'required|string|max:255',
            'email'                  => 'required|email|unique:users,email',
            'password'               => 'required|string|min:6|confirmed',
            'company_admin_ids'      => 'required|array',
            'company_admin_ids.*'    => 'exists:users,id',
        ];
    }
}