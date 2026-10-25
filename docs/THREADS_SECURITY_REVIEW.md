# Phase 3B security review

25 October 2026 (Asia/Kuala_Lumpur). Engineering review of the local fixture candidate, supported by the automated evidence in [PHASE3B_COMPLETION_REPORT.md](PHASE3B_COMPLETION_REPORT.md). This is not an independent penetration test, legal/policy certification or live-provider assessment.

## Reviewed boundaries and evidence

| Boundary | Implemented control and verification |
| --- | --- |
| No live execution | Only disabled/in-memory fixture transports are composed. Production refuses fixture actions. Provider operations/paths are fixed; fake codes/tokens are enforced. No Threads socket/cURL executor exists. Unit/integration denial tests pass. |
| Local authorization | Existing administrator/owner/authentication, Host/Origin and POST+CSRF protection apply to all human mutation routes. Services recheck current membership/session; caller-submitted tenant IDs cannot substitute scope. Cross-tenant account/root/deletion and composite FK tests deny access. |
| OAuth | Random hashed state bound to tenant/user/auth session/session digest/account/generation/privacy/redirect with ten-minute one-use expiry. Strict raw query parsing rejects duplicates, malformed/unknown/array inputs, replay, mismatch and substitution. State consumption precedes the one-use code request; activation requires identity and grant validation. |
| Credential isolation | Sodium XChaCha20-Poly1305 with random nonce, versioned private keyring and tenant/account/provider/app/kind/generation/key AAD. Tamper/scope-swap/missing-key and real key-rotation tests pass. Ciphertext and tokens never enter job payloads, HTML or logging. |
| Refresh/concurrency | Separate credential/grant expiry; 24-hour minimum long-token age; per-account reservation; generation compare-and-swap under account lock; pending revalidation before active. Two real PHP processes reserve one refresh. Reauthorization refuses a still-reserved call; key rotation fences old work without releasing an in-flight reservation early. |
| SQL/publication | Native prepared parameters in infrastructure repositories; application-owned short transactions; organization/account/job ordering. Lease/hash/generation/epoch/grant/root/checkpoint/reservation checks precede publication. Final lease validation rolls back effects/checkpoint/continuation. No transaction spans the fake request. |
| Provider input | Fixed host/path and typed string IDs; projected fields; strict schema/time/link/relationship validation; bounded cursor/body/JSON structure/record counts. No `paging.next` fetching, credential logging, arbitrary proxy, discovery or public-history lookup. Malformed, oversize, timeout, throttle and safe-error tests pass. |
| Privacy races | Parent blocks and account/source epochs deny reads immediately. Suppression is checked before ingestion. Deletion inside an actual fake request and late lease/credential/grant/privacy/root changes cannot publish. Token/grant/state removal and bounded dependency purge are tested, including more than 100 children. |
| Restore | Independent signed version-2 ledger with exact target namespace/HMAC references, bounded offset replay and absent-account/tenant suppression. Tampering and extra-content fields are rejected. Replayed primary-complete intents generate new epoch purge work rather than trusting a restored completion flag. |
| UI/logging | Escaped source text/handles and safe error categories; no credentials/provider bodies in HTML. Source XSS fixture remains inert. Callback outcomes redirect with no-referrer and no assets. Existing logger/session/CSRF/private-root regressions remain passing. |
| Resource use | One provider unit/invocation, four-second persistence reserve, finite cURL contract bounds, one-MiB/100-record page cap, ten-page pass cap, reservation/finite backoff and bounded purge. Process-kill tests recover claimed/prepared/uncommitted publication without partial local ingestion. |

Review included all new PHP/SQL and shared queue/privacy changes, explicit composition, migration immutability, source provenance, expiry/read predicates, dependency ordering, stale-worker recovery, casing and deployable paths. Application services and HTTP controllers contain no SQL statements; named infrastructure records encapsulate database operations.

## Explicit limits before live use

- No live transport exists. Future TLS, redirect, header/body streaming, compression/decompression and actual network-timeout behavior require adapter implementation and target-host tests under separate approval. A fake timeout/byte test does not verify cURL execution.
- All G1–G6 remain open. App/business purpose, actual grants/access tier, Meta review, callback signing/mapping, permitted retention and external processing are not established. Discovery, OpenAI and individual profiling remain unavailable.
- Fixture disconnect permanently suppresses its scope. Safe scope release/recovery, general multi-account/multi-user operation, app-wide quota coordination, real callback ingestion and suppression-key rotation/migration are not implemented. Current key rotation covers token/cursor encryption contexts; ledger key recovery/rotation needs operator planning.
- A signed ledger cannot detect removed whole records by itself. Independently protect completeness/position/checksum, signing keys and recovery copies. The local replay drill does not certify encrypted hosting backup restore, backup erasure, provider logs or downloaded exports.
- Minimal account scope/epoch and deletion HMAC proof deliberately outlive source content so restored backups cannot revive it. Their eventual lawful lifecycle requires review against the actual backup horizon; no blanket indefinite-content retention is claimed.
- Pre-existing installation log/session scans visit only 500 directory entries without a progress cursor. The new DB sweeps progress in chunks, but full-installation retention at production volume is not certified.
- Only Windows/PHP 8.5.8/MariaDB 12.3.3 were available. Actual PHP 8.5.12/MariaDB 10.11/Linux/cPanel, HTTPS/referrer/access-log behavior, cron/signals/permissions and full 100-post/10,000-reply capacity remain staging checks.

No known failing local security/regression test remains at the reported candidate. This statement is limited to the tested synthetic boundaries. The next step is review of the concrete candidate and [staging plan](THREADS_STAGING_TEST_PLAN.md); it does not authorize activating a provider, installing dependencies or deploying.
