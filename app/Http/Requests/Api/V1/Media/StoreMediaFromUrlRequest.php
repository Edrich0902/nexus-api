<?php

namespace App\Http\Requests\Api\V1\Media;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMediaFromUrlRequest extends FormRequest
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

        return [
            'url' => ['required', 'url', 'max:2048'],
            'collection' => ['required', 'string', Rule::in($collections)],
            'source' => ['nullable', 'string', Rule::in(['upload', 'unsplash', 'mirror'])],
            'source_provider' => ['nullable', 'string', 'max:64'],
            'source_ref' => ['nullable', 'string', 'max:255'],
            'source_meta' => ['nullable', 'array'],
            'alt_text' => ['nullable', 'string', 'max:255'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:64'],
            'attach_to' => ['nullable', 'array'],
            'attach_to.type' => ['required_with:attach_to', 'string', Rule::in($attachTypes)],
            'attach_to.id' => ['required_with:attach_to', 'integer', 'min:1'],
            'role' => ['nullable', 'string', 'max:64'],
            'unsplash_download_location' => ['nullable', 'url', 'max:2048'],
        ];
    }
}
