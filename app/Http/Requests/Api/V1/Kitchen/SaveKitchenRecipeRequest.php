<?php

namespace App\Http\Requests\Api\V1\Kitchen;

use Illuminate\Foundation\Http\FormRequest;

class SaveKitchenRecipeRequest extends FormRequest
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
            'mealdb_id' => ['required', 'string', 'max:32'],
            'rating' => ['nullable', 'numeric', 'min:0.5', 'max:5'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
