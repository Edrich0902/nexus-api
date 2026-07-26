# Milestone 2 — Food & Drink (Cellar, Kitchen, Beer)

Status: **implemented** (web + API). Library (books) and FastCork label recognition are deferred to a later milestone.

Roadmap context: [ROADMAP.md](ROADMAP.md) · Conventions: [ARCHITECTURE.md](ARCHITECTURE.md), [DEVELOPMENT.md](DEVELOPMENT.md)

---

## Scope (as shipped)

| Domain | Model | Upstream | Notes |
|--------|-------|----------|-------|
| **Cellar** | Drinking journal (not inventory) | [WineAPI](https://wineapi.io/docs/) — 100 req/day | One wine + many tastings; catalog match always user-confirmed; enrichment queued when budget exhausted |
| **Kitchen** | Saved recipes from TheMealDB | [TheMealDB](https://www.themealdb.com/documentation#access) free key `1` | Imports only; own-recipe authoring later |
| **Beer** | Self-authored beer log | [Open Brewery DB](https://www.openbrewerydb.org/) | Manual brewery creation is first-class when OBDB misses (e.g. ZA) |
| **Hub** | Pairings + rule-based suggestions | Local data only | Explainable reasons; no ML |

Ratings are **0.5–5.0** half-steps everywhere. Images are external URLs only (no uploads / R2 yet).

---

## Architecture highlights

- Providers use `ProviderHttpClient` + `UpstreamRateGate` (no OAuth).
- WineAPI daily cap is enforced by `UpstreamDailyBudget` + `upstream_request_budgets` table, with search headroom reserved for enrichment jobs.
- Global catalog tables (`wine_catalog_*`, `meal_catalog_*`, `beer_breweries`) share enrichment cost; user tables are soft-deleted and ownership-scoped.
- Jobs: `EnrichWineJob`, hourly `RetryPendingWineEnrichmentJob`.

### Key routes

```
/api/v1/cellar/*
/api/v1/kitchen/*
/api/v1/beer/*
/api/v1/food-drink/dashboard|pairings|suggestions
```

Web: `/food-drink`, `/cellar`, `/kitchen`, `/beer` with sidebar groups **Modules → Food & Drink** and **Cellar & Kitchen**.

---

## Deferred / out of scope for M2

1. **Library (books)** — Open Library / Google Books (original M2 draft).
2. **FastCork / photo intake** — mobile camera capture later.
3. **Own recipe authoring** and recipe URL JSON-LD import.
4. **Image mirroring** to R2/Cloudinary.
5. **Learned recommendation weights** — ship rule-based scorer first.

---

## Env

See `.env.example` for `WINEAPI_*`, `MEALDB_*`, `OPENBREWERYDB_*`. Never log API keys.
