# Nexus API — Vision

Nexus Hub is a personal life hub: one backend that aggregates, stores, and serves everything that matters across lifestyle, collections, media, and integrations.

This API is the source of truth. The Vue web dashboard (`nexus-web`) and a future mobile app consume the same REST endpoints. Features that shine on mobile (photo capture for wine/books, on-the-go logging) are designed here first as API capabilities, then built out in clients.

## What Nexus Is

A modular personal platform where each domain is a vertical slice — its own models, jobs, and `/api/{module}/*` routes — while sharing authentication, patterns, and infrastructure.

## Pillars (milestones, not specs)

These are directional milestones. When a milestone becomes active work, research what is required and possible, write a short spec, then implement. Do not treat this list as a locked implementation plan.

| Milestone | Intent |
|-----------|--------|
| **Foundation & Auth** | Secure, token-based API ready for SPA and mobile |
| **Spotify** | Listening history, connection, and personal music analytics |
| **GitHub** | Personal developer activity and repo/context surfaces |
| **Cellar** | Wine collection inventory; mobile photo/label intake later |
| **Library** | Book collection and reading status; mobile photo intake later |
| **Kitchen** | Personal recipes and related kitchen content |
| **Media vaults** | Personal media libraries / vault surfaces |
| **Social (e.g. Instagram)** | Optional social/media integrations where useful |
| **Sports & F1** | Schedules, standings, live/weekend sports context |
| **Mobile app** | Same API; photo-first collection workflows |

## Design Principles

- **API-first** — every feature ships as stable JSON endpoints before (or with) UI.
- **Stateless clients** — Sanctum bearer tokens for web and mobile.
- **Background ingestion** — third-party sync runs in queues, never blocks HTTP.
- **Module boundaries** — clear prefixes and namespaces so domains stay independent.
- **Mobile-ready schema** — cellar/library (and similar) models anticipate photo/quick-log payloads even before the mobile app exists.
- **Docs as living context** — roadmap and vision stay high-level; detailed specs land when a milestone starts.

## Related

- [ROADMAP.md](ROADMAP.md) — ordered milestones and rough sequencing
- [ARCHITECTURE.md](ARCHITECTURE.md) — system shape and module sketch
- Web vision: [`../../nexus-web/docs/VISION.md`](../../nexus-web/docs/VISION.md)
