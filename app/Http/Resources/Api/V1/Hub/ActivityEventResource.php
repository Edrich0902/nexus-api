<?php

namespace App\Http\Resources\Api\V1\Hub;

use App\Models\Activity\ActivityEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ActivityEvent
 */
class ActivityEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'module' => $this->module,
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'meta' => $this->meta ?? (object) [],
            'subject' => $this->subject_type !== null
                ? ['type' => $this->subject_type, 'id' => $this->subject_id]
                : null,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
