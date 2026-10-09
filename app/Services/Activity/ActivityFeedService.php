<?php

namespace App\Services\Activity;

use App\Models\Activity\ActivityEvent;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;

class ActivityFeedService
{
    public const MAX_LIMIT = 30;

    /**
     * Newest first, cursor-paginated on (occurred_at, id).
     *
     * @return CursorPaginator<int, ActivityEvent>
     */
    public function feed(User $user, ?string $module = null, int $limit = 20): CursorPaginator
    {
        return ActivityEvent::query()
            ->where('user_id', $user->id)
            ->when($module !== null, fn ($q) => $q->where('module', $module))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->cursorPaginate(
                max(1, min(self::MAX_LIMIT, $limit)),
                ['id', 'module', 'type', 'subject_type', 'subject_id', 'title', 'body', 'meta', 'occurred_at'],
            );
    }
}
