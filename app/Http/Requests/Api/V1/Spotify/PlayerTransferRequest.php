<?php

namespace App\Http\Requests\Api\V1\Spotify;

use Illuminate\Foundation\Http\FormRequest;

class PlayerTransferRequest extends FormRequest
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
            'device_ids' => ['required', 'array', 'min:1'],
            'device_ids.*' => ['required', 'string'],
            'play' => ['sometimes', 'boolean'],
        ];
    }
}
