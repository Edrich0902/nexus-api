<?php

namespace App\Http\Requests\Api\V1\Hub;

use Illuminate\Foundation\Http\FormRequest;

class NowRequest extends FormRequest
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
            'tz' => ['nullable', 'string', 'max:64', 'timezone:all'],
        ];
    }

    public function timezone(): string
    {
        return (string) ($this->validated('tz') ?? config('app.timezone', 'UTC'));
    }
}
