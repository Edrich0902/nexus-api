<?php

namespace App\Services\Media;

use App\Integrations\Cloudinary\CloudinaryClient;
use App\Models\Media\MediaAsset;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MediaService
{
    public function __construct(
        private readonly CloudinaryClient $cloudinary,
        private readonly MediaAttachmentService $attachments,
    ) {}

    /**
     * @param  array{
     *     collection: string,
     *     alt_text?: string|null,
     *     tags?: list<string>|null,
     *     attach_to?: array{type: string, id: int}|null,
     *     role?: string|null,
     * }  $options
     */
    public function upload(User $user, UploadedFile $file, array $options): MediaAsset
    {
        $collection = $this->assertCollection($options['collection']);
        $this->assertFile($file, $collection);

        $ulid = (string) Str::ulid();
        $folder = $this->userFolder($user, $collection);
        $publicId = $folder.'/'.$ulid;

        $response = $this->cloudinary->upload($file, [
            'public_id' => $publicId,
            'folder' => $folder,
            'asset_folder' => $folder,
            'overwrite' => false,
            'resource_type' => 'image',
            'transformation' => $this->incomingTransformation($collection),
        ]);

        $asset = $this->persistFromCloudinaryResponse(
            user: $user,
            response: $response,
            collection: $collection,
            source: MediaAsset::SOURCE_UPLOAD,
            folder: $folder,
            altText: $options['alt_text'] ?? null,
            tags: $options['tags'] ?? null,
        );

        if (! empty($options['attach_to'])) {
            $this->attachments->attach(
                $user,
                $asset,
                (string) $options['attach_to']['type'],
                (int) $options['attach_to']['id'],
                (string) ($options['role'] ?? 'cover'),
            );
            $asset->refresh();
        }

        return $asset;
    }

    /**
     * @param  array{
     *     collection: string,
     *     url: string,
     *     source?: string,
     *     source_provider?: string|null,
     *     source_ref?: string|null,
     *     source_meta?: array<string, mixed>|null,
     *     public_id?: string|null,
     *     folder?: string|null,
     *     user?: User|null,
     *     alt_text?: string|null,
     *     tags?: list<string>|null,
     *     attach_to?: array{type: string, id: int}|null,
     *     role?: string|null,
     *     overwrite?: bool,
     * }  $options
     */
    public function importFromUrl(User $actingUser, array $options): MediaAsset
    {
        $collection = $this->assertCollection($options['collection']);
        $url = trim((string) $options['url']);
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw ValidationException::withMessages(['url' => 'A valid image URL is required.']);
        }

        $owner = array_key_exists('user', $options)
            ? $options['user']
            : $actingUser;
        $folder = $options['folder'] ?? (
            $owner instanceof User
                ? $this->userFolder($owner, $collection)
                : $this->mirrorFolder((string) ($options['source_provider'] ?? 'unknown'))
        );
        $publicId = $options['public_id'] ?? (
            ($owner instanceof User ? $this->userFolder($owner, $collection) : $folder).'/'.(string) Str::ulid()
        );

        // Idempotent mirror / re-import by public_id.
        $existing = MediaAsset::query()->where('public_id', $publicId)->first();
        if ($existing !== null && ! ($options['overwrite'] ?? false)) {
            if (! empty($options['attach_to'])) {
                $this->attachments->attach(
                    $actingUser,
                    $existing,
                    (string) $options['attach_to']['type'],
                    (int) $options['attach_to']['id'],
                    (string) ($options['role'] ?? 'cover'),
                );
            }

            return $existing;
        }

        $response = $this->cloudinary->uploadFromUrl($url, [
            'public_id' => $publicId,
            'folder' => $folder,
            'asset_folder' => $folder,
            'overwrite' => (bool) ($options['overwrite'] ?? false),
            'resource_type' => 'image',
            'transformation' => $this->incomingTransformation($collection),
        ]);

        $asset = $this->persistFromCloudinaryResponse(
            user: $owner instanceof User ? $owner : null,
            response: $response,
            collection: $collection,
            source: (string) ($options['source'] ?? MediaAsset::SOURCE_UPLOAD),
            folder: $folder,
            altText: $options['alt_text'] ?? null,
            tags: $options['tags'] ?? null,
            sourceProvider: $options['source_provider'] ?? null,
            sourceRef: $options['source_ref'] ?? null,
            sourceMeta: $options['source_meta'] ?? null,
        );

        if (! empty($options['attach_to'])) {
            $this->attachments->attach(
                $actingUser,
                $asset,
                (string) $options['attach_to']['type'],
                (int) $options['attach_to']['id'],
                (string) ($options['role'] ?? 'cover'),
            );
            $asset->refresh();
        }

        return $asset;
    }

    /**
     * @param  array{
     *     collection?: string|null,
     *     source?: string|null,
     *     attached?: string|null,
     *     tag?: string|null,
     *     q?: string|null,
     *     per_page?: int,
     * }  $filters
     * @return LengthAwarePaginator<int, MediaAsset>
     */
    public function listForUser(User $user, array $filters = []): LengthAwarePaginator
    {
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 24)));

        $query = MediaAsset::query()
            ->where(function (Builder $q) use ($user): void {
                $q->where('user_id', $user->id)
                    ->orWhereNull('user_id');
            })
            ->withCount('attachments')
            ->orderByDesc('created_at');

        if (! empty($filters['collection'])) {
            $query->where('collection', (string) $filters['collection']);
        }

        if (! empty($filters['source'])) {
            $query->where('source', (string) $filters['source']);
        }

        if (($filters['attached'] ?? null) === 'orphan') {
            $query->doesntHave('attachments');
        } elseif (($filters['attached'] ?? null) === 'attached') {
            $query->has('attachments');
        }

        if (! empty($filters['tag'])) {
            $tag = (string) $filters['tag'];
            $query->whereJsonContains('tags', $tag);
        }

        if (! empty($filters['q'])) {
            $term = '%'.trim((string) $filters['q']).'%';
            $query->where(function (Builder $q) use ($term): void {
                $q->where('public_id', 'like', $term)
                    ->orWhere('alt_text', 'like', $term)
                    ->orWhere('folder', 'like', $term);
            });
        }

        return $query->paginate($perPage);
    }

    public function findAccessible(User $user, int $mediaId): MediaAsset
    {
        $asset = MediaAsset::query()
            ->with(['attachments'])
            ->withCount('attachments')
            ->where(function (Builder $q) use ($user): void {
                $q->where('user_id', $user->id)->orWhereNull('user_id');
            })
            ->find($mediaId);

        if ($asset === null) {
            abort(404, 'Media not found.');
        }

        return $asset;
    }

    /**
     * @param  array{alt_text?: string|null, tags?: list<string>|null, collection?: string|null}  $data
     */
    public function update(User $user, MediaAsset $asset, array $data): MediaAsset
    {
        $this->assertOwned($user, $asset);

        if (array_key_exists('alt_text', $data)) {
            $asset->alt_text = $data['alt_text'];
        }
        if (array_key_exists('tags', $data)) {
            $asset->tags = $data['tags'] ?? [];
        }
        if (! empty($data['collection'])) {
            $asset->collection = $this->assertCollection((string) $data['collection']);
        }

        $asset->save();

        return $asset->fresh() ?? $asset;
    }

    /**
     * @return list<array{type: string, id: int, role: string}>
     */
    public function delete(User $user, MediaAsset $asset, bool $force = false): array
    {
        $this->assertOwned($user, $asset);

        $attachmentSummary = $this->attachments->attachmentSummary($asset);

        if ($attachmentSummary !== [] && ! $force) {
            return $attachmentSummary;
        }

        DB::transaction(function () use ($asset, $user): void {
            $this->attachments->detachAll($user, $asset);
            $this->cloudinary->destroy($asset->public_id, [
                'invalidate' => true,
                'resource_type' => $asset->resource_type ?: 'image',
            ]);
            $asset->delete();
        });

        return [];
    }

    public function userFolder(User $user, string $collection): string
    {
        $root = trim((string) config('media.root_folder', 'nexus'), '/');
        $env = trim((string) config('media.env_segment', 'local'), '/');

        return "{$root}/{$env}/users/{$user->id}/{$collection}";
    }

    public function mirrorFolder(string $provider): string
    {
        $root = trim((string) config('media.root_folder', 'nexus'), '/');
        $env = trim((string) config('media.env_segment', 'local'), '/');

        return "{$root}/{$env}/mirror/{$provider}";
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  list<string>|null  $tags
     * @param  array<string, mixed>|null  $sourceMeta
     */
    public function persistFromCloudinaryResponse(
        ?User $user,
        array $response,
        string $collection,
        string $source,
        string $folder,
        ?string $altText = null,
        ?array $tags = null,
        ?string $sourceProvider = null,
        ?string $sourceRef = null,
        ?array $sourceMeta = null,
    ): MediaAsset {
        $publicId = (string) ($response['public_id'] ?? '');
        if ($publicId === '') {
            throw ValidationException::withMessages(['file' => 'Cloudinary did not return a public_id.']);
        }

        $asset = MediaAsset::query()->firstOrNew(['public_id' => $publicId]);
        $asset->fill([
            'user_id' => $user?->id,
            'asset_id' => isset($response['asset_id']) ? (string) $response['asset_id'] : $asset->asset_id,
            'folder' => (string) ($response['asset_folder'] ?? $response['folder'] ?? $folder),
            'collection' => $collection,
            'secure_url' => (string) ($response['secure_url'] ?? $response['url'] ?? ''),
            'format' => isset($response['format']) ? (string) $response['format'] : null,
            'resource_type' => (string) ($response['resource_type'] ?? 'image'),
            'bytes' => (int) ($response['bytes'] ?? 0),
            'width' => isset($response['width']) ? (int) $response['width'] : null,
            'height' => isset($response['height']) ? (int) $response['height'] : null,
            'version' => isset($response['version']) ? (int) $response['version'] : null,
            'etag' => isset($response['etag']) ? (string) $response['etag'] : null,
            'alt_text' => $altText,
            'source' => $source,
            'source_provider' => $sourceProvider,
            'source_ref' => $sourceRef,
            'source_meta' => $sourceMeta,
            'tags' => $tags ?? $asset->tags ?? [],
        ]);
        $asset->save();

        return $asset->fresh() ?? $asset;
    }

    private function assertOwned(User $user, MediaAsset $asset): void
    {
        if ($asset->user_id !== null && (int) $asset->user_id !== (int) $user->id) {
            abort(404, 'Media not found.');
        }
    }

    private function assertCollection(string $collection): string
    {
        $allowed = array_keys(config('media.collections', []));
        if (! in_array($collection, $allowed, true)) {
            throw ValidationException::withMessages([
                'collection' => 'Invalid media collection.',
            ]);
        }

        return $collection;
    }

    private function assertFile(UploadedFile $file, string $collection): void
    {
        $max = (int) (config("media.collections.{$collection}.max_bytes")
            ?? config('media.default_max_bytes', 10 * 1024 * 1024));

        if ($file->getSize() > $max) {
            throw ValidationException::withMessages([
                'file' => 'File exceeds the maximum upload size for this collection.',
            ]);
        }

        $mime = (string) $file->getMimeType();
        $allowed = config('media.allowed_mimes', []);
        if (! in_array($mime, $allowed, true)) {
            throw ValidationException::withMessages([
                'file' => 'Unsupported image type.',
            ]);
        }
    }

    private function incomingTransformation(string $collection): string
    {
        $name = (string) (config("media.collections.{$collection}.incoming_transformation")
            ?? 'nexus_master');

        // Upload API wants the bare named-transform name (no t_ prefix).
        // Delivery URLs use t_<name>; Admin API also displays names with t_.
        return str_starts_with($name, 't_') ? substr($name, 2) : $name;
    }
}
