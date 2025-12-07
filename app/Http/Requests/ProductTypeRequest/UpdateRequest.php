<?php

namespace App\Http\Requests\ProductTypeRequest;


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

        $id = $this->route('product_type');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('product_types')
                    ->ignore($id)
                    ->whereNull('deleted_at')
            ],
            'is_active' => 'sometimes|boolean|required',
            'is_primary' => 'sometimes|boolean',
           
            

        ];
    }
}
