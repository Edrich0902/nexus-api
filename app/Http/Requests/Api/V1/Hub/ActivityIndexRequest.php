<?php

namespace App\Http\Requests\Api\V1\Hub;

use App\Models\Activity\ActivityEvent;
use App\Services\Activity\ActivityFeedService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ActivityIndexRequest extends FormRequest
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
            'module' => ['nullable', 'string', Rule::in(ActivityEvent::MODULES)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.ActivityFeedService::MAX_LIMIT],
            'cursor' => ['nullable', 'string', 'max:512'],
        ];
    }
}
