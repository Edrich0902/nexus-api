<?php

namespace App\Http\Requests\Api\V1\Cellar;

use Illuminate\Foundation\Http\FormRequest;

class StoreCellarWineRequest extends FormRequest
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
            'producer_name' => ['nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'vintage' => ['nullable', 'integer', 'min:1800', 'max:2100'],
            'wine_type' => ['nullable', 'string', 'max:64'],
            'region_name' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:128'],
            'rating' => ['nullable', 'numeric', 'min:0.5', 'max:5'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
