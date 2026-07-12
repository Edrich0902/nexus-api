<?php

namespace App\Jobs\Github;

use App\Models\User;
use App\Services\Github\GithubSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncGithubReposJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $userId,
    ) {}

    public function handle(GithubSyncService $sync): void
    {
        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }

        try {
            $sync->syncRepos($user);
        } catch (\Throwable $e) {
            Log::warning('GitHub repos sync failed', [
                'user_id' => $this->userId,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
