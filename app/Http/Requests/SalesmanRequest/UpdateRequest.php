<?php

namespace App\Http\Requests\SalesmanRequest;


use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRequest extends FormRequest
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

        $id = $this->route('salesman');

        return [
            'salesman_code' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('salesmen')
                    ->ignore($id)
                    ->whereNull('deleted_at')

            ],
            'pan_number' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('salesmen')
                    ->ignore($id)
                    ->whereNull('deleted_at')

            ],
            'name' => 'sometimes|required|string|max:255',
            'is_active' => 'nullable|boolean',
            'is_primary' => 'nullable|boolean',
            'address' => 'nullable|string',
            'country' => 'nullable|string',
            'state' => 'nullable|string',
            'ward_no' => 'nullable|integer',
            'area' => 'nullable|string',

            'email' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('salesmen')
                    ->ignore($id)
                    ->whereNull('deleted_at')

            ],
            'working_office' => 'nullable|string|max:255',
            'joining_date' => 'nullable|date',
            'designation' => 'nullable|string|max:255',
            'dob' => 'nullable|date',
            'mobile' => [
                'required',
                'digits:10',

                Rule::unique('salesmen', 'mobile')->ignore($id)
            ],

            'citizenship_number' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('salesmen', 'citizenship_number')->ignore($id)
            ],



            'nationality' => 'nullable|string|max:100',
            'zone' => 'nullable|string|max:100',
            'district' => 'nullable|string|max:100',
            'vdc_municipality' => 'nullable|string|max:255',



        ];
    }
}
