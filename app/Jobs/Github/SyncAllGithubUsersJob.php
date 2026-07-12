<?php

namespace App\Jobs\Github;

use App\Integrations\Github\GithubIntegration;
use App\Models\Integration\IntegrationConnection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncAllGithubUsersJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        IntegrationConnection::query()
            ->where('provider', GithubIntegration::PROVIDER)
            ->where('status', IntegrationConnection::STATUS_ACTIVE)
            ->pluck('user_id')
            ->each(function (int $userId): void {
                try {
                    SyncGithubReposJob::dispatch($userId);
                } catch (\Throwable $e) {
                    Log::warning('Failed to dispatch GitHub repo sync', [
                        'user_id' => $userId,
                        'message' => $e->getMessage(),
                    ]);
                }
            });
    }
}
