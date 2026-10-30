# Google Hub Driver Memory
## Scope
- Package role: Normalization (Drivers)
- Purpose: This package operates within the Normalization (Drivers) layer of the APIs Hub SaaS hierarchy, providing data normalization for Google ecosystem channels.
- Dependency stance: Consumes `anibalealvarezs/api-client-skeleton`, `anibalealvarezs/api-driver-core`, and `anibalealvarezs/google-api`; serves the Orchestrator (apis-hub).
## Local working rules
- Consult `AGENTS.md` first for package-specific instructions.
- Use this `MEMORY.md` for repository-specific decisions, learnings, and follow-up notes.
- Use `D:\laragon\www\_shared\AGENTS.md` and `D:\laragon\www\_shared\MEMORY.md` for cross-repository protocols and workspace-wide learnings.
- Keep secrets, credentials, tokens, and private endpoints out of this file.
## Current notes
- GA4 correctness (2026-10-30): driver publishes query_contract v1 in universal profile, blocks cost, isolates matrix scopes, defines same-grain session-weighted rates, explicit reported-user sums, virtual source/medium and revenue currency guards. Requires corresponding tenant executor. Property metadata selects keyEvents/legacy conversions; storage remains conversions. SDK compatibility preflight validates requested fields; unrelated candidate incompatibilities do not fail valid reports. Failed matrix reports throw for retry. Conversion retains allowlisted quality metadata and skips absent values instead of zero. No default grain/signature rewrite; old metadata/missing companions may need controlled replay. See tenant docs/ga4-correctness-rollout.md.
- GSC search types: `SearchConsoleDriver::SEARCH_TYPES` includes web/image/video/news/discover/googleNews. `search_types` defaults to all six; explicit lists limit calls. Search-result cubes reconcile independently per type; empty baselines short-circuit and non-synthetic mode no longer mixes baseline totals with full-cube rows. Discover/Google News use date/page/country and omit unsupported position/query/device data. Conversion defaults legacy inputs to Web and includes `search_type` in dimension identity. Existing aggregation profiles default to Web; explicit dimension filters override. Rollout needs tenant 1.18.3 backfill plus channel-only historical replay without `--wipe-metrics`; see `docs/surface-manifest.md`.
- Google driver must normalize Search Console and Analytics data for the orchestrator.
- Search Console reconciliation now defaults to IPF/Raking with bridge seeding, using all 16 subset constraints as simultaneous inputs.
- The earlier daily distribution bug was fixed by including the metric date in the aggregation grouping key.
- Synthetic generation is controlled by `calculate_synthetics`; when disabled, the driver should query only the full 5D subset to avoid overcounting and reduce quota usage.
- Query breakdown performance relies on the optimized aggregate path and the shared helper conservation tests; keep the invariants (`clicks <= impressions`, non-negative outputs, bounded CTR) intact.
- Shared Phase 2 pilot: `SearchConsoleDriver` now implements `AggregationProfileProviderInterface` and exposes normalized aggregation capability profiles (`gsc_search_cube`, `gsc_page_flow`) for planner-side capability validation in `apis-hub`.
- Google priority pilot completed for canonical metric equivalence: `SearchConsoleDriver` now implements `CanonicalMetricDictionaryProviderInterface` with read-only canonical -> raw mapping for aggregation-time resolution, and this Google step closes the pilot-expansion stage for now.
- **GSC Profiles**: Split `gsc_search_cube` into two profiles (Standard and By Appearance) to handle mandatory `searchAppearance` filters via `default_filters`, removing the need for hardcoded logic in the planner.
- **Platform Identity**: Implemented `getPlatformEntityIdField()` to return `site_url`, facilitating agnostic identity resolution in the core engine.
- **GSC Driver Fix**: Resolved a critical `ParseError` in `SearchConsoleDriver.php` caused by massive code duplication and a malformed `isAuthenticationError` method. The duplicate block (approx. 200 lines) was removed and the method was restored to its correct implementation.
- **Google Business Profile Driver**: Integrated `GoogleBusinessProfileDriver` under the `google_business_profile` channel, mapping location resources from `locations/{id}` paths as assets, and standardizing daily metrics (`BUSINESS_IMPRESSIONS_DESKTOP_MAPS`, `WEBSITE_CLICKS`, etc.) via `GoogleBusinessProfileMetricConvert` into APIs Hub canonical models.

- 2026-10-30: GA4 expansion first increment: observed event discovery uses paginated eventCount with metadata/compatibility preflight; raw names are identities, canonical classification is metadata. Failed discovery/cancellation prevents successful metric sync. Default report recipes declare unchanged grains and metric allowlists; property recipe projection separates metadata availability from unchecked compatibility/unknown cache coverage. Historical repair and authenticated acceptance remain pending.

- 2026-10-30: GA4 expansion: paginated eventCount discovery preserves raw event names; classifications remain metadata. Failed discovery/cancellation prevents successful sync. Declared recipes preserve grains and restrict metrics; property metadata projection separates availability from compatibility and cache coverage. Authenticated acceptance/historical repair remain pending.
