<?php

namespace App\Http\Requests\Api\V1\Spirits;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSpiritRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'producer' => ['sometimes', 'nullable', 'string', 'max:255'],
            'category' => ['sometimes', 'nullable', 'string', 'max:64'],
            'age_statement' => ['sometimes', 'nullable', 'string', 'max:64'],
            'abv' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'region' => ['sometimes', 'nullable', 'string', 'max:128'],
            'country' => ['sometimes', 'nullable', 'string', 'max:128'],
            'rating' => ['sometimes', 'nullable', 'numeric', 'min:0.5', 'max:5'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
