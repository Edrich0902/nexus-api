<?php

namespace App\Http\Controllers\Api\V1\Library;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Library\ConfirmLibraryMatchRequest;
use App\Http\Requests\Api\V1\Library\StoreLibraryBookFromCatalogRequest;
use App\Http\Requests\Api\V1\Library\StoreLibraryBookRequest;
use App\Http\Requests\Api\V1\Library\UpdateLibraryBookRequest;
use App\Http\Resources\Api\V1\Library\LibraryBookResource;
use App\Services\Library\LibraryBookService;
use App\Services\Library\LibraryMatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LibraryController extends Controller
{
    public function __construct(
        private readonly LibraryBookService $books,
        private readonly LibraryMatchService $matches,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return LibraryBookResource::collection(
            $this->books->list($request->user(), [
                'q' => $request->query('q'),
                'status' => $request->query('status'),
                'match_status' => $request->query('match_status'),
                'per_page' => $request->integer('per_page', 20),
            ]),
        );
    }

    public function pulse(Request $request): JsonResponse
    {
        $payload = $this->books->pulse($request->user());

        return response()->json([
            'counts' => $payload['counts'],
            'reading' => LibraryBookResource::collection($payload['reading'])->resolve(),
            'recent' => LibraryBookResource::collection($payload['recent'])->resolve(),
        ]);
    }

    public function store(StoreLibraryBookRequest $request): JsonResponse
    {
        $book = $this->books->create($request->user(), $request->validated());

        return (new LibraryBookResource($book))->response()->setStatusCode(201);
    }

    public function storeFromCatalog(StoreLibraryBookFromCatalogRequest $request): JsonResponse
    {
        $data = $request->validated();
        $olWorkKey = (string) $data['ol_work_key'];
        unset($data['ol_work_key']);

        $book = $this->matches->createFromCatalog($request->user(), $olWorkKey, $data);

        return (new LibraryBookResource($book))->response()->setStatusCode(201);
    }

    public function show(Request $request, int $book): LibraryBookResource
    {
        return new LibraryBookResource($this->books->findOwned($request->user(), $book));
    }

    public function update(UpdateLibraryBookRequest $request, int $book): LibraryBookResource
    {
        $model = $this->books->findOwned($request->user(), $book);

        return new LibraryBookResource(
            $this->books->update($request->user(), $model, $request->validated()),
        );
    }

    public function destroy(Request $request, int $book): JsonResponse
    {
        $model = $this->books->findOwned($request->user(), $book);
        $this->books->delete($request->user(), $model);

        return response()->json(['message' => 'Book deleted.']);
    }

    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        return response()->json([
            'results' => $q === '' ? [] : $this->matches->searchUpstream($q, 20),
        ]);
    }

    public function candidates(Request $request, int $book): JsonResponse
    {
        $model = $this->books->findOwned($request->user(), $book);
        $payload = $this->matches->candidates(
            $request->user(),
            $model,
            $request->query('q'),
        );

        return response()->json($payload);
    }

    public function confirmMatch(ConfirmLibraryMatchRequest $request, int $book): LibraryBookResource
    {
        $model = $this->books->findOwned($request->user(), $book);

        return new LibraryBookResource(
            $this->matches->confirmMatch(
                $request->user(),
                $model,
                (string) $request->validated('ol_work_key'),
            ),
        );
    }

    public function noMatch(Request $request, int $book): LibraryBookResource
    {
        $model = $this->books->findOwned($request->user(), $book);

        return new LibraryBookResource(
            $this->matches->markNoMatch($request->user(), $model),
        );
    }

    public function clearMatch(Request $request, int $book): LibraryBookResource
    {
        $model = $this->books->findOwned($request->user(), $book);

        return new LibraryBookResource(
            $this->matches->clearMatch($request->user(), $model),
        );
    }
}
