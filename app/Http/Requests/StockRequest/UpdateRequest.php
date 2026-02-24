<?php

namespace App\Http\Requests\StockRequest;


use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;

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
        $id = $this->route('stock');
        return [
            
            'company_id' => 'required|integer',
            'branch_id' => 'required|integer',

            'invoice_date' => 'nullable|date',
            'invoice_date_bs' => 'nullable|string|max:255',
            'type' => 'nullable|string|max:255',
            'bill_number' => [
                'nullable',
                'string',
                Rule::unique('stocks')
                    ->ignore($id)
                    ->whereNull('deleted_at')
            ],
            'address' => 'nullable|string',
            'stock_products' => 'required|array',
            'stock_products.*.id' => 'nullable|integer',

            'stock_products.*.product_id' => 'required|integer|exists:products,id',

            'stock_products.*.type' => 'nullable|string',
            'stock_products.*.stock_type' => 'nullable|string',

            'stock_products.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
           
            'stock_products.*.quantity' => 'required|numeric',
          
            // 'stock_products.*.is_vatable' => 'nullable|boolean',
            'stock_products.*.field_values' => 'nullable|array',
            'stock_products.*.field_values.*' => 'array',
            'stock_products.*.field_values.*.*.id' => 'nullable|integer',
            'stock_products.*.field_values.*.*.key' => 'required|string',
            'stock_products.*.field_values.*.*.value' => 'required|string|max:255',


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
