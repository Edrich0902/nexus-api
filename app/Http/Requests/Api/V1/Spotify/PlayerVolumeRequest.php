<?php

namespace App\Http\Requests\Api\V1\Spotify;

use Illuminate\Foundation\Http\FormRequest;

class PlayerVolumeRequest extends FormRequest
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
            'volume_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'device_id' => ['sometimes', 'string'],
        ];
    }
}
