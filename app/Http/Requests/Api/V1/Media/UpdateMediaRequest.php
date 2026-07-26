<?php

namespace App\Http\Requests\Api\V1\Media;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMediaRequest extends FormRequest
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
        $collections = array_keys(config('media.collections', []));

        return [
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tags' => ['sometimes', 'nullable', 'array'],
            'tags.*' => ['string', 'max:64'],
            'collection' => ['sometimes', 'string', Rule::in($collections)],
        ];
    }
}
