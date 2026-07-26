<?php

namespace App\Services\Media;

use App\Models\Beer\BeerBeer;
use App\Models\Cellar\CellarWine;
use App\Models\Kitchen\KitchenRecipe;
use App\Models\Media\MediaAsset;
use App\Models\Media\Mediable;
use App\Models\User;
use App\Models\WineCatalog\WineCatalogWine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MediaAttachmentService
{
    /**
     * Attach media to a model and sync denormalised cover columns when role is cover.
     */
    public function attach(
        User $user,
        MediaAsset $asset,
        string $typeAlias,
        int $id,
        string $role = 'cover',
    ): Mediable {
        $model = $this->resolveAttachable($user, $typeAlias, $id);
        $morphType = $this->morphAliasFor($model);

        return DB::transaction(function () use ($asset, $model, $morphType, $role): Mediable {
            if ($role === 'cover') {
                Mediable::query()
                    ->where('mediable_type', $morphType)
                    ->where('mediable_id', $model->getKey())
                    ->where('role', 'cover')
                    ->where('media_asset_id', '!=', $asset->id)
                    ->delete();
            }

            $mediable = Mediable::query()->updateOrCreate(
                [
                    'media_asset_id' => $asset->id,
                    'mediable_type' => $morphType,
                    'mediable_id' => $model->getKey(),
                    'role' => $role,
                ],
                [
                    'position' => 0,
                ],
            );

            if ($role === 'cover' && $this->hasCoverColumns($model)) {
                $model->forceFill([
                    'media_asset_id' => $asset->id,
                    'media_public_id' => $asset->public_id,
                    'media_url' => $asset->secure_url,
                ])->save();
            }

            return $mediable;
        });
    }

    public function detach(
        User $user,
        MediaAsset $asset,
        string $typeAlias,
        int $id,
        string $role = 'cover',
    ): void {
        $model = $this->resolveAttachable($user, $typeAlias, $id);
        $morphType = $this->morphAliasFor($model);

        DB::transaction(function () use ($asset, $model, $morphType, $role): void {
            Mediable::query()
                ->where('media_asset_id', $asset->id)
                ->where('mediable_type', $morphType)
                ->where('mediable_id', $model->getKey())
                ->where('role', $role)
                ->delete();

            if ($role === 'cover' && $this->hasCoverColumns($model) && (int) $model->getAttribute('media_asset_id') === (int) $asset->id) {
                $model->forceFill([
                    'media_asset_id' => null,
                    'media_public_id' => null,
                    'media_url' => null,
                ])->save();
            }
        });
    }

    public function detachAll(User $user, MediaAsset $asset): void
    {
        unset($user);

        DB::transaction(function () use ($asset): void {
            $attachments = Mediable::query()
                ->where('media_asset_id', $asset->id)
                ->get();

            foreach ($attachments as $attachment) {
                $model = $attachment->mediable;
                if ($model instanceof Model
                    && $this->hasCoverColumns($model)
                    && (int) $model->getAttribute('media_asset_id') === (int) $asset->id
                ) {
                    $model->forceFill([
                        'media_asset_id' => null,
                        'media_public_id' => null,
                        'media_url' => null,
                    ])->save();
                }
            }

            Mediable::query()->where('media_asset_id', $asset->id)->delete();

            // Clear any leftover denormalised references (e.g. attach skipped mediable).
            foreach ($this->coverModels() as $class) {
                $class::query()
                    ->where('media_asset_id', $asset->id)
                    ->update([
                        'media_asset_id' => null,
                        'media_public_id' => null,
                        'media_url' => null,
                    ]);
            }
        });
    }

    /**
     * @return list<array{type: string, id: int, role: string}>
     */
    public function attachmentSummary(MediaAsset $asset): array
    {
        return Mediable::query()
            ->where('media_asset_id', $asset->id)
            ->get()
            ->map(fn (Mediable $row) => [
                'type' => (string) $row->mediable_type,
                'id' => (int) $row->mediable_id,
                'role' => (string) $row->role,
            ])
            ->values()
            ->all();
    }

    public function resolveAttachable(User $user, string $typeAlias, int $id): Model
    {
        /** @var array<string, class-string<Model>> $map */
        $map = config('media.attachable', []);
        $class = $map[$typeAlias] ?? null;

        if ($class === null) {
            throw ValidationException::withMessages([
                'attach_to.type' => 'Unknown attachable type.',
            ]);
        }

        $model = $class::query()->find($id);
        if ($model === null) {
            abort(404, 'Attachable model not found.');
        }

        $this->assertCanAttach($user, $model);

        return $model;
    }

    private function assertCanAttach(User $user, Model $model): void
    {
        if ($model instanceof User) {
            if ((int) $model->id !== (int) $user->id) {
                abort(404, 'Attachable model not found.');
            }

            return;
        }

        if ($model instanceof WineCatalogWine) {
            return;
        }

        if (isset($model->user_id) && (int) $model->user_id !== (int) $user->id) {
            abort(404, 'Attachable model not found.');
        }
    }

    private function morphAliasFor(Model $model): string
    {
        $morph = array_search($model::class, Relation::morphMap(), true);

        return is_string($morph) ? $morph : $model->getMorphClass();
    }

    private function hasCoverColumns(Model $model): bool
    {
        return $model->isFillable('media_asset_id')
            || array_key_exists('media_asset_id', $model->getAttributes())
            || in_array($model::class, $this->coverModels(), true);
    }

    /**
     * @return list<class-string<Model>>
     */
    private function coverModels(): array
    {
        return [
            User::class,
            CellarWine::class,
            KitchenRecipe::class,
            BeerBeer::class,
            WineCatalogWine::class,
        ];
    }
}
