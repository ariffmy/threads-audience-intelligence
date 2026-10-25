# Phase 3A — Decision and completion report

Completed: **25 October 2026 (Asia/Kuala_Lumpur)**. Phase 2 baseline: `dfba94e`. Scope: official-documentation research and future integration design only. Application code, database migrations, dependencies and infrastructure are unchanged. No live API requests, credentials, production databases or deployment were used.

## 1. Decision

**The unrestricted objective of identifying each replier's frequently discussed topics across their posting history is not a supportable product promise.** There is no documented stable reply-author user ID in the inspected reply contract; public discovery has target/access limits and a competitor-analysis purpose restriction. An owner granting OAuth does not establish consent from reply authors to build personal profiles.

**A narrower owned-discussion product is conditionally feasible:** retrieve the authorized owner's posts and accessible replies for managing their Threads presence, then, in a separately approved analysis phase, summarize recurring non-sensitive topics in a bounded discussion sample using suitably aggregated, de-identified output. This is a recommended change in product direction, not an assertion that the owner has accepted it or that Meta has approved this app.

The current [permissions reference](https://developers.facebook.com/documentation/development/permissions), updated 19 October 2026, limits the relevant Threads permissions to business apps. Its reply-analytics allowance requires aggregation and de-identification/anonymization that prevents re-identification; its discovery allowance is for competitor analysis. A personal installation with one administrator does not by itself settle the provider's business-versus-consumer classification. Purpose fit must be resolved before real-data collection.

Proceed only to a decision about a **fixture-first Phase 3B**. Do not enable discovery, individual interest profiles or external AI processing. All G1–G6 gates remain open; not all are prerequisites for fixture development, and optional G4/G6 cannot be bypassed by passing baseline gates.

## 2. Deliverables and evidence

| Document | Contents |
| --- | --- |
| [PHASE3A_API_RESEARCH.md](PHASE3A_API_RESEARCH.md) | All twelve requested research areas; official URLs, page update dates and verification date; evidence versus unknowns; gate disposition. |
| [PHASE3A_CAPABILITY_MATRIX.md](PHASE3A_CAPABILITY_MATRIX.md) | Feature, endpoint, HTTP method, permissions, fields, restrictions, classification and official references. |
| [PHASE3A_INTEGRATION_DESIGN.md](PHASE3A_INTEGRATION_DESIGN.md) | Future PHP/PDO/MariaDB/cron architecture, OAuth, credentials, request budgets, queue adaptations, ingestion, retention and deletion. |
| [PHASE3A_PRODUCT_FEASIBILITY.md](PHASE3A_PRODUCT_FEASIBILITY.md) | Reply-only evidence, public-profile/history limitations, permitted-purpose issues, aggregate fallback and disabled features. |
| This report | Decision, proposed next increment, outstanding decisions and completion boundary. |

Research read current public Meta developer pages, terms/policies and the changelog. Direct web-tool access sometimes failed; public browser-rendered official pages supplied the primary evidence. Meta's official Postman collection was secondary and explicitly warns it may lag the changelog. No inability to retrieve a page was classified as proof of an unsupported capability. Verification of documentation does not mean a token, grant, app approval or runtime behavior has been tested.

## 3. Verified and unverified capabilities

### Verified in official documentation

- Threads authorization-code flow; exact registered redirects; server-side code exchange; one-hour short token, sixty-day long token, and refresh of an unexpired long token at least twenty-four hours old. Permission/data-access expiry is distinct from token expiry.
- App-scoped own profile and own posts; direct replies and root-level flattened conversations; documented cursor paging and nullable/visibility-dependent reply fields.
- Public exact-username profile lookup and public post retrieval exist with `threads_profile_discovery` plus basic access. They require public profiles with at least 100 followers, have restricted Standard Access and a published 1,000-request/user rolling-day limit. They remain disabled for this product's proposed replier enrichment.
- Same-app token debugging, general impression-based rate accounting, permission-specific App Review evidence and generic Meta deletion-callback requirements. See the matrix for exact contracts and source links.

### Unverified or unavailable as a guarantee

- Actual app type, purpose eligibility, tester roles, granted scopes, Advanced Access, business verification, publication and review status.
- A stable reply-author user ID; complete visibility/history for every replier; mapping handles to immutable identities. The own-user history endpoint cannot be repurposed for arbitrary reply authors. Private or below-threshold discovery targets contradict the documented restrictions.
- PKCE requirements/support in this flow; exact actual Threads deauthorization/deletion signature, identifier namespace, delivery/retry semantics and tested mapping to stored accounts.
- Reply endpoint date/limit parameters not documented in the inspected reference; precise usage-header/error/subcode behavior, shared discovery quota bucket and measured capacity.
- Permission to build identifying audience profiles or send text to OpenAI. No analysis quality, provider processing arrangement, target-host runtime or production deletion/restore evidence was established in Phase 3A.

## 4. Permissions and OAuth recommendation

Request only `threads_basic` and `threads_read_replies` for the proposed owned-discussion baseline, after confirming purpose eligibility. No publishing, moderation, insights, keyword search or discovery permission is necessary for it. Optional future capabilities need their own allowed purpose, approvals and evidence; access approval cannot broaden a permission's allowed use.

The future flow starts with authenticated POST+CSRF; creates one-time, expiring, session/organization-bound random state; uses the exact configured HTTPS callback; atomically consumes state; and exchanges the code server-side without a DB transaction held over the request. Persist the pending token encrypted, then activate through bounded exchange/debug/profile jobs only after identity, grants and gates match. Keep tokens, codes and callback queries out of logs/browser storage. Reconnect must never silently change account identity.

Use sodium authenticated encryption with random nonces, versioned external keys and tenant/account/app-associated data. Refresh before actual expiry through an account reservation and credential-generation compare-and-swap; track grant/data-access expiry separately, including private-profile reauthorization. Use only fixed allowlisted provider hosts/paths, verified TLS, no redirects, bounded bodies/timeouts and safe errors. The detailed design includes a sequence diagram and proposed application budgets; none of these controls was implemented in this phase.

## 5. Collection and security limitations

Collection must prove roots came from the granted owned-post list. Public readability, a pasted ID, a local administrator role or a username does not grant provider authority. Preserve source/window/field/version/collection evidence and unknown values; label a capped, inaccessible or incomplete pass. Do not sum distinct handles across windows or describe accounts as people.

The existing queue is an appropriate foundation but its synthetic job schemas, organization-only fences and purge/ledger contracts are insufficient for real content. Before real use, extend account/source privacy versions and composite FKs, provider request reservations, bounded preflight deferral, atomic page/checkpoint/continuation ingestion and dependency-aware purge/restore. No network call may hold a DB transaction. Proposed limits remain 25 seconds/96 MiB with one network request per invocation, a persistence reserve and bounded responses.

Immediate blocks must stop reads and publication; late in-flight results must fail their lease/generation/privacy/source checks. Retention covers sources, derivatives, files and backups; current durations are provisional upper bounds, not a Meta allowance. Replay an independent complete deletion ledger before a restored system becomes accessible. Primary deletion cannot erase provider logs or already downloaded exports. Resolve the current bounded file-scan progress limitation before claiming retention completeness at production volume.

## 6. Recommended Phase 3B scope — not authorized

1. Confirm the business-purpose fit and whether to accept the owned-discussion fallback. Preserve one administrator/organization and begin with one account; no public registration or organization interfaces.
2. Implement only approved local account/credential/grant/OAuth contracts with fake transports and sanitized fixtures. Add new immutable migrations only under that future approval; preserve the Phase 2 baseline.
3. Extend the queue, budgets, tenant/account fences, retention/deletion ledger and restore paths before registering real collection handlers. Implement owned-root, reply/conversation pagination and idempotent ingestion with explicit incomplete outcomes.
4. Verify state/account substitution, token tampering/rotation, refresh races, quota concurrency, cursor/replay behavior, cross-tenant isolation, deletion during requests and full purge/restore suppression. Run PHP lint and focused regressions, MariaDB 10.11 concurrency/crash/upgrade tests and target-like resource checks.
5. Produce a local release candidate and a concrete redacted contract-test plan. Real tester calls require separately applicable data/cost authorization, resolved G1/G2/G3/G5 prerequisites and callback/deletion evidence. No live calls are authorized by this report or by an eventual fixture-only approval.

Exclude public-profile discovery/history enrichment, person-level interests, OpenAI, semantic analysis, dashboards/reports beyond import operations, publishing/moderation and deployment. Later analysis requires its own scope and G3/G6 closure. If the purpose cannot fit the current permissions, stop the integration and revise the product; do not add scraping or alternative-search workarounds.

## 7. Outstanding decisions and gates

| Decision/evidence needed | Responsibility and consequence |
| --- | --- |
| Is this an eligible internal business presence-management app? Is the fallback acceptable? | Owner, with Meta clarification/review where needed. A consumer-app or disallowed-purpose outcome stops live integration. |
| Which actual Threads app, roles, account and access tier will be used? | Owner supplies configuration through secret-safe channels in a later authorized phase; verify required publication/review/business requirements. No credentials requested or used now. |
| What notices, consent and processing arrangements apply? | Owner resolves purpose/profiling and any processor sharing. Default: no identifying profiles and no external-model transfer. |
| Which pilot roots/window, local request caps, retention and backup lifecycle are approved? | Owner and implementer set measurable bounds. Proposed ten roots/thirty-day window and refresh timing are local design choices, not Meta entitlements. |
| What is the actual Threads callback signature and account-ID mapping? | Implementer proves it in an authorized tester contract; generic Facebook ASID examples cannot establish the mapping. Resolve before live ingestion. |
| What are actual read telemetry/errors, cursor behavior and grant expiries? | Implementer records redacted contract results after authorization. G1/G5 remain open; source specifications alone are insufficient. |
| Does the implementation satisfy PHP 8.5/MariaDB 10.11/Linux/cPanel limits and real-data restore/deletion? | Implementer proves target compatibility, resources, upgrade/recovery and privacy races before staging/release approval. |
| Should any future discovery feature exist? | Separate product decision under the documented competitor-analysis purpose; no approval to enrich ordinary repliers is inferred. G4 stays disabled. |

**G1 contract, G2 access, G3 purpose/privacy, G4 discovery, G5 quotas and G6 AI all remain open.** The research register records what evidence advanced and what still needs proof. No gate was closed merely because documentation became readable.

## 8. Verification and stopping boundary

Phase 3A verification completed: 67 local Markdown file links across the twelve changed/new documents resolved; code fences were balanced and no replacement characters were found; `git diff --check` passed. Git inspection found seven modified Markdown planning/index/instruction files and the five new deliverables, with no paths outside that documentation boundary. HEAD remains `dfba94e`; all fifteen tracked SQL files and application files are unchanged. No commit or push was made.

The Phase 2 report's 86 passing tests and prior lint are historical; no new application, DB, concurrency or live-provider test result is claimed. This phase needs no dependency or schema execution.

Phase 3A is complete as a planning/research package. Phase 3B has not begun. No push, publish, deployment, production access or paid/live API activity is authorized by this report. Stop here until the user explicitly approves a further scope.
