<?php

namespace App\Http\Requests\Api\V1\Media;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMediaUploadRequest extends FormRequest
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
        $attachTypes = array_keys(config('media.attachable', []));
        $maxKb = (int) ceil(((int) config('media.default_max_bytes', 10 * 1024 * 1024)) / 1024);

        return [
            'file' => ['required', 'file', 'max:'.$maxKb, 'mimetypes:'.implode(',', config('media.allowed_mimes', []))],
            'collection' => ['required', 'string', Rule::in($collections)],
            'alt_text' => ['nullable', 'string', 'max:255'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:64'],
            'attach_to' => ['nullable', 'array'],
            'attach_to.type' => ['required_with:attach_to', 'string', Rule::in($attachTypes)],
            'attach_to.id' => ['required_with:attach_to', 'integer', 'min:1'],
            'role' => ['nullable', 'string', 'max:64'],
        ];
    }
}
