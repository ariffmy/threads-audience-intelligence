# Phase 3A — Future Threads integration design

Design date: **25 October 2026 (Asia/Kuala_Lumpur)**. Baseline: `dfba94e`. **Proposal only:** no routes, classes, schemas, migrations, credentials or infrastructure were created in Phase 3A. Implementation requires explicit Phase 3B approval; real requests need separate satisfied gates and data authorization.

Official protocol evidence is indexed in [API research](PHASE3A_API_RESEARCH.md). This document's timeouts, schemas, local caps, route names and job names are application design decisions, not claims of Meta requirements or implemented behavior.

## 1. Preserve the existing system

Retain native object-oriented PHP 8.5, constructor injection, explicit composition, PDO native prepares, MariaDB 10.11/InnoDB/utf8mb4, Bootstrap/vanilla JS and finite PHP CLI/cPanel cron. No framework, Node runtime/build, Redis, persistent worker, container or dependency addition. Serve only `public/`; existing administrator/session/CSRF controls remain the local access boundary. Keep one administrator and one organization; begin with one authorized account pilot while retaining composite tenant/account storage.

Use the existing queue's at-least-once delivery, dispatch keys, lease digests/generations and publication transaction. Preserve its synthetic tests. Provider code prepares a bounded result outside DB transactions, then publishes through stronger account/source fences inside the same transaction as checkpoint/continuation/success. No PDO lock crosses a network call.

### Baseline gaps that Phase 3B must explicitly address

| Current Phase 2 contract | Future extension needed before live data |
| --- | --- |
| Job allowlist accepts only demo and retention schemas. | Review/version explicit provider-job reference schemas; continue rejecting arbitrary URLs, IDs-as-authority, bodies and credentials. Unknown versions fail closed. |
| Organization epoch; no account/source FK or fence. | Add real account parents first; composite account FKs and account/grant/credential/source/checkpoint versions; consistent account lock order before job publication. |
| One-second exit reserve; up to twenty local jobs/run. | Add one shared network-call allowance/run and a four-second persistence reserve; check remaining wall time before each outbound call. Existing local jobs can retain their bounded behavior. |
| Four generic failure codes; no provider gate/blocked business run. | Extend safe codes and provider eligibility/defer operations. Keep current queue states initially; put reconnect/policy-blocked status on the account/import/capability, cancel the relevant job and redispatch only after revalidation. |
| Attempts increase at claim, regardless of external work. | Distinguish queue delivery attempts from actual provider attempts. Rate/policy preflight deferrals must not consume provider execution attempts or cause claim loops/starvation. |
| Retention handler treats non-maintenance jobs as synthetic and deletes only demo effects/attempts before jobs. | Restrict synthetic cleanup by known type and implement the real FK dependency purge. Merely registering provider handlers would leave an unsafe/incomplete cleanup contract. |
| Ledger only recognizes permanent `synthetic_scope` intents; artifact creation/replay has local/test guards. | Version account/content deletion intents and identifier namespaces, purge manifests, restore replay and retention lifecycle. Do not relabel the existing synthetic block as real account deletion. |
| 500-entry installation file scan has no persistent progress cursor. | Resolve or bound demonstrated volume/retention before sensitive deployment; do not claim complete installation retention from a successful demo sweep. |

These are planned extensions, not fixes applied in Phase 3A. Any future migrations must be new numbered checksummed files, with upgrade/partial-DDL recovery and rollback compatibility; existing fifteen SQL files stay immutable.

## 2. Module and storage responsibilities

| Module/store concept | Responsibility and contract |
| --- | --- |
| Access/application services | Resolve authenticated membership and explicit account grant; verify permitted purpose/capabilities before enqueue, read, pre-call and commit. Never trust tenant/account IDs from the browser alone. |
| Threads adapter | Fixed-endpoint cURL requests, strict response projection, token envelope and safe provider-error translation; fake transport injectable for all ordinary tests. |
| Account/credential/capability records | App/environment namespace and remote ID, connection status, exact scopes, verified access level/purpose evidence, expiry metadata, credential generation and account privacy/data versions. Requested/granted/tested/approved are separate facts. |
| OAuth state | Random-state hash, organization, user, active auth-session binding, operation/expected account, registered redirect key, expiry and atomic consumed marker. |
| Import run/checkpoints | Explicit account, owned roots, half-open UTC window, endpoint/projection/API version, page/cursor/pass/version, scope/permission/policy snapshot and incomplete/blocked outcomes. |
| Retained content/provenance | Remote media IDs as strings, root/parent references, nullable text/username/timestamps, first collection/last verification, content hash/source version, expiry, field availability and evidence references. |
| Author observations | Minimal account-local observations for displaying authorized replies; nullable stable ID only if a future verified field supplies it. Handle observations are provisional, not a global user directory or interest profile. |
| Provider request/limit records | Atomic app/account/capability request reservations, safe result metadata and `next_allowed_at`; no raw query URLs, tokens, reply bodies or provider errors. |
| Privacy/independent ledger | Immediate account/source blocks and epochs, suppression, dependent manifests, bounded purge, independent intent completeness/checkpoint proof and restore replay before access. |

Use the relevant logical entities in DATABASE_DESIGN rather than creating an unrelated parallel schema. In a later approved migration, give every account-scoped child a composite `(organization_id,threads_account_id,...)` FK to its actual parent. Include Meta app/environment in remote identity namespaces. Avoid source rows depending on ephemeral job rows; expiring queue history must not accidentally erase or orphan source provenance.

Secrets remain outside the web root. Remote IDs are never floats; decode oversized JSON integers as strings and normalize exact integer/string IDs without rounding. PDO binds data and allowlists identifiers/order clauses. UTC timestamps and explicit nulls are mandatory. Metadata limits are enforced before encoding and in DB checks; preserve the queue's 2-KiB reference-payload ceiling. Proposed token-field cap is 8 KiB: reject/report an unexpected larger token, never truncate it; this is a local safety cap, not a documented Meta token maximum.

## 3. OAuth and account activation

T03 documents the current authorization URL and form-body exchange; T10 supports checking validity, granted scopes and separate expiries. State is mandatory locally even though optional at Meta.

1. A signed-in administrator submits a CSRF-protected connect/reconnect action. Validate current owner membership, organization state, allowed business purpose and any target account before issuing state. No provider grant follows merely from being a local administrator.
2. Generate 32 random bytes of opaque state, store only its hash with the active auth session/user/organization, operation, expected account (if reconnecting), canonical redirect key and a proposed ten-minute TTL. Use exact configured HTTPS callback URIs, never a request-supplied return URL. Request only basic/read-replies scopes.
3. Redirect the browser to the documented `https://threads.com/oauth/authorize` with public client ID, required parameters and state. The browser receives no app secret or access token. Provider OAuth is not Facebook/Instagram Login substituted by assumption. PKCE behavior remains a pre-live contract question.
4. At callback, bound input sizes and reject duplicate/malformed/error/code combinations. Verify the same active session, identity, organization and intended operation. Atomically consume unexpired state under a short DB transaction before exchanging the code. Handle cancellation without trusting/rendering raw provider descriptions. Replays/mismatches expire the flow.
5. Release DB/session locks, then perform one bounded server-side code exchange. An ambiguous timeout can consume the one-use code; do not blindly replay it. Restart consent when the outcome cannot be established safely. Provider codes' one-hour lifetime does not extend our state TTL.
6. Encrypt the short token immediately in a pending account/credential context; persist the returned app-scoped user ID as unverified pending identity and dispatch credential-activation work atomically. Do not mark the account active or ingest content yet. If no authoritative short-token expiry is returned, label a conservative documented-TTL estimate explicitly until debug metadata supplies it.
7. Separate finite jobs exchange the short token for a long token, inspect it with same-app token debugging, and resolve `/me`. Each job gets at most one outbound call per invocation. Activation requires the resolved user ID to match the exchange result and reconnect target, valid token/data access, expected basic/read-replies grant and cleared purpose/access gates. Never silently reassign an existing account to a different returned identity or another tenant.
8. Redirect the callback immediately to a clean local URL after handling, with no token/code/state in the destination, HTML, browser storage, logs or error page. Use no-referrer specifically on this response and verify upstream callback-query log redaction on staging. A callback page must not load assets while its credential-bearing query remains active. The existing ordinary form referrer policy need not be weakened.

Proposed pending credential jobs receive priority below deletion but above ordinary imports and a short finite activation lifetime, well inside the short token's expiry. If cron cannot activate in time, purge the pending credential and require consent again. Tokens belong in the encrypted credential store, never job JSON. Failed/abandoned OAuth state and pending records have finite cleanup.

```mermaid
sequenceDiagram
    participant Owner
    participant Web as PHP web application
    participant DB as MariaDB
    participant Meta as Meta Threads
    participant Cron as Finite CLI jobs
    Owner->>Web: Authorized POST connect + CSRF
    Web->>DB: Store expiring session-bound state hash
    Web-->>Owner: Authorization redirect
    Owner->>Meta: Consent in provider window
    Meta-->>Web: Callback code + state
    Web->>DB: Validate and consume state
    Web->>Meta: One bounded code exchange outside DB transaction
    Web->>DB: Encrypt pending token + dispatch activation
    Web-->>Owner: Clean redirect; connection pending
    Cron->>Meta: Long-token exchange in one invocation
    Cron->>DB: Fenced credential update + continuation
    Cron->>Meta: Debug and profile in separate invocations
    Cron->>DB: Verify identity, scopes, expiry and gates before active
```

Job names/routes in this sequence are proposed application concepts. No callback, role, reviewer credential or OAuth grant was configured during Phase 3A.

## 4. Token envelope and refresh

Use native sodium authenticated encryption, preferably XChaCha20-Poly1305 where verified available, a random nonce and versioned key IDs. Associated data binds environment, Meta app, organization, internal account, credential kind and generation; swapping ciphertext across scopes must fail. Keep keys outside DB/public/storage backups, separate from the session application key. Define bounded key rotation, old-key backup recovery and missing-key fail-closed behavior. Verify both CLI/web extensions and host permissions before activation.

Track token issuance/expiry, data-access expiry, last verified scopes, privacy status and credential generation separately. Prefer actual returned/debug timestamps; never silently invent an unlimited grant. Propose refresh at day 45 or earlier expiry-driven safety margin, only after the documented 24-hour minimum and before actual expiry. This is a local schedule, not a Meta requirement. Refresh a private-profile token when eligible, but still require renewed consent at its non-extended permission expiry (T02/T04).

Under a short account transaction reserve one refresh operation for the current generation, commit, call Meta, then compare-and-swap the encrypted replacement/expiry using the current lease, grant/privacy and credential generation. Use an explicit account refresh reservation so two differently keyed jobs cannot refresh simultaneously. Handle unknown remote outcomes conservatively; do not let a stale result overwrite a newer credential. Expired/revoked permission blocks collection and requires the documented reconnect path, not infinite refresh retries. Every read capability remains conditional on current validity.

Meta documents GET query parameters containing secrets for long-token exchange. Keep those requests exclusively inside the fixed server adapter; redact full URLs/query values in every application/proxy diagnostic path. Do not replace the published method with an assumed POST or claim bearer-header equivalence without a verified contract. No verbose cURL tracing or raw transport logging.

## 5. Fixed transport, deadlines and errors

Initial read endpoint allowlist: own profile, own post list, proved-owned root reply/conversation and token debugging. Auth exchange/refresh have separate fixed unversioned paths. Discovery, public-media import, writes, keyword search, media fetching, webhooks and OpenAI are off. Choose graph.threads.com from current examples; graph.threads.net is a documented alternative, not an invitation to follow redirects. TLS host/peer verification stays on, redirects off and URLs are constructed from typed parameters. Never fetch returned permalinks, profile pictures or arbitrary `paging.next` URLs.

| Proposed application bound | Rule |
| --- | --- |
| Whole CLI invocation | Keep 25-second deadline and 96-MiB peak soft ceiling beneath host 30-second/128M limits; include startup/DB/persistence overhead in measurements. |
| Outbound calls | One provider call per invocation, including refresh/debug calls; local purge work can use remaining budget. This requires a shared network budget beyond Phase 2's ordinary job cap. |
| HTTP time | Connect at most 3 seconds; total at most `min(20 seconds, remaining wall time minus 4 seconds)`. Do not start when a useful finite call plus persistence cannot fit. |
| Response bytes | Proposed 1-MiB compressed/decoded-response safety policy; cap received bytes and either disable compression or bound decompression too. Abort oversize bodies before unbounded decode. Bound header bytes as well. |
| Pages | Start at 25 requested items where a verified endpoint has a limit parameter; media-reply page-size controls remain unverified. Do not truncate transport JSON and call it a successful page. |
| Inputs | Bounded windows, selected roots, metadata, cursor references and strict field projections; no whole-corpus load or unbounded retries. |

For OAuth HTTP callback exchange, apply the same remaining-time reserve to its single request; activation work follows via cron. Actual callback acknowledgement requirements and complete-command latency need staging proof. No unlimited execution settings, worker retry sleeps or persistent daemon.

Error classification combines HTTP status, parsed safe schema, known provider codes and account state. HTTP 200 can still carry unusable data; HTTP 400 alone is not a complete diagnosis. Provider code/subcode and read-usage-header mappings were not fully verified in Phase 3A and must be contract-tested before live activation.

| Outcome | Planned handling |
| --- | --- |
| Transport timeout/temporary service failure | Finite retry with exponential backoff/jitter; unknown request outcome retained in safe request metadata. No partial local page publication. |
| Throttle | Respect a valid bounded retry delay when supplied, update shared `next_allowed_at`, reserve future eligibility and stop/defer. Do not conflate writing quotas with read budgets. |
| Revoked/expired token or missing required scope | Block relevant account/capability, cancel dependent execution and require revalidation/reconnect; no automatic permission escalation. |
| Invalid input/unsupported field or schema drift | Safe terminal/review outcome; distinguish malformed response from an empty accessible collection. Do not retry the same poison request forever. |
| Local time/memory/byte exhaustion | Reduce only supported page/projection bounds or pause for review; finite reschedule. Persistent oversized replies are an explicit incomplete/unsupported unit, not silently complete data. |
| Policy/access/budget unavailable | Block the business import before outbound work. A local owner toggle is insufficient permission. |
| Changed source/epoch/lease/checkpoint after call | Discard the prepared response and roll back publication; replan only if still allowed. |

Persist only allowlisted error categories, HTTP status, safe provider trace IDs when appropriate, counts, timing/memory, correlation and next eligibility. No raw bodies, codes, secrets, complete URLs, usernames, prompts or reply text in operational logs. Unknown error variants stop for review rather than exposing raw messages or guessing a grant.

## 6. Quota accounting and queue adaptation

T01's impression-based formula and T05/T06 discovery cap do not establish current remaining headroom. T18's publishing-limit endpoint is not a general read counter and must not cause publishing scopes to be requested. Until actual Threads telemetry is verified, use conservative local request caps approved for the pilot, with both shared app/user and local tenant/account checks. No numerical local cap is represented as a Meta entitlement. Count ambiguous outcomes conservatively.

Before a network job is claimed for execution, check purpose, token/grant expiry, account block, capability, request reservation and `next_allowed_at` in a bounded transaction. Deferring a job for an unavailable window must not repeatedly claim it, consume provider attempt limits or starve another eligible unit. Preserve finite queue delivery limits separately. Reserve the permitted request atomically across competing workers, commit, call outside the transaction and reconcile safe usage metadata afterward.

Keep consistent lock order: organization, synthetic/real scope state, account, relevant provider reservation/budget rows in a fixed order, job, import/checkpoint/content rows by ID. Extend the existing claim/publication contract to acquire the account before the job; do not acquire it for the first time inside a callback that already owns the job lock. Shared app quota updates must never acquire these locks in reverse order. Maintain the current short MariaDB statement/lock budgets and bounded deadlock recovery, measured on 10.11.

Only eligible, unblocked work is dispatched/scheduled. Refresh/deletion get higher priority; missed slots coalesce as in Phase 2. Initially retain explicit organization invocation and one-account pilot, avoiding an unapproved multi-tenant UI/coordinator. At-least-once network requests may repeat; idempotent local ingestion does not promise exactly-once remote execution or quota use.

## 7. Collection, pagination and provenance

1. After all live gates pass, obtain roots only from the connected user's owned list, with matching root ownership evidence. Initial proposed pilot: manually selected at most ten roots and a thirty-day requested window; limits remain owner decisions, not provider maxima. Unsupported/absent ownership evidence blocks that root. A public media ID, discovery result or local role never substitutes for ownership.
2. Snapshot current organization/account privacy epochs, credential generation, grant/policy/capability revisions, root source version and checkpoint version before the call. Recheck these immediately before outbound work and during fenced publication. Check suppression at import as well as before commit.
3. Select a documented retrieval mode: direct replies or root conversation. Normalize `root_post`/`replied_to` object IDs without inventing missing parents. Treat both modes' appearances of the same media ID as one retained object. A reply ID is not an author ID.
4. Use only documented cursor parameters, never both `before` and `after`. Persist an encrypted/opaque cursor reference in the checkpoint, not a full token-bearing next URL or payload body. Detect repeated/non-advancing cursors with bounded state. Pin endpoint/projection/version per pass.
5. Owned/public lists have documented date/limit controls; the media-reply contract inspected does not. Apply reply window filtering locally, discard out-of-scope bodies rather than retaining them, disclose overfetch and cap pages/time. Missing timestamps are an unknown-time category, not today's date. Reaching a local cap yields partial status. Cursor failure starts a bounded new pass/overlap; it does not prove deletion.
6. Use a unique content key scoped to organization/account/remote media ID; upsert hashes and increment source versions on changed current text/context. A page's writes, progress and deterministic continuation dispatch commit atomically after all fences pass. Existing remote ID replay creates no additional counts. Changed root/parent context must invalidate future dependent interpretations.
7. Save collection time, requested/actual window, source/endpoint/API version, field projection, grant/policy evidence, provenance, sample/missingness and expiry. Do not retain complete raw provider responses or old raw versions indefinitely. Text attachments/media not requested by the baseline are explicitly outside text coverage.
8. A pass is exhausted only within the currently accessible requested scope. Missing pages, absent author names and omitted/deleted fields are not zeros, complete histories, lost-interest signals or evidence of a private identity. A missing item from a page never independently proves provider deletion.

Minimize initial projections to ownership/root context and reply text/time/tree/availability fields needed for management and future approved aggregate analysis. Do not collect biographies, follower counts, pictures, private identities or historical public-post samples by default. Handle-only observations remain provisional and account/window-local. No inferred author linkage across changed/reused handles, accounts or tenants.

## 8. Retention, revocation and deletion

Current 90/7/30/90/30-day source/export/log/audit/backup values are provisional upper bounds, not permission to retain data that is unnecessary or must be removed earlier (T15 §3.d). Record the applicable purpose/policy revision and earliest expiry; ordinary re-fetch must not reset `first_collected_at` just to keep a body forever. Future derivatives and exports expire no later than their evidence. No anonymous-data exception is assumed for retained text, usernames or pseudonymous hashes.

Local disconnect requires authenticated POST+CSRF. Under the common scope locks, immediately block the account, increment its privacy/data versions, revoke local capabilities and cancel pending work. Purge credentials and applicable sources/derivatives in bounded dependency order. A remote request already in flight cannot be recalled; its late result is rejected. Account revocation signals and definitive invalid-grant responses use the same path. Transient transport errors alone are not proof of revocation. No unverified Graph permission-revocation endpoint is proposed; distinguish local disconnect from provider-side consent removal.

Real source/account/subject removal needs a versioned independent deletion intent before primary tombstoning, exact identifier namespace, keyed suppression references and a complete manifest through evidence/derivatives/files/queue/request records. Deleting one source increments its containing account privacy epoch so cached account output fails closed. Preserve the organization epoch for organization-wide changes; account fences must protect narrower changes. Privacy and in-flight publication must acquire the same ordered locks and versions.

The purge must derive and test the actual FK graph: invalidate/block derivatives/downloads, remove dependent files/manifests and results if present, provider request references, source observations/content/author observations/checkpoints, and credentials/grants as appropriate before parents. Never let today's synthetic retention handler delete real job parents without those dependencies. Physical progress is chunked with finite retries and audited status; primary completion, backup expiry and provider-controlled copies are separate states.

### Provider callbacks: verified generic protocol, unverified Threads mapping

Threads setup lists deauthorization/deletion callback settings (T14); T17 describes a generic Meta POST signed request and JSON `url`/`confirmation_code`. Future routes must be distinct narrowly authenticated provider callbacks, not ordinary mutations exempted from all protection. Human connect/disconnect actions keep session/CSRF/Origin checks; provider callbacks require the verified provider signature contract instead of a human CSRF token. No global CSRF bypass.

For the generic signed protocol, plan strict body/base64url/JSON bounds, expected algorithm, constant-time HMAC-SHA256 verification over the encoded payload, appropriate configured signing-secret version and event-time/replay validation. Do not copy the documentation's minimal sample as a complete production validator. **Which app secret, identifier namespace and delivery/retry contract actually apply to Threads remains a live contract gate.** Never match a Facebook ASID to a Threads account by assumption or username.

Once mapping is verified, durably deduplicate intent, block the precise authorized target, enqueue purge and return the required opaque confirmation/status URL quickly, without synchronous whole-corpus deletion or secret/user identifiers in the URL. A GET status route only reads minimal non-identifying progress. Unknown/unmapped identifiers cannot trigger another account's deletion or a false completed-erasure claim. Actual acknowledgement timing, revocation payload/method and ID mapping require staging proof before live ingestion is enabled. General Threads reply/delete webhooks are optional future work, not assumed complete deletion notifications.

### Restore

Keep the independent ledger/signing-key recovery outside restored DB/runtime backups, with access controls and independently protected position/checksum evidence: HMAC alone does not detect removal of whole records. In an isolated restore, block web/cron access, replay complete account/source deletion/suppression history and retention, purge restored derivatives/files, revalidate credentials/grants and verify negative reads before reopening. Test partial replay/resumption and absent targets without reviving deleted scopes. Phase 2's synthetic replay guard/target is not a production-ready real-data restore procedure. Backups and minimal deletion-proof retention need a documented lawful lifecycle and measured erasure, not an indefinite exemption.

## 9. Required future verification

Phase 3B approval should first authorize fake transports and synthetic fixtures only. Preserve the 86-test regression baseline and add tests for state replay/expiry/session/account substitution; malicious redirects; token envelope tampering/scope swaps/rotation; refresh concurrency and ambiguous outcomes; grant versus token expiry; request reservations and pre-call deferrals; nested/duplicate pages, omitted names/text, unknown timestamps and cursor loops; cross-tenant/account FKs/reads; deletion during outbound calls and stale checkpoint/root versions; provider callback tampering/replay/namespace mismatch; complete source/derivative/file restore suppression; and error/log redaction.

Before any separately authorized real-data test, close purpose/app/access gates and verify the actual callback contract. Use a deliberately small authorized tester account/root set, prove exact grants/ownership/fields/paging/headers/errors, and record safe evidence without tokens or reply bodies. Measure full invocations on PHP 8.5.12, MariaDB 10.11 and target-like Linux/cPanel at 128M/30 seconds; no compatibility claim follows from the Phase 2 Windows/12.3.3 run. Rehearse migrations, partial-DDL recovery, encrypted backup/ledger restore and rollback before requesting deployment approval.

Phase 3B implementation and all live tests remain unapproved by this design.
