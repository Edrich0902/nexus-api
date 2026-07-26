<?php

namespace App\Http\Requests\Api\V1\Beer;

use Illuminate\Foundation\Http\FormRequest;

class StoreBeerRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'beer_brewery_id' => ['nullable', 'integer', 'exists:beer_breweries,id'],
            'beer_style_id' => ['nullable', 'integer', 'exists:beer_styles,id'],
            'abv' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'ibu' => ['nullable', 'integer', 'min:0', 'max:200'],
            'format' => ['nullable', 'string', 'max:64'],
            'rating' => ['nullable', 'numeric', 'min:0.5', 'max:5'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
