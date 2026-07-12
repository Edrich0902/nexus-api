<?php

namespace App\Http\Requests\Api\V1\Github;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MergeGithubPullRequest extends FormRequest
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
            'merge_method' => ['sometimes', 'string', Rule::in(['merge', 'squash', 'rebase'])],
            'commit_title' => ['nullable', 'string', 'max:256'],
            'commit_message' => ['nullable', 'string', 'max:65535'],
        ];
    }
}
