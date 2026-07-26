<?php

namespace App\Http\Requests\Api\V1\Beer;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBeerRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'beer_brewery_id' => ['sometimes', 'nullable', 'integer', 'exists:beer_breweries,id'],
            'beer_style_id' => ['sometimes', 'nullable', 'integer', 'exists:beer_styles,id'],
            'abv' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'ibu' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:200'],
            'format' => ['sometimes', 'nullable', 'string', 'max:64'],
            'rating' => ['sometimes', 'nullable', 'numeric', 'min:0.5', 'max:5'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ];
    }
}
