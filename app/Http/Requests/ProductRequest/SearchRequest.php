<?php

namespace App\Http\Requests\ProductRequest;


use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;

class SearchRequest extends FormRequest
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
            'filter_by' => 'nullable|string',
            'search_name' => 'nullable|string|max:100',
            'search_category' => 'nullable|string|max:100',
            'search_product_code' => 'nullable|string|max:100',
            'search_barcode' => 'nullable|string|max:100',
            'search_brand' => 'nullable|string|max:100',
            'search_measure_unit' => 'nullable|string|max:100',
            'search_product_type' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:1|max:100',

        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'error' => 'Validation failed',
            'messages' => $validator->errors(),
            'status' => 422,
        ], 422));
    }
}
