<?php

namespace App\Http\Requests\Api\V1\Github;

use Illuminate\Foundation\Http\FormRequest;

class StoreGithubPullRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:256'],
            'head' => ['required', 'string', 'max:255'],
            'base' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:65535'],
            'draft' => ['sometimes', 'boolean'],
        ];
    }
}
