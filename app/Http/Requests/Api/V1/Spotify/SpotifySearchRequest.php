<?php

namespace App\Http\Requests\Api\V1\Spotify;

use Illuminate\Foundation\Http\FormRequest;

class SpotifySearchRequest extends FormRequest
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
            'q' => ['required', 'string', 'min:1', 'max:200'],
            'type' => ['sometimes', 'string', 'max:80'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ];
    }
}
