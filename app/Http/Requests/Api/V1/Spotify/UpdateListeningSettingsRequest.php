<?php

namespace App\Http\Requests\Api\V1\Spotify;

use Illuminate\Foundation\Http\FormRequest;

class UpdateListeningSettingsRequest extends FormRequest
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
            'engage_progress_ms' => ['sometimes', 'integer', 'min:5000', 'max:120000'],
            'engage_ratio' => ['sometimes', 'numeric', 'min:0.05', 'max:1'],
            'full_listen_ratio' => ['sometimes', 'numeric', 'min:0.1', 'max:1'],
            'auto_queue_enabled' => ['sometimes', 'boolean'],
            'auto_queue_min_upcoming' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'auto_queue_batch' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ];
    }
}
