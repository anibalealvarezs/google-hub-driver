# Google driver surface — GSC search types

The existing Google Analytics, Google Ads and Business Profile drivers are unchanged by this update.

| GSC surface | Status |
|---|---|
| Hierarchy | Site / channeled account → page; query, country and device associations where supported |
| Web, Image, Video, News tab | Daily clicks, impressions, CTR and position; independent query/country/page/device reconciliation and separate appearance pass |
| Discover, Google News | Daily page/country clicks, impressions and CTR; no query, device or position is fabricated |
| Search type | Stored as `search_type` dimension; part of dimensions hash and config identity |
| Settings | `search_types` defaults to all six supported values; an explicit non-empty subset limits source calls |
| Aggregation | Existing requests default to Web via driver profile defaults; explicit `dimensions.search_type` filters select other types |
| Historical ingestion | Existing seven-day processing windows; sequential daily/type calls; empty search-type baseline short-circuits reconciliation fan-out |
| Synthetic mode | Search-result types only, isolated per type; disabled mode retrieves full cube plus appearance, without adding a second totals dataset |
| Failures and cancellation | Checked between days/types/passes/retries; failed windows propagate to scheduler rather than reporting complete success |

`news` means the Google Search News tab; `googleNews` means the Google News site/app. These are separate source datasets.

## Tenant rollout

Release the Google driver and deploy it with the tenant upgrade routine `1.18.2 → 1.18.3`. The routine labels existing untyped GSC configurations as Web, recomputes their canonical signatures, and updates both metric dimension hashes and channeled metric dimension-set links. It preserves already typed and other-channel records. Signature collisions are reconciled with the established recalculation policy: retain the oldest config ID, overlapping observations from newer configs, unique historical dates, and channel links. Duplicate observations are never summed. Unexpected write errors still roll back the transaction.

Stop sync workers for the database migration and take the normal pre-upgrade backup. The routine also locks metric tables against concurrent writers; that PostgreSQL lock is not exercised by the SQLite regression suite. Downgrade requires restoring the backup because dropping type identities after additional ingestion would merge distinct datasets.

New scheduled windows fetch all enabled types. To populate **older dates** for the newly supported types, replay the configured GSC history range. The existing `app:nuclear-resync --channel=google_search_console` command can reschedule that channel without deleting its cached metrics: **do not pass `--wipe-metrics`**. It replaces that channel's pending jobs, so run it during rollout with workers stopped, then resume workers. No tenant or source requests are run by the automated regression tests.

No source data is reconstructed beyond Google's retention/availability limits. Discover/Google News are ingested at date/page/country granularity without synthetic reconciliation or appearance expansion in this release. URL-derived fields and new UI selectors are outside this update.
