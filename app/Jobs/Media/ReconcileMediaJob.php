<?php

namespace App\Jobs\Media;

use App\Integrations\Cloudinary\CloudinaryClient;
use App\Models\Media\MediaAsset;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class ReconcileMediaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly int $userId,
    ) {
        $this->onQueue((string) config('media.queue', 'default'));
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('media-reconcile:'.$this->userId))
                ->releaseAfter(30)
                ->expireAfter(180),
        ];
    }

    public function handle(CloudinaryClient $cloudinary): void
    {
        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }

        $prefix = trim((string) config('media.root_folder', 'nexus'), '/').'/'
            .trim((string) config('media.env_segment', 'local'), '/').'/users/'.$user->id;

        $nextCursor = null;
        $seen = [];

        do {
            $options = [
                'type' => 'upload',
                'resource_type' => 'image',
                'prefix' => $prefix,
                'max_results' => 100,
            ];
            if (is_string($nextCursor) && $nextCursor !== '') {
                $options['next_cursor'] = $nextCursor;
            }

            $page = $cloudinary->listResources($options);
            $resources = is_array($page['resources'] ?? null) ? $page['resources'] : [];

            foreach ($resources as $resource) {
                if (! is_array($resource)) {
                    continue;
                }
                $publicId = (string) ($resource['public_id'] ?? '');
                if ($publicId === '') {
                    continue;
                }
                $seen[$publicId] = true;

                if (! MediaAsset::query()->where('public_id', $publicId)->exists()) {
                    Log::info('[media] Orphan Cloudinary asset (not in DB)', [
                        'public_id' => $publicId,
                        'user_id' => $user->id,
                    ]);
                }
            }

            $nextCursor = is_string($page['next_cursor'] ?? null) ? $page['next_cursor'] : null;
        } while ($nextCursor !== null);

        MediaAsset::query()
            ->where('user_id', $user->id)
            ->whereNotIn('public_id', array_keys($seen) ?: ['__none__'])
            ->orderBy('id')
            ->each(function (MediaAsset $asset): void {
                Log::info('[media] DB asset missing from Cloudinary', [
                    'media_asset_id' => $asset->id,
                    'public_id' => $asset->public_id,
                ]);
            });
    }
}
