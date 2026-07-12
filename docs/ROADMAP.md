# Nexus API — Roadmap

High-level milestones for Nexus Hub. These are **directional markers**, not detailed specs.

When a milestone becomes the current focus: research requirements and constraints, draft a short implementation note, then build. Earlier docs that listed endpoint checklists were aspirational sketches — replace them with real specs at kickoff time.

Aligned with [`nexus-web/docs/ROADMAP.md`](../../nexus-web/docs/ROADMAP.md). Vision context: [VISION.md](VISION.md).

---

## Milestone 0 — Foundation ✅ (largely done)

Infrastructure and application skeleton.

**Done**

- [x] Laradock environment (nginx, php-fpm, mysql, workspace)
- [x] Laravel 11 project with Sanctum
- [x] Base migrations (users, cache, jobs, personal access tokens)
- [x] API route scaffold (`/api/user`)
- [x] JSON exception rendering for `/api/*`

**Still open before feature work**

- [x] Authentication endpoints (login, token issue/revoke, me, refresh, sessions)
- [x] Shared API resource / response conventions (Resources, no `data` wrapper)
- [x] Auth hardening (rate limits, CORS allowlist, HTTPS outside local)
- [ ] Change password / password reset (deferred until email integration)
- [ ] Queue / schedule worker usage documented and ready for sync jobs

---

## Milestone 1 — Spotify

**Intent:** Connect a Spotify account, ingest listening-related data, expose it for the dashboard.

At kickoff: confirm Spotify API scopes, OAuth flow, rate limits, and what is worth caching vs fetching live. Spec tokens, tables, jobs, and routes then.

Rough expectations (not a checklist):

- OAuth connect / callback / disconnect
- Token storage and refresh
- Sync of recently played / top items (or equivalent useful subsets)
- Status + read APIs for the web module

---

## Milestone 2 — Collections (Cellar, Library, Kitchen)

**Intent:** First-party personal collections owned entirely by Nexus.

### Cellar

Wine inventory — producers, regions, vintages, stock, drinking windows / notes. Schema should anticipate **mobile photo / label capture** as a primary future intake path (not only manual CRUD).

### Library

Book catalog and reading status. Same mobile-friendly intake mindset (cover/spine photos later).

### Kitchen

Recipes and related personal kitchen content.

At kickoff: model the domain lightly, define CRUD + any “quick-log” endpoints the mobile app will need later.

---

## Milestone 3 — Developer & social integrations

**Intent:** Pull in personal context from platforms that matter day to day.

### GitHub

Activity, repos, or profile surfaces useful on a personal hub. Spec OAuth/App permissions and which data is worth storing locally when the milestone starts.

### Instagram (optional / later)

Only if there is a clear personal-hub use case and a viable API path. Treat as exploratory until explicitly prioritized.

---

## Milestone 4 — Media vaults

**Intent:** Personal media libraries / vault surfaces backed by the API (storage strategy TBD at kickoff — local, object storage, or hybrid).

---

## Milestone 5 — Sports & F1

**Intent:** Schedules, standings, and weekend/live sports context for the dashboard ticker and dedicated views.

At kickoff: choose data sources, refresh strategy, and which leagues/events are in scope for v1.

---

## Milestone 6 — Mobile app (future client)

Not a separate API product — a new consumer of this same API.

- Sanctum bearer auth
- Same module endpoints
- Photo-first cellar/library intake
- Optional push hooks later

---

## Suggested sequencing

```
Foundation + Auth
    → Spotify
    → Cellar / Library / Kitchen
    → GitHub (and other integrations as needed)
    → Media vaults
    → Sports / F1
    → Mobile client
```

Order can shift. Auth should land before any private module work. Integrations can be interleaved once patterns from Spotify exist.

---

## Decision log

| Date | Decision | Rationale |
|------|----------|-----------|
| 2026-07-11 | Database queue driver | No Redis yet; enough for local sync jobs |
| 2026-07-11 | Sanctum over Passport | First-party SPA + future mobile |
| 2026-07-11 | Module-prefixed routes | Clear surface for a multi-domain hub |
| 2026-07-12 | Roadmaps stay milestone-level | Spec each milestone at kickoff; avoid premature endpoint lock-in |
| 2026-07-12 | Expanded vision pillars | GitHub, media vaults, optional Instagram, photo-driven collections |
