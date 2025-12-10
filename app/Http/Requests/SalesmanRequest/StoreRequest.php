<?php

namespace App\Http\Requests\SalesmanRequest;


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
            'salesman_code' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('salesmen')
                    ->whereNull('deleted_at')

            ],
            'pan_number' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('salesmen')
                    ->whereNull('deleted_at')

            ],
            'name' => 'required|string|max:255',
            'address' => 'nullable|string',
            'country' => 'nullable|string',
            'state' => 'nullable|string',
            'ward_no' => 'nullable|integer',
            'is_active' => 'boolean',
            'is_primary' => 'boolean',
            'area' => 'nullable|string',
            'mobile' => 'required|digits:10|unique:salesmen,mobile',

            'email' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('salesmen')
                    ->whereNull('deleted_at')

            ],
            'working_office' => 'nullable|string|max:255',
            'joining_date' => 'nullable|date',
            'designation' => 'nullable|string|max:255',
            'dob' => 'nullable|date',
            'citizenship_number' => 'nullable|string|max:255|unique:salesmen,citizenship_number',

            'nationality' => 'nullable|string|max:100',
            'zone' => 'nullable|string|max:100',
            'district' => 'nullable|string|max:100',
            'vdc_municipality' => 'nullable|string|max:255',

        ];
    }
}
