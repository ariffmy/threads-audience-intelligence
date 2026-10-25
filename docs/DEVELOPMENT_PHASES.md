# Development phases and testing strategy

Status: Phase 1 preserved; Phase 2 locally implemented/verified at `dfba94e`. Phase 3A research/planning explicitly authorized and completed on 25 October 2026. Phase 3B and later are not authorized.
Planning date: 24 October 2026.

## 1. Delivery rules

This package began as Phase 0. The user subsequently explicitly approved Phase 1 for personal use: one administrator, one organization, no invitations or organization-management interface, while retaining tenant-aware storage. A phase closes after deliverables, checks and documented limitations are reviewed. No phase implicitly authorizes a production deployment or changes to production infrastructure. Current evidence is in [PHASE1_COMPLETION_REPORT.md](PHASE1_COMPLETION_REPORT.md).

Use small, reviewable Git commits. Record architectural decisions in the relevant planning document rather than allowing code to silently diverge. No dependency installation is authorized by this plan. Any later new dependency must have a stated purpose, runtime/hosting impact, license and explicit approval before installation. Do not add Laravel, Node.js, Redis, Docker or Python without explicit approval.

API evidence gates G1-G6 are defined in [API_FEASIBILITY.md](API_FEASIBILITY.md). Foundation and synthetic tests can proceed after Phase 1 approval while external app approval is pending. Real collection waits for the required baseline/policy gates; optional public discovery remains independent and disabled by default.

## 2. Phase 0: technical plan (completed)

Deliverables: the six `docs/` planning files plus `AGENTS.md`, researched capability matrix, proposed schema and directory layout, security/queue design, deployment/rollback plan and test strategy.

Exit: the user approved Phase 1 with the personal-use revision. Phase 0 itself created only planning documents.

## 3. Phase 1: application foundation, identity and isolation

Prerequisite: satisfied by explicit user authorization. Local implementation and verification are complete with the target-host limitations recorded in the completion report; user acceptance is pending.

Deliverables:

- Initialize Git if still absent, add ignores and secret-free configuration examples; create proposed directories and native autoload/composition structure.
- Front controller, minimal router, request/response objects, shared exception handling, safe logging and environment validation.
- PDO connection factory, native prepared queries, CLI migration runner with checksums/lock, initial tenant/identity/retention/audit schema.
- CLI bootstrap for the sole administrator/organization; login/logout, password change, secure sessions, DB login throttling and CSRF. Invitations are excluded by the approved personal scope.
- Tenant context and action authorization contracts; account-grant enforcement finalized when account tables exist in Phase 3.
- Minimal server-rendered Bootstrap layout and accessible navigation shell; no external API features.

Acceptance: clean migration and rerun succeed; changed migration checksum is rejected; unauthenticated access and cross-tenant object requests fail; session fixation/replay tests pass; CSRF rejects absent/invalid tokens; bootstrap cannot create a second administrator/organization; secrets and PHP errors do not appear in HTML/JSON/logs; web root cannot expose private paths. No owner-removal interface exists. PHP lint and synthetic tests pass on PHP 8.5. Actual Linux, MariaDB 10.11 and cPanel validation remain explicit staging requirements rather than claims established by Windows/MariaDB 12.3 tests.

## 4. Phase 2: bounded queue, privacy foundation and operational controls

Prerequisite: satisfied by the explicit Phase 2 request of 25 October 2026, naming baseline `8be29a7`. Actual implementation/evidence is in [PHASE2_COMPLETION_REPORT.md](PHASE2_COMPLETION_REPORT.md). Phase 3A subsequently authorized documentation research only; no integration implementation is authorized.

Deliverables: jobs/attempts/scheduler tables, finite runner, atomic claim/lease fencing, retry/dead-letter handling, idempotent dispatch, checkpoints, scope blocking/cancellation, status UI and synthetic retention/deletion foundations. Synthetic handlers and a simulated external effect only; private-file lifecycle and independent signed-ledger adapters precede sensitive collection. Provider budget/request controls and provider-specific blocked/error states are deferred to Phase 3/4 to match the explicit no-API-handler Phase 2 request. Account/source suppression must be added with those stores before real data.

Acceptance:

- Run two competing CLI processes; each logical chunk publishes once. A manual second runner must be safe even without the file lock.
- Kill after claim, after a fake external request, after page writes and before continuation dispatch; leases recover and committed chunks do not double-count or lose continuation work.
- A stale/expired worker cannot commit; expired-lease recovery does not revive a cancelled job. Poison jobs stop after the configured attempt limit.
- A completed page/checkpoint/next-job transaction is atomic; injected DB rollback leaves none partially advanced.
- A deletion during an in-flight fake request blocks reads immediately and rejects the late response. Retention uses the same purge path.
- Cron entry/exit is finite, paces retries through `available_at`, and respects 25-second/96M soft limits. Scheduled slots deduplicate and missed runs coalesce.
- Queue/admin routes enforce tenant/account authorization, and job JSON contains only validated references and bounded metadata.

## 5. Phase 3: official Threads connection and collection

### Phase 3A: feasibility and design (documentation complete)

Authorized scope: current official Meta documentation, dated capability matrix, product feasibility/fallback, future integration design and decision report. Deliverables and limitations: [PHASE3A_DECISION_REPORT.md](PHASE3A_DECISION_REPORT.md). No source, migrations, live calls, dependencies or deployment. All G1–G6 remain open. Discovery's competitor-analysis purpose and reply-analytics de-identification restrictions qualify all later feature proposals, including Phase 6.

### Phase 3B: proposed fixture-first connection and collection

Prerequisites: new explicit implementation approval, Phase 2 acceptance and an agreed eligible product purpose. The recommended increment is fake-transport/synthetic owned-discussion integration, not public profile/history enrichment or identifying analytics. G1/G2/G3/G5 and actual callback/deletion evidence must be satisfied for the relevant real-data pilot, with applicable live-call authorization. No live request is authorized by this plan. External AI remains a later separate scope.

Deliverables: account/grant/credential/capability tables, state-protected OAuth, authenticated token encryption, refresh/reconnect/disconnect, explicit root ownership validation, owned-post imports, available reply/conversation imports, author observations and provenance, source reconciliation, safe pagination and import progress UI. Do not implement posting or reply moderation.

Acceptance:

- Record current official contracts, actual permissions and successful redacted staging tests; missing permissions disable relevant actions.
- OAuth rejects state mismatch, replay, wrong callback context and account reassignment; secrets stay server-side. Token refresh respects generation locking; revocation blocks work and initiates applicable privacy actions.
- Nested replies, duplicated pages/overlapping endpoints, missing authors, Unicode/emoji, edits, hidden/deleted/unavailable content, empty responses and cursor expiry behave explicitly.
- Imports resume after transport timeout and process death without duplicate content. No arbitrary URL or root ID can cause an unauthorized fetch.
- Distinct-account output separates stable IDs, provisional observed handles and unknown authors. No stable author ID is invented.
- End-to-end account/subject/content deletion removes sources and prevents a queued page from reimporting suppressed content.

## 6. Phase 4: evidence-based text analysis and reply topics

Prerequisites: Phase 3 acceptance, permitted-processing/transfer gate G3, and OpenAI quality/cost gate G6. Optional profile/public-post processing is excluded until Phase 6.

Deliverables: immutable analysis configurations, source fingerprints, runs/items/results/evidence/corrections, versioned sentiment/stance/style/topic schema, minimal cURL Responses client, strict output/evidence validation, per-item budgets, retry/abstention states, human review and effective correction logic. Record estimates before submission; no automatic unlimited reanalysis.

Acceptance:

- Model choice is evaluated with supported strict output and measured latency/cost; actual request schema is verified from official OpenAI Docs. `store: false` and approved data minimization are exercised.
- Refusal, incomplete response, invalid JSON/schema, unknown enum, fabricated citation, unsupported trait, prompt injection and context-limit cases cannot publish a valid result.
- A changed reply, root/parent context, stance target, schema/prompt/taxonomy/model version or truncation strategy creates a new fingerprint; unchanged requests reuse results.
- Text-level style never becomes person-level personality/demographic/health/identity inference. Sensitive topic/attribute candidates are filtered or sent to review without personal attribution.
- Budget reservations survive concurrent dispatch and unknown outcomes. Temporary errors retry within limits; billing/quota errors block. An ambiguous timeout discloses possible repeat billing.
- Analysis and evidence disappear/invalidate after source expiry/deletion; in-flight outputs cannot resurrect them.

Quality gate: build a human-labeled synthetic or appropriately authorized, de-identified evaluation set of at least 200 replies covering English, Malay, code-switching, short/emoji responses, sarcasm, questions, disagreement and insufficient context. Keep tuning and held-out subsets separate. Report per-language confusion matrices, sentiment/stance macro-F1, coverage/abstention and evidence accuracy. Proposed acceptance is macro-F1 at least 0.80 for sentiment and stance on adjudicated applicable held-out cases, 100% accepted evidence-reference validity, and zero accepted prohibited-attribute outputs in the adversarial set. Small-sample language results must be labeled; do not hide weak subgroups in an overall score. If unmet, revise prompts/model or narrow supported labels/languages with an explicit limitation before release; do not lower a threshold silently.

## 7. Phase 5: analytics dashboard and reports

Prerequisites: Phase 4 acceptance and stable metric definitions.

Deliverables: scoped aggregates/version pointers, dashboard filters/charts/accessible tables, discussion drill-down, observed audience summaries, topic evidence views, correction-aware metrics, private HTML/CSV reports, authenticated downloads and export expiry. Use pinned Bootstrap 5 and Chart.js browser files without a Node build.

Acceptance: displayed totals match independently calculated fixture results; duplicate joins and multi-topic labels do not inflate counts; distinct counts across periods are not summed incorrectly; dates/timezones and half-open boundaries are correct; unavailable/partial/stale/zero states differ. Reports show evidence, sample size, collection and analysis coverage, scope/model versions and limitations. XSS and CSV formula-injection fixtures remain inert. No user can read another tenant/account's report, and a source deletion immediately disables affected artifacts.

For a 100-post/10,000-reply fixture, representative indexed dashboards target under two seconds on target-like staging, each cron run under 25 seconds and peak PHP usage below 96M. Use EXPLAIN and measured latency/memory; if targets fail, reduce batch/query size or precompute. Do not claim interactive whole-corpus analysis under these limits.

## 8. Phase 6: optional public-profile and public-post samples

Prerequisites: baseline working without this phase, G4 verified, G3 confirmed for this purpose and any onward transfer, and explicit feature enablement by an authorized owner. Phase 3A establishes that current discovery allowed use is business competitor analysis. Generic replier-interest enrichment does not satisfy that requirement by obtaining a scope; this original phase proposal must be revised or remain unavailable unless a genuinely applicable permitted purpose is established.

Deliverables: separate verified discovery capability, exact-username lookup only when supported, permitted current profile snapshot, bounded authorized public-post sample, self-described profile-content summaries and sample topic aggregation. Persist provenance, eligibility/missingness, quota usage and sample windows. No external enrichment or scraping fallback.

Acceptance: current official access/fields/eligibility/quotas are documented and contract-tested; ineligible/private/unavailable results are handled without workaround. No arbitrary posting history is promised, profile images are not analyzed, missing stable IDs remain provisional, self-description is distinguished from verified fact, and sample topics do not become person-level sensitive attributes. Denied discovery leaves baseline dashboards usable. If access/purpose is unavailable, close the phase as unavailable with capability UI and no collector; it is not a baseline release blocker.

## 9. Phase 7: cPanel staging, hardening and operational release readiness

Prerequisites: baseline phases accepted; optional phase either verified/enabled or explicitly disabled. Production change still requires separate authorization.

Deliverables: deployment package/runbook, web and CLI PHP parity checks, cPanel document root/permissions verification, cron configuration proposal, resource/compatibility/security testing, backup/restore and rollback rehearsal, current policy/access evidence, operational dashboards and deletion monitoring.

Acceptance: use [DEPLOYMENT_PLAN.md](DEPLOYMENT_PLAN.md) checklist; rehearse fresh installation, release upgrade, failed migration recovery, code rollback, encrypted restore with deletion-ledger replay and key recovery in isolated staging. Verify 128M/30s constraints, correct CLI PHP binary, HTTPS/OAuth callback operation, native PDO settings, actual MariaDB schema and timezone behavior. Scan the deployable tree and Git diff for secrets. No known authorization/credential exposure/deletion-resurrection defect may remain.

Release evidence includes test results, approved purpose/grants, exact application/API/asset versions, measured resource usage, backup/restore proof, open limitations and rollback instructions. A real-data pilot is deliberately small and bounded by verified quotas and budgets. Owner approval is the final step after a concrete release candidate and staging evidence are available.

## 10. Cross-cutting testing strategy

| Layer | Approach | Required coverage |
| --- | --- | --- |
| Static/basic | PHP lint, coding review, migration/config checks, secret scan | PHP 8.5 compatibility, bounded inputs, explicit dependencies, no sensitive output |
| Unit | Native PHP test runner and fake clock/transport | Authorization matrix, metric math, state transitions, fingerprints, text validation, encryption tamper detection |
| Integration | Disposable MariaDB 10.11 schema; real PDO transactions | Tenant/account FKs, unique idempotency, expiry indexes, budget locking, purges, migration upgrade/recovery |
| Concurrency/fault | Two CLI processes, deterministic barriers and injected failures | Claims, leases, fencing, cancellation races, page/checkpoint atomicity, refresh race and aggregate publication |
| Provider contract | Sanitized official fixtures; gated staging calls | OAuth and scopes, paging/fields/errors, token refresh, deletion/revocation mechanism, strict OpenAI output |
| Security | Negative route/file/provider tests | IDOR, CSRF, XSS, injection, SSRF, session fixation/revocation, traversal, token leakage, download authorization |
| Analysis evaluation | Adjudicated held-out examples and adversarial text | Sentiment vs stance, abstention, language quality, evidence, unsupported inference and prompt injection |
| UI/accessibility | Browser/manual keyboard tests plus endpoint checks | Fetch polling, pagination, charts/tables, filters, narrow viewport, empty/partial/error states |
| Hosting/performance | Target-like PHP/MariaDB without daemon services | Memory/time ceilings, cron overlap/downtime, disk growth, dashboard queries and backup restore |
| Privacy | Trace source to every derivative and file | Retention sweep, provider/local request, revoked account, in-flight race, suppression and restored backups |

Default tests use synthetic data and fake providers; no real outbound calls, spend or production database is required for normal checks. Live test credentials are private, environment-scoped and never committed. Tests must refuse a database target unless its explicit test marker/name is present; do not use production-like names to guess safety. Do not introduce Docker/Python/Node to run tests. Test tooling additions follow the same dependency approval rule.

## 11. Definition of done for each implementation increment

The change satisfies its acceptance criteria, handles failures and authorization boundaries, includes meaningful tests proportional to impact, and updates migrations/configuration/docs as needed. Run focused checks once and broaden only when a failure or changed risk warrants it. Preserve unrelated user edits. Record what was tested and any inaccessible environment/API gate. A mock passing is not evidence of live API permission. Never mark an unavailable capability implemented by substituting a scraper.

## 12. Proposed approval scope

Phase 1 and Phase 2 implementation and Phase 3A research received separate authorization. The next proposed scope is the fixture-first Phase 3B described in [PHASE3A_DECISION_REPORT.md](PHASE3A_DECISION_REPORT.md), pending explicit approval and a product-purpose decision. No implementation, credentials, dependency installation, paid/live request or production mutation is authorized by this plan. Later release gates remain enforceable after any phase approval.
