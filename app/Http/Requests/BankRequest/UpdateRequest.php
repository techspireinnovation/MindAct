<?php

namespace App\Http\Requests\BankRequest;


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

        $id = $this->route('bank');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('banks')
                    ->ignore($id)
                    ->whereNull('deleted_at'),

            ],
            'is_active' => 'boolean|required',
            'is_primary' => 'boolean',
            'address' => 'nullable|string|max:255',
            'class' => 'nullable|string|max:255',
            'number' => 'nullable|string|max:255',
            'swift' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('banks')
                    ->ignore($id)
                    ->whereNull('deleted_at'),

            ],

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
