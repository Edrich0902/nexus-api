<?php

namespace App\Http\Requests\Api\V1\Cellar;

use Illuminate\Foundation\Http\FormRequest;

class StoreCellarWineTastingRequest extends FormRequest
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
            'tasted_on' => ['required', 'date'],
            'rating' => ['nullable', 'numeric', 'min:0.5', 'max:5'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'occasion' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
        ];
    }
}
