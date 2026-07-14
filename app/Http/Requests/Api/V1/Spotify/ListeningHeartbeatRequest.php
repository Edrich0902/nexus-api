<?php

namespace App\Http\Requests\Api\V1\Spotify;

use Illuminate\Foundation\Http\FormRequest;

class ListeningHeartbeatRequest extends FormRequest
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
            'spotify_id' => ['required', 'string', 'max:64'],
            'progress_ms' => ['required', 'integer', 'min:0'],
            'duration_ms' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_playing' => ['sometimes', 'boolean'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'uri' => ['sometimes', 'nullable', 'string', 'max:255'],
            'album_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'album_image_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'artists' => ['sometimes', 'nullable', 'array', 'max:20'],
            'artists.*.id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'artists.*.name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
