<?php

namespace App\Services\Media;

use App\Jobs\Media\MirrorRemoteImageJob;
use App\Models\Media\MediaAsset;
use App\Models\User;
use App\Models\WineCatalog\WineCatalogWine;
use Illuminate\Database\Eloquent\Model;

class MediaMirrorService
{
    public function __construct(
        private readonly MediaService $media,
        private readonly MediaAttachmentService $attachments,
    ) {}

    public function shouldMirror(string $provider): bool
    {
        return (bool) config("media.mirrors.{$provider}.enabled", false);
    }

    /**
     * Queue a mirror when the provider policy allows it.
     */
    public function queueMirror(
        string $provider,
        string $url,
        string $sourceRef,
        ?User $actingUser = null,
        ?array $attachTo = null,
        ?array $sourceMeta = null,
    ): void {
        if (! $this->shouldMirror($provider) || trim($url) === '') {
            return;
        }

        MirrorRemoteImageJob::dispatch(
            provider: $provider,
            url: $url,
            sourceRef: $sourceRef,
            actingUserId: $actingUser?->id,
            attachType: $attachTo['type'] ?? null,
            attachId: isset($attachTo['id']) ? (int) $attachTo['id'] : null,
            sourceMeta: $sourceMeta,
        );
    }

    /**
     * Synchronously mirror a remote image into Cloudinary with a deterministic public_id.
     *
     * @param  array<string, mixed>|null  $sourceMeta
     * @param  array{type: string, id: int}|null  $attachTo
     */
    public function mirrorNow(
        string $provider,
        string $url,
        string $sourceRef,
        ?User $actingUser = null,
        ?array $attachTo = null,
        ?array $sourceMeta = null,
    ): ?MediaAsset {
        if (! $this->shouldMirror($provider) || trim($url) === '') {
            return null;
        }

        $collection = (string) (config("media.mirrors.{$provider}.collection") ?? 'mirror');
        $folder = $this->media->mirrorFolder($provider);
        $safeRef = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $sourceRef) ?: 'unknown';
        $publicId = $folder.'/'.$safeRef;

        $importUser = $actingUser ?? User::query()->orderBy('id')->first();
        if ($importUser === null) {
            return null;
        }

        return $this->media->importFromUrl($importUser, [
            'url' => $url,
            'collection' => $collection,
            'source' => MediaAsset::SOURCE_MIRROR,
            'source_provider' => $provider,
            'source_ref' => $sourceRef,
            'source_meta' => $sourceMeta,
            'folder' => $folder,
            'public_id' => $publicId,
            'user' => null,
            'overwrite' => true,
            'attach_to' => $attachTo,
            'role' => 'cover',
        ]);
    }

    public function queueWineCatalogMirror(WineCatalogWine $wine): void
    {
        if (! is_string($wine->image_url) || $wine->image_url === '') {
            return;
        }

        $this->queueMirror(
            provider: 'wineapi',
            url: $wine->image_url,
            sourceRef: (string) $wine->wineapi_id,
            attachTo: [
                'type' => 'wine_catalog_wine',
                'id' => $wine->id,
            ],
        );
    }

    public function queueMealdbMirror(Model $recipeOrMeal, string $url, string $mealdbId, User $user): void
    {
        if (! $this->shouldMirror('mealdb')) {
            return;
        }

        // mealdb is on_save_only — callers must only invoke this when a recipe is saved.
        $this->queueMirror(
            provider: 'mealdb',
            url: $url,
            sourceRef: $mealdbId,
            actingUser: $user,
            attachTo: [
                'type' => 'kitchen_recipe',
                'id' => (int) $recipeOrMeal->getKey(),
            ],
        );
    }
}
