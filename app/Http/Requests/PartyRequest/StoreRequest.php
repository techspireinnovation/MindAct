<?php

namespace App\Http\Requests\PartyRequest;


use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',

            ],
            'pan_number' => [
                'nullable',
                'numeric',
                
                Rule::unique('parties')
                    ->whereNull('deleted_at')

            ],
            'billing_address' => 'nullable|string',
            'opening_balance' => 'nullable|numeric',
            'district' => 'nullable|string|max:255',
            'ledger_type' => 'nullable|in:customer,vendor,both',
            'address' => 'nullable|string',
            'phone' => 'nullable|digits:10',
            'email' => [
                'nullable',
                'email',
                'string',
                'max:255',
                Rule::unique('parties')
                    ->whereNull('deleted_at')

            ],
            'contact_person' => 'nullable|string|max:255',
            'contact_person_phone' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'vdc_municipality' => 'nullable|string|max:255',
            'ward_no' => 'nullable|string|max:255',
            'area' => 'nullable|string|max:100',
            'bank_name' => 'nullable|string|max:255',
            'bank_id' => 'nullable|numeric',
            'bank_account_number' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',

        ];
    }
}
