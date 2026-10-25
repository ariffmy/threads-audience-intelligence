# Threads staging and next-phase gates

25 October 2026 (Asia/Kuala_Lumpur). This is a checklist and proposed scope, **not authorization to access staging, credentials, live APIs or production**. Phase 3B stops at local synthetic verification.

## Environment and migration evidence

- Run the committed candidate on PHP 8.5.12, MariaDB 10.11, AlmaLinux 8.10 and the actual cPanel web/CLI configuration. Local evidence is Windows/PHP 8.5.8/MariaDB 12.3.3, with the existing sodium DLL enabled only through a private per-process INI scan directory. No dependencies or global settings were installed/changed.
- Verify sodium XChaCha20-Poly1305, cURL TLS/CA configuration, native PDO/UTC/strict SQL, web/CLI parity, case-sensitive autoload/templates, private ownership/ACLs and `public/` isolation. Review actual cPanel binary paths rather than assuming one.
- Rehearse clean 25-migration installation, the original six-to-fifteen upgrade, fifteen-to-twenty-five upgrade and zero-change rerun. Preserve all original fifteen checksums. Corrupt/started/failed/unknown journal entries must stop. Test concurrent migration lock and partial-DDL failure in an isolated copy.
- On partial DDL, stop workers/mutations, retain journal/SQL and inspect actual tables/constraints. Restore a verified disposable baseline or prepare a reviewed forward repair; never edit applied SQL or erase history to force success. The migrator cannot roll back MariaDB DDL.
- Rollback: leave additive tables/columns intact, stop Threads work and retain Phase 3B migration history. Older releases do not know new migrations/jobs; do not run their migrators or Phase 2 retention against Threads data. Prefer forward repair. A DB rollback stays isolated until both deletion ledgers and retention have replayed.

## Provider gates before any proposed real-data pilot

G1–G6 remain open in [API_FEASIBILITY.md](API_FEASIBILITY.md). Refresh current official contracts and record actual reviewer/date/version evidence. A direct documentation recheck during 3B was inaccessible; implementation uses the supplied dated Phase 3A matrix without claiming a new live or publication verification.

1. **G2/G3 purpose and access:** establish eligible business presence-management purpose, app type/roles/access/publication/business verification and any App Review. Agree the owned-discussion scope and notices/retention/deletion arrangement. Owner consent alone cannot authorize reply-author profiling or processor transfer.
2. **G1 contracts:** exact registered HTTPS redirect, authorization host/parameters, actual minimal grants, short/long/debug/profile identity and separate expiry evidence; resolve PKCE rather than guessing. Prove actual Threads deauthorization/deletion signing secret, algorithm, identifier namespace/mapping, replay/delivery semantics, acknowledgement and status response before accepting provider events.
3. **G1 collection:** authorized test roots must come from the owner list, with matching ownership. Prove cursor/visibility/root/parent behavior, nullable fields and exact IDs. No arbitrary histories, private replies, stable author IDs or universal completeness claims.
4. **G5 resource/quota:** verify actual errors/headers/buckets without assuming generic Graph telemetry or publishing counters. Review app/account reservation scope and local caps, bounded streaming headers/decompressed bytes, redirects/TLS, transport timeouts and one-call invocation accounting in a new live adapter. No such executor ships now.
5. **G3 privacy/operations:** independent complete ledgers/key recovery, finite source/derivative retention, source/account/tenant deletion, callback mapping, race tests and negative reads after isolated encrypted backup restore. Resolve installation file-scan progress before claiming high-volume retention completeness. Review suppression-key rotation and disconnection-scope recovery explicitly.
6. **G4/G6:** discovery and OpenAI stay disabled. Do not use a later fixture acceptance as approval for either. Any analysis phase needs its own permitted-processing, aggregation/de-identification, model/schema/quality, retention and cost authorization. Person-level interest profiles remain outside scope.

## Target runtime and fault drill

Repeat OAuth replay/substitution/expiry, AEAD tampering/scope swaps/rotation/missing keys, refresh competing processes/unknown outcomes, stale lease/generation/privacy/grant/root/checkpoint checks, cursor loops/overlap/oversize/schema errors, cross-tenant FKs and negative routes. Kill processes after claim, fake request and publication/continuation; verify rollback, finite recovery and one local content object. Exercise bounded purge across more than 100 dependents and replay with absent targets, interrupted offsets and independently detected ledger truncation.

Measure full commands at 128M/30 seconds, with a 25-second/96-MiB worker target and four-second persistence reserve. The local suite's parent peak and one small worker sample are not a 100-post/10,000-reply or target-host capacity benchmark. Rehearse cron overlap/downtime, OS signals, database lock/statement limits, backlog/deletion priority, disk growth and rollback while web/cron remain inaccessible.

Verify desktop/mobile layout, keyboard focus, labels, CSRF/Origin/tenant denial, empty/pending/partial/expired/revoked states, callback redirects and web-server query redaction. The local browser evidence is a basic smoke check, not an accessibility audit.

## Release candidate and approval

Prepare a secret-free tracked-file candidate, checksums, exact test transcript, configuration/key/ledger recovery procedure and rollback package. Present remaining risks and verified purpose/grants before asking for any new phase or real-data approval. Do not deploy, enable live execution, push, merge or begin AI integration under Phase 3B authorization.

Recommendation for a possible Phase 3C: review and close purpose/app/callback/runtime prerequisites first; separately authorize the smallest tester contract exercise and live-adapter implementation only after that review. Current status: suitable for local fixture review; **not ready for live collection or production release**.
