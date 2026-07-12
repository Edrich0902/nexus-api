<?php

namespace App\Jobs\Spotify;

use App\Models\User;
use App\Services\Spotify\SpotifySyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncTopItemsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $userId,
    ) {}

    public function handle(SpotifySyncService $sync): void
    {
        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }

        try {
            $sync->syncTopItems($user);
        } catch (\Throwable $e) {
            Log::warning('Spotify top items sync failed', [
                'user_id' => $this->userId,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
