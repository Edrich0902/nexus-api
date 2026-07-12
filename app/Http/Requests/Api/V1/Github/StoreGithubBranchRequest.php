<?php

namespace App\Http\Requests\Api\V1\Github;

use Illuminate\Foundation\Http\FormRequest;

class StoreGithubBranchRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'from' => ['nullable', 'string', 'max:255'],
        ];
    }
}
