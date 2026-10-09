<?php

namespace App\Http\Requests\Api\V1\Hub;

use App\Services\Media\PaletteService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class PaletteRequest extends FormRequest
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
            'url' => [
                'required',
                'string',
                'max:1024',
                'url:https',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! app(PaletteService::class)->isAllowed(is_string($value) ? $value : null)) {
                        $fail('Images from this host are not supported.');
                    }
                },
            ],
        ];
    }
}
