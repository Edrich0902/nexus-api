<?php

namespace App\Http\Requests\Api\V1\Spotify;

use Illuminate\Foundation\Http\FormRequest;

class PlaylistRemoveItemsRequest extends FormRequest
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
            'items' => ['required', 'array', 'min:1'],
            'items.*.uri' => ['required', 'string'],
            'items.*.positions' => ['sometimes', 'array'],
            'items.*.positions.*' => ['integer', 'min:0'],
        ];
    }
}
