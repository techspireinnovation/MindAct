<?php

namespace App\Http\Requests\ProductRequest;


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

        $id = $this->route('product');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('products')
                    ->ignore($id)
                    ->whereNull('deleted_at')


            ],
            'product_code' => 'nullable|string',
            'sku' => 'required|string',
            'note' => 'nullable|string',
            'category_id' => 'nullable|numeric',
            'brand_id' => 'nullable|numeric',
            'measure_unit_id' => 'nullable|numeric',
            'is_vatable' => 'nullable',
            'product_type_id' => 'nullable|numeric',
            'is_active' => 'boolean|required',




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
