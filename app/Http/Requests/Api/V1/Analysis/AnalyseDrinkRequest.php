<?php

namespace App\Http\Requests\Api\V1\Analysis;

use Illuminate\Foundation\Http\FormRequest;

class AnalyseDrinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'force' => ['sometimes', 'boolean'],
            'extra_context' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
