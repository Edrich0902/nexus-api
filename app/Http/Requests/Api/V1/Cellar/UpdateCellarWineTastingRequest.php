<?php

namespace App\Http\Requests\Api\V1\Cellar;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCellarWineTastingRequest extends FormRequest
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
            'tasted_on' => ['sometimes', 'required', 'date'],
            'rating' => ['sometimes', 'nullable', 'numeric', 'min:0.5', 'max:5'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'occasion' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
