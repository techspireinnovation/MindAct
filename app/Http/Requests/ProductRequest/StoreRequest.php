<?php

namespace App\Http\Requests\ProductRequest;


use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;

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
                Rule::unique('products')
                    ->whereNull('deleted_at')


            ],
            'product_code' => 'nullable|string',

            'company_id' => 'required|integer',
            'note' => 'nullable|string',
            'product_field_number' => 'nullable|numeric',
            'base_unit_id' => 'nullable|numeric|exists:measure_units,id',
            'category_id' => 'nullable|numeric',
            'sub_category_id' => 'nullable|numeric',
            'location_id' => 'nullable|integer',
            'brand_id' => 'nullable|numeric',
            'purchase_rate' => 'nullable|numeric',
            'purchase_rate_vat' => 'nullable|numeric',
            'minimum_stock' => 'nullable|numeric',
            'wholesale_price' => 'nullable|numeric',
            'retail_price' => 'nullable|numeric',
            'wholesale_price_vat' => 'nullable|numeric',
            'retail_price_vat' => 'nullable|numeric',
            'wholesale_profit_percent' => 'nullable|numeric',
            'retail_profit_percent' => 'nullable|numeric',
            'mrp_price' => 'nullable|numeric',
            'measure_unit_id' => 'nullable|numeric',
            'is_vatable' => 'nullable',
            'product_type_id' => 'nullable|numeric',
            'is_active' => 'boolean|required',
            'product_lists' => 'nullable||array',


            'product_lists.*.measure_unit_id' => 'nullable||integer|exists:measure_units,id',


            'product_lists.*.is_primary' => 'boolean|nullable|',
            'product_lists.*.barcode' => 'nullable|string',
            'product_lists.*.hs_code' => 'nullable|string',
            'product_lists.*.price' => 'nullable|numeric',
            'product_lists.*.discount' => 'nullable|numeric',
            'product_lists.*.final_price' => 'nullable|numeric',
            'product_lists.*.primary_measure_unit_id' => 'nullable||integer|exists:measure_units,id',

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
