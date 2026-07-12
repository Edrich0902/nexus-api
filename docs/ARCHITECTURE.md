# Nexus API — Architecture

## Overview

Nexus API is the central data layer for **Nexus Hub**, a personal life hub. It persists owned data, ingests from external services where useful, and exposes RESTful JSON endpoints for:

1. **nexus-web** — desktop-first Vue SPA (`nexus.test`)
2. **Future mobile app** — same endpoints, photo-friendly payloads for collections

```
┌─────────────┐     HTTPS/JSON      ┌──────────────┐
│  nexus-web  │ ◄─────────────────► │  nexus-api   │
│  (Vue SPA)  │   Bearer (Sanctum)  │  (Laravel)   │
└─────────────┘                     └──────┬───────┘
                                           │
                    ┌──────────────────────┼──────────────────────┐
                    │                      │                      │
              ┌─────▼─────┐         ┌──────▼──────┐       ┌──────▼──────┐
              │   MySQL   │         │ Queue Worker │       │  External   │
              │  nexus_db │         │  (database)  │       │    APIs     │
              └───────────┘         └──────────────┘       └─────────────┘
                                                          Spotify, GitHub,
                                                          sports, …
```

Vision and sequencing: [VISION.md](VISION.md), [ROADMAP.md](ROADMAP.md).

## Module sketch

Each module is a vertical slice: migrations, models, services, jobs, and API routes. Details below are **directional** — finalized when that milestone is specified.

| Module | Prefix (planned) | Role |
|--------|------------------|------|
| Auth / users | `/api/*` auth routes | Sanctum login, tokens |
| Spotify | `/api/spotify/*` | Listening sync & analytics |
| GitHub | `/api/github/*` | Developer activity / context |
| Cellar | `/api/cellar/*` | Wine collection |
| Library | `/api/library/*` | Book collection |
| Kitchen | `/api/kitchen/*` | Recipes |
| Media vaults | `/api/media/*` (TBD) | Personal media libraries |
| Social | TBD | Optional (e.g. Instagram) |
| Sports / F1 | `/api/sports/*`, `/api/f1/*` | Schedules, standings, ticker |

### Collections note (Cellar & Library)

CRUD on the web is the first surface. Schema and endpoints should leave room for **mobile photo / label intake** as a primary future path for adding bottles and books.

### Integrations note

Third-party modules (Spotify, GitHub, sports, optional social) typically share a pattern: OAuth or API keys → encrypted credentials → queued sync → normalized tables → read APIs for clients.

## Authentication

Laravel Sanctum bearer tokens:

- Web SPA and mobile use the same token flow
- Module routes under `/api/*` require `auth:sanctum` unless explicitly public (e.g. OAuth callbacks)
- Auth is the first concrete capability to finish before private feature modules

## Background processing

External API calls should not block HTTP responses.

```
HTTP Request → Controller → Dispatch Job → immediate response
                                │
                                ▼
                         Queue Worker → Service → External API → DB
```

Queue driver today: `database`. Redis later if Laradock Redis is enabled.

## Database conventions

- Plural, snake_case tables (`spotify_tracks`, `cellar_wines`)
- Module-oriented naming for clarity
- Timestamps on all tables; soft deletes where user data should be recoverable

## Error handling

`/api/*` returns JSON exceptions. Typical shape:

```json
{
  "message": "Human-readable summary",
  "errors": { "field": ["Validation message"] }
}
```

## Environment variables (planned, per milestone)

```dotenv
# Spotify
SPOTIFY_CLIENT_ID=
SPOTIFY_CLIENT_SECRET=
SPOTIFY_REDIRECT_URI=http://api.nexus.test/spotify/callback

# GitHub (when prioritized)
GITHUB_CLIENT_ID=
GITHUB_CLIENT_SECRET=

# Sports / F1 (when prioritized)
F1_API_KEY=
SPORTS_API_KEY=
```

Exact variable sets are defined when each integration is specified.
