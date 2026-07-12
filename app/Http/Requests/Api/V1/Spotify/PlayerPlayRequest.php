<?php

namespace App\Http\Requests\Api\V1\Spotify;

use Illuminate\Foundation\Http\FormRequest;

class PlayerPlayRequest extends FormRequest
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
            'device_id' => ['sometimes', 'string'],
            'context_uri' => ['sometimes', 'string'],
            'uris' => ['sometimes', 'array', 'min:1'],
            'uris.*' => ['string'],
            'offset' => ['sometimes', 'array'],
            'position_ms' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
