<?php

namespace App\Http\Requests\Api\V1\Media;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttachMediaRequest extends FormRequest
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
        $attachTypes = array_keys(config('media.attachable', []));

        return [
            'type' => ['required', 'string', Rule::in($attachTypes)],
            'id' => ['required', 'integer', 'min:1'],
            'role' => ['nullable', 'string', 'max:64'],
        ];
    }
}
