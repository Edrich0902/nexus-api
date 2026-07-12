<?php

namespace App\Http\Requests\Api\V1\Spotify;

use Illuminate\Foundation\Http\FormRequest;

class PlaylistItemsRequest extends FormRequest
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
            'uris' => ['required', 'array', 'min:1'],
            'uris.*' => ['required', 'string'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
