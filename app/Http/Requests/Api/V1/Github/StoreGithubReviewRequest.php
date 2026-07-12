<?php

namespace App\Http\Requests\Api\V1\Github;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGithubReviewRequest extends FormRequest
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
            'event' => ['required', 'string', Rule::in(['APPROVE', 'REQUEST_CHANGES', 'COMMENT'])],
            'body' => [
                Rule::requiredIf(fn () => in_array($this->input('event'), ['REQUEST_CHANGES', 'COMMENT'], true)),
                'nullable',
                'string',
                'max:65535',
            ],
        ];
    }
}
