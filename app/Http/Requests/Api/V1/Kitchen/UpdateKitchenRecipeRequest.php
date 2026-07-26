<?php

namespace App\Http\Requests\Api\V1\Kitchen;

use Illuminate\Foundation\Http\FormRequest;

class UpdateKitchenRecipeRequest extends FormRequest
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
            'rating' => ['sometimes', 'nullable', 'numeric', 'min:0.5', 'max:5'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'is_favourite' => ['sometimes', 'boolean'],
        ];
    }
}
