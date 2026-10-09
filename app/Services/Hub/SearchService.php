<?php

namespace App\Services\Hub;

use App\Models\Beer\BeerBeer;
use App\Models\Cellar\CellarWine;
use App\Models\Github\GithubRepo;
use App\Models\Kitchen\KitchenRecipe;
use App\Models\Library\LibraryBook;
use App\Models\Spirit\SpiritSpirit;
use App\Models\Spotify\SpotifyPlaylist;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cross-module search over the user's own records. Each group is capped and
 * only selects the columns it renders, so a keystroke costs a handful of
 * small indexed queries.
 */
class SearchService
{
    public const PER_GROUP = 5;

    /**
     * @return list<array{key: string, label: string, items: list<array<string, mixed>>}>
     */
    public function search(User $user, string $query): array
    {
        $like = $this->likeTerm($query);

        $groups = [
            ['key' => 'cellar', 'label' => 'Wine', 'items' => $this->wines($user, $like)],
            ['key' => 'beer', 'label' => 'Beer', 'items' => $this->beers($user, $like)],
            ['key' => 'spirits', 'label' => 'Spirits', 'items' => $this->spirits($user, $like)],
            ['key' => 'kitchen', 'label' => 'Recipes', 'items' => $this->recipes($user, $like)],
            ['key' => 'library', 'label' => 'Library', 'items' => $this->books($user, $like)],
            ['key' => 'code', 'label' => 'Repositories', 'items' => $this->repos($user, $like)],
            ['key' => 'listening', 'label' => 'Playlists', 'items' => $this->playlists($user, $like)],
        ];

        return array_values(array_filter($groups, fn ($g) => $g['items'] !== []));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function wines(User $user, string $like): array
    {
        return CellarWine::query()
            ->where('user_id', $user->id)
            ->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('producer_name', 'like', $like)->orWhere('region_name', 'like', $like))
            ->orderByDesc('updated_at')
            ->limit(self::PER_GROUP)
            ->get(['id', 'name', 'producer_name', 'vintage', 'region_name', 'media_url'])
            ->map(fn (CellarWine $w) => $this->item('cellar_wine', $w->id, trim(($w->vintage ? $w->vintage.' ' : '').$w->name), $w->producer_name ?? $w->region_name, $w->media_url))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function beers(User $user, string $like): array
    {
        return BeerBeer::query()
            ->where('user_id', $user->id)
            ->where('name', 'like', $like)
            ->with('brewery:id,name')
            ->orderByDesc('updated_at')
            ->limit(self::PER_GROUP)
            ->get(['id', 'name', 'beer_brewery_id', 'media_url'])
            ->map(fn (BeerBeer $b) => $this->item('beer_beer', $b->id, $b->name, $b->brewery?->name, $b->media_url))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function spirits(User $user, string $like): array
    {
        return SpiritSpirit::query()
            ->where('user_id', $user->id)
            ->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('producer', 'like', $like)->orWhere('category', 'like', $like))
            ->orderByDesc('updated_at')
            ->limit(self::PER_GROUP)
            ->get(['id', 'name', 'producer', 'category', 'media_url'])
            ->map(fn (SpiritSpirit $s) => $this->item('spirit_spirit', $s->id, $s->name, collect([$s->producer, $s->category])->filter()->implode(' · ') ?: null, $s->media_url))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recipes(User $user, string $like): array
    {
        return KitchenRecipe::query()
            ->where('user_id', $user->id)
            ->whereHas('meal', fn (Builder $q) => $q->where('name', 'like', $like))
            ->with('meal:id,name,category,area,thumb_url')
            ->orderByDesc('updated_at')
            ->limit(self::PER_GROUP)
            ->get(['id', 'meal_catalog_meal_id', 'media_url'])
            ->map(fn (KitchenRecipe $r) => $this->item(
                'kitchen_recipe',
                $r->id,
                (string) $r->meal?->name,
                collect([$r->meal?->area, $r->meal?->category])->filter()->implode(' · ') ?: null,
                $r->media_url ?? $r->meal?->thumb_url,
            ))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function books(User $user, string $like): array
    {
        return LibraryBook::query()
            ->where('user_id', $user->id)
            ->where(fn (Builder $q) => $q->where('title', 'like', $like)->orWhere('authors', 'like', $like))
            ->orderByDesc('updated_at')
            ->limit(self::PER_GROUP)
            ->get(['id', 'title', 'authors', 'status', 'media_url'])
            ->map(fn (LibraryBook $b) => $this->item('library_book', $b->id, $b->title, $b->authors, $b->media_url))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function repos(User $user, string $like): array
    {
        return GithubRepo::query()
            ->where('user_id', $user->id)
            ->where('full_name', 'like', $like)
            ->orderByDesc('pushed_at')
            ->limit(self::PER_GROUP)
            ->get(['id', 'owner_login', 'name', 'full_name', 'language'])
            ->map(fn (GithubRepo $r) => $this->item('github_repo', $r->full_name, $r->full_name, $r->language, null, [
                'owner' => $r->owner_login,
                'repo' => $r->name,
            ]))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function playlists(User $user, string $like): array
    {
        return SpotifyPlaylist::query()
            ->where('user_id', $user->id)
            ->where('name', 'like', $like)
            ->orderByDesc('synced_at')
            ->limit(self::PER_GROUP)
            ->get(['id', 'spotify_id', 'name', 'item_count', 'image_url'])
            ->map(fn (SpotifyPlaylist $p) => $this->item('spotify_playlist', $p->spotify_id, $p->name, $p->item_count.' tracks', $p->image_url))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function item(string $type, int|string $id, string $title, ?string $subtitle, ?string $image, array $extra = []): array
    {
        return ['type' => $type, 'id' => $id, 'title' => $title, 'subtitle' => $subtitle, 'image' => $image] + $extra;
    }

    private function likeTerm(string $query): string
    {
        $term = trim($query);
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
        }

        return '%'.$term.'%';
    }
}
