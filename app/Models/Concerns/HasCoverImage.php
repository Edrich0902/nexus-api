<?php

namespace App\Models\Concerns;

use App\Models\Media\MediaAsset;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait HasCoverImage
{
    public function coverMedia(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

    public function mediaAssets(): MorphToMany
    {
        return $this->morphToMany(MediaAsset::class, 'mediable')
            ->withPivot(['role', 'position'])
            ->withTimestamps()
            ->orderByPivot('position');
    }

    /**
     * Prefer mirrored/uploaded Cloudinary URL; fall back to an upstream hotlink column when present.
     */
    public function resolvedImageUrl(?string $upstreamAttribute = null): ?string
    {
        if (is_string($this->media_url) && $this->media_url !== '') {
            return $this->media_url;
        }

        if ($upstreamAttribute !== null) {
            $upstream = $this->getAttribute($upstreamAttribute);

            return is_string($upstream) && $upstream !== '' ? $upstream : null;
        }

        return null;
    }

    /**
     * Compact shape for API resources / NexusImage.
     *
     * @return array{id: int, public_id: string, url: string}|null
     */
    public function mediaImagePayload(): ?array
    {
        if ($this->media_asset_id === null || ! is_string($this->media_public_id) || $this->media_public_id === '') {
            return null;
        }

        return [
            'id' => (int) $this->media_asset_id,
            'public_id' => $this->media_public_id,
            'url' => (string) ($this->media_url ?? ''),
        ];
    }
}
