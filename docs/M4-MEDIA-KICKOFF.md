# Milestone 4 — Media platform (Cloudinary)

Status: **implemented** (API + web vault + collection cover images).

Roadmap: [ROADMAP.md](ROADMAP.md) · Architecture: [ARCHITECTURE.md](ARCHITECTURE.md)

## Scope

| Surface | Notes |
|---------|-------|
| Generic media API | `/api/v1/media/*` — upload, from-url, attach, vault list, usage, reconcile, Unsplash search |
| Cloudinary | Server-side only via `CloudinaryClient`; folders under `nexus/{env}/…` |
| NexusImageUploader | Device / Camera / Unsplash / Vault tabs; client-side downscale |
| Media vault UI | `/media` — grid, filters, usage meters, force-delete, reconcile |
| Cover images | Profile avatar, cellar wines, kitchen recipes, beer entries |
| Mirroring | WineAPI on enrichment complete; MealDB on recipe save; Unsplash on pick |

## Decisions

- All uploads hit `nexus-api` — never browser → Cloudinary.
- No eager transformations. Incoming masters use named transforms `nexus_master` / `nexus_master_avatar` (Strict-safe). Delivery: `nexus_thumb`, `nexus_card`, `nexus_hero`, `nexus_avatar`.
- Max upload 10 MB (5 MB avatars). Client downscales to 2560px WebP when possible.
- Denormalised `media_asset_id` / `media_public_id` / `media_url` on cover rows + `mediables` pivot.
- Morph attach aliases only (`user`, `cellar_wine`, `kitchen_recipe`, `beer_beer`, `wine_catalog_wine`).

## Setup checklist

1. Cloudinary API keys → `CLOUDINARY_*` in `nexus-api/.env`
2. Unsplash app keys → `UNSPLASH_*`
3. `php artisan media:sync-transformations`
4. Enable **Strict transformations** in Cloudinary; confirm auto-backup is **off**
5. `VITE_CLOUDINARY_CLOUD_NAME` in `nexus-web` env files
6. Confirm nginx/PHP body limits ≥ 12M

## Key routes

```
POST   /api/v1/media
POST   /api/v1/media/from-url
GET    /api/v1/media
GET    /api/v1/media/{id}
PATCH  /api/v1/media/{id}
DELETE /api/v1/media/{id}
POST   /api/v1/media/{id}/attach
DELETE /api/v1/media/{id}/attach
GET    /api/v1/media/usage
POST   /api/v1/media/reconcile
GET    /api/v1/media/sources/unsplash
```

Web: `/media`, profile photo uploader, “Change image” on cellar / kitchen / beer detail.
