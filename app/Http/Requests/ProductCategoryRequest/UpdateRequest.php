<?php

namespace App\Http\Requests\ProductCategoryRequest;


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

        $id = $this->route('product_category');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('product_categories')
                    ->ignore($id)
                    ->whereNull('deleted_at')

            ],

            'is_primary' => 'boolean',
            'is_active' => 'boolean'

        ];
    }
}
