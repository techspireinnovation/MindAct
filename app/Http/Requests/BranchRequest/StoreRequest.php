<?php

namespace App\Http\Requests\BranchRequest;


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
                Rule::unique('branches')
                    ->whereNull('deleted_at')


            ],
            'is_active' => 'boolean|required',
            'branch_type' => 'nullable|string',
            'is_primary' => 'boolean',
           

        ];
    }
}
