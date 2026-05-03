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
- Google driver must normalize Search Console and Analytics data for the orchestrator.
- Search Console reconciliation now defaults to IPF/Raking with bridge seeding, using all 16 subset constraints as simultaneous inputs.
- The earlier daily distribution bug was fixed by including the metric date in the aggregation grouping key.
- Synthetic generation is controlled by `calculate_synthetics`; when disabled, the driver should query only the full 5D subset to avoid overcounting and reduce quota usage.
- Query breakdown performance relies on the optimized aggregate path and the shared helper conservation tests; keep the invariants (`clicks <= impressions`, non-negative outputs, bounded CTR) intact.
