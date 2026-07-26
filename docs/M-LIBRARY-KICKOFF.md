# Milestone — Book library (Open Library)

Status: **implemented** (API + web shelf + Open Library match).

Roadmap: [ROADMAP.md](ROADMAP.md) · Architecture: [ARCHITECTURE.md](ARCHITECTURE.md)

## Scope

| Surface | Notes |
|---------|-------|
| Personal shelf | One user-owned book row: `want` / `reading` / `read`, rating, notes, started/finished dates |
| Catalog | Global `library_catalog_books` keyed by Open Library work id |
| Match | User-confirmed only — candidates → confirm / no-match / clear |
| Covers | Open Library cover URLs; mirror into Cloudinary on match when media mirroring is enabled |
| Web | `/library` list + detail + match dialog; home pulse + command brief chip |

## Decisions

- Shelf model (not a reading-session journal). Re-reads / session timelines deferred.
- Open Library only for v1 (no Google Books).
- Manual entry always allowed when catalog misses.
- Providers use `ProviderHttpClient` + `UpstreamRateGate` (no OAuth, no daily budget ledger unless rate limits bite).
- Ratings are **0.5–5.0** half-steps (same as Food & Drink).

## Key routes

```
GET    /api/v1/library/pulse
GET    /api/v1/library/books
POST   /api/v1/library/books
GET    /api/v1/library/books/{id}
PATCH  /api/v1/library/books/{id}
DELETE /api/v1/library/books/{id}
GET    /api/v1/library/search
GET    /api/v1/library/books/{id}/candidates
POST   /api/v1/library/books/{id}/match
POST   /api/v1/library/books/{id}/no-match
DELETE /api/v1/library/books/{id}/match
POST   /api/v1/library/books/from-catalog
```

Web: `/library`, `/library/books/:bookId`, home library pulse + Today command brief.

## Env

See `.env.example` for `OPENLIBRARY_*`. No API key required for public search/covers.

## Out of v1

- Barcode / camera intake (mobile later)
- Google Books dual-wire
- Reading session timeline
