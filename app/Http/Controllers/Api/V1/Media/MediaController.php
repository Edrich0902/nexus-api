<?php

namespace App\Http\Controllers\Api\V1\Media;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Media\AttachMediaRequest;
use App\Http\Requests\Api\V1\Media\StoreMediaFromUrlRequest;
use App\Http\Requests\Api\V1\Media\StoreMediaUploadRequest;
use App\Http\Requests\Api\V1\Media\UpdateMediaRequest;
use App\Http\Resources\Api\V1\Media\MediaAssetResource;
use App\Integrations\Unsplash\UnsplashIntegration;
use App\Jobs\Media\ReconcileMediaJob;
use App\Models\Media\MediaAsset;
use App\Services\Media\CloudinaryUsageService;
use App\Services\Media\MediaAttachmentService;
use App\Services\Media\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class MediaController extends Controller
{
    public function __construct(
        private readonly MediaService $media,
        private readonly MediaAttachmentService $attachments,
        private readonly CloudinaryUsageService $usage,
        private readonly UnsplashIntegration $unsplash,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return MediaAssetResource::collection(
            $this->media->listForUser($request->user(), [
                'collection' => $request->query('collection'),
                'source' => $request->query('source'),
                'attached' => $request->query('attached'),
                'tag' => $request->query('tag'),
                'q' => $request->query('q'),
                'per_page' => $request->integer('per_page', 24),
            ]),
        );
    }

    public function store(StoreMediaUploadRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $asset = $this->media->upload(
            $request->user(),
            $request->file('file'),
            [
                'collection' => $validated['collection'],
                'alt_text' => $validated['alt_text'] ?? null,
                'tags' => $validated['tags'] ?? null,
                'attach_to' => $validated['attach_to'] ?? null,
                'role' => $validated['role'] ?? 'cover',
            ],
        );

        return (new MediaAssetResource($asset->loadCount('attachments')))
            ->response()
            ->setStatusCode(201);
    }

    public function storeFromUrl(StoreMediaFromUrlRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $source = (string) ($validated['source'] ?? MediaAsset::SOURCE_UPLOAD);

        if ($source === MediaAsset::SOURCE_UNSPLASH && ! empty($validated['unsplash_download_location'])) {
            $this->unsplash->trackDownload((string) $validated['unsplash_download_location']);
        }

        $asset = $this->media->importFromUrl($request->user(), [
            'url' => $validated['url'],
            'collection' => $validated['collection'],
            'source' => $source,
            'source_provider' => $validated['source_provider'] ?? ($source === MediaAsset::SOURCE_UNSPLASH ? 'unsplash' : null),
            'source_ref' => $validated['source_ref'] ?? null,
            'source_meta' => $validated['source_meta'] ?? null,
            'alt_text' => $validated['alt_text'] ?? null,
            'tags' => $validated['tags'] ?? null,
            'attach_to' => $validated['attach_to'] ?? null,
            'role' => $validated['role'] ?? 'cover',
        ]);

        return (new MediaAssetResource($asset->loadCount('attachments')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, int $media): MediaAssetResource
    {
        $asset = $this->media->findAccessible($request->user(), $media);

        return new MediaAssetResource($asset);
    }

    public function update(UpdateMediaRequest $request, int $media): MediaAssetResource
    {
        $asset = $this->media->findAccessible($request->user(), $media);
        $asset = $this->media->update($request->user(), $asset, $request->validated());

        return new MediaAssetResource($asset->loadCount('attachments'));
    }

    public function destroy(Request $request, int $media): JsonResponse|Response
    {
        $asset = $this->media->findAccessible($request->user(), $media);
        $force = filter_var($request->query('force', false), FILTER_VALIDATE_BOOL);
        $blocking = $this->media->delete($request->user(), $asset, $force);

        if ($blocking !== []) {
            return response()->json([
                'message' => 'Media is attached to one or more records. Pass force=true to delete anyway.',
                'attachments' => $blocking,
            ], 409);
        }

        return response()->noContent();
    }

    public function attach(AttachMediaRequest $request, int $media): MediaAssetResource
    {
        $asset = $this->media->findAccessible($request->user(), $media);
        $validated = $request->validated();

        $this->attachments->attach(
            $request->user(),
            $asset,
            (string) $validated['type'],
            (int) $validated['id'],
            (string) ($validated['role'] ?? 'cover'),
        );

        return new MediaAssetResource(
            $this->media->findAccessible($request->user(), $media),
        );
    }

    public function detach(AttachMediaRequest $request, int $media): MediaAssetResource
    {
        $asset = $this->media->findAccessible($request->user(), $media);
        $validated = $request->validated();

        $this->attachments->detach(
            $request->user(),
            $asset,
            (string) $validated['type'],
            (int) $validated['id'],
            (string) ($validated['role'] ?? 'cover'),
        );

        return new MediaAssetResource(
            $this->media->findAccessible($request->user(), $media),
        );
    }

    public function usage(Request $request): JsonResponse
    {
        unset($request);

        return response()->json(
            $this->usage->snapshot(
                filter_var(request()->query('refresh', false), FILTER_VALIDATE_BOOL),
            ),
        );
    }

    public function reconcile(Request $request): JsonResponse
    {
        ReconcileMediaJob::dispatch($request->user()->id);

        return response()->json([
            'message' => 'Media reconcile queued.',
        ], 202);
    }

    public function unsplashSearch(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));
        if ($query === '') {
            return response()->json([
                'message' => 'The q field is required.',
                'errors' => ['q' => ['A search query is required.']],
            ], 422);
        }

        $payload = $this->unsplash->search(
            $query,
            max(1, $request->integer('page', 1)),
            $request->integer('per_page', 20) ?: null,
        );

        return response()->json($payload);
    }
}
