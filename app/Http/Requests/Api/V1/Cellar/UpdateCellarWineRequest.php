<?php

namespace App\Http\Requests\Api\V1\Cellar;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCellarWineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'producer_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'vintage' => ['sometimes', 'nullable', 'integer', 'min:1800', 'max:2100'],
            'wine_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'region_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'country' => ['sometimes', 'nullable', 'string', 'max:128'],
            'rating' => ['sometimes', 'nullable', 'numeric', 'min:0.5', 'max:5'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ];
    }
}
