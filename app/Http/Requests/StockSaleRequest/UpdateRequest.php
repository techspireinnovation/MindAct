<?php

namespace App\Http\Requests\StockSaleRequest;


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
        $id = $this->route('stock-sales');

        return [


            'company_id' => 'required|integer',
            'branch_id' => 'required|integer',
            'invoice_date' => 'nullable|date_format:Y-m-d',
            'invoice_date_bs' => 'nullable|date_format:Y-m-d',
            'party_id' => 'nullable|integer',
            'location_id' => 'nullable|integer',
            'type' => 'nullable|string|max:255',
            'batch_no' => 'nullable|string|max:255',
            'credit_days' => 'nullable|string|max:255',
            'balance' => 'nullable|string|max:255',

            'bill_number' => 'nullable|string',
            'ref_bill_number' => 'nullable|string|max:255',
            'return_bill_number' => 'nullable|string|max:255',
            'reasons' => 'nullable|string|max:255',
            'discount_type' => 'nullable|string|max:255',
            'discount_value' => 'nullable',
            'discount_after_vat' => 'nullable',
            'sub_total_before_discount' => 'nullable',
            'taxable_amount' => 'nullable',
            'non_taxable_amount' => 'nullable',
            'excise_duty' => 'nullable',
            'vat_percent' => 'nullable',
            'health_insurance' => 'nullable',
            'freight_amount' => 'nullable',
            'roundoff_type' => 'nullable',
            'roundoff_amount' => 'nullable',
            'total_amount' => 'nullable',
            'payment' => 'nullable',
            'remarks' => 'nullable',
            'address' => 'nullable|string',

            'stock_transactions' => 'required|array',


            'stock_transactions.*.id' => 'nullable|integer',
            'stock_transactions.*.product_id' => 'required|integer|exists:products,id',
            'stock_transactions.*.stock_product_id' => 'nullable|integer',
            'stock_transactions.*.stock_movement_id' => 'nullable|integer',
            'stock_transactions.*.party_id' => 'nullable|numeric',
            'stock_transactions.*.expiry_date' => 'nullable|string',
            'stock_transactions.*.mfd' => 'nullable|string',
            'stock_transactions.*.type' => 'nullable|string',
            'stock_transactions.*.price' => 'nullable|numeric',
            'stock_transactions.*.discount_percent' => 'nullable|numeric',
            'stock_transactions.*.discount_amount' => 'nullable|numeric',
            'stock_transactions.*.amount' => 'nullable|numeric',
            'stock_transactions.*.batch_no' => 'nullable|string',


            'stock_transactions.*.stock_type' => 'nullable|string',

            'stock_transactions.*.measure_unit_id' => 'required|integer|exists:measure_units,id',

            'stock_transactions.*.quantity' => 'nullable|numeric',
            'stock_transactions.*.free_quantity' => 'nullable|numeric',

            'stock_transactions.*.is_vatable' => 'required|boolean',
            'stock_transactions.*.field_values' => 'nullable|array',
            'stock_transactions.*.field_values.*' => 'array',
            'stock_transactions.*.field_values.*.*.id' => 'nullable|integer',
            'stock_transactions.*.field_values.*.*.stock_product_id' => 'nullable|integer',
            'stock_transactions.*.field_values.*.*.quantity_type' => 'nullable|string',
            'stock_transactions.*.field_values.*.*.quantity_index' => 'nullable|numeric',




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
