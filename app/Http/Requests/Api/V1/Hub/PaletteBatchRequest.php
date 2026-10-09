<?php

namespace App\Http\Requests\Api\V1\Hub;

use Illuminate\Foundation\Http\FormRequest;

class PaletteBatchRequest extends FormRequest
{
    public const MAX_URLS = 60;

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
            'urls' => ['required', 'array', 'min:1', 'max:'.self::MAX_URLS],
            'urls.*' => ['required', 'string', 'max:1024'],
        ];
    }
}
