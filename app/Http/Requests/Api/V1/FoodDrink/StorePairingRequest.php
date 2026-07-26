<?php

namespace App\Http\Requests\Api\V1\FoodDrink;

use Illuminate\Foundation\Http\FormRequest;

class StorePairingRequest extends FormRequest
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
            'drinkable_type' => ['required', 'string', 'in:wine,beer'],
            'drinkable_id' => ['required', 'integer', 'min:1'],
            'kitchen_recipe_id' => ['required', 'integer', 'exists:kitchen_recipes,id'],
            'verdict' => ['nullable', 'string', 'in:great,good,poor'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
