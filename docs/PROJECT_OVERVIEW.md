# Threads Audience Intelligence: project overview

Status: Phase 1 implemented and locally verified; completion awaits user review. Later phases remain proposed and require approval.
Planning date: 24 October 2026 (Asia/Kuala_Lumpur).

## 1. Purpose and scope

Build a web application for people analyzing replies to Threads posts belonging to accounts they have authenticated and are authorized to manage. The product describes the observed discussion: engagement, sentiment, stance toward a stated proposition, communication style in the supplied text, recurring topics, and permitted public profile statements. Every conclusion must be traceable to accessible evidence.

The approved first release is a personal installation with one administrator and one organization. There is no public registration, invitation flow, organization management interface, subscription or billing. Tenant-aware storage remains the isolation boundary for future expansion; multiple authorized Threads accounts belong to later phases. A local administrator role never substitutes for the Threads account holder's OAuth authorization.

Phase 0 produced the original planning package. Phase 1 subsequently added the foundation, six identity/audit migrations, administrator authentication and six page types. The remaining collection, queue, analysis and reporting design is a future proposal. No live provider integration or production change has been made. Actual files, checks and limitations are recorded in [PHASE1_COMPLETION_REPORT.md](PHASE1_COMPLETION_REPORT.md); installation is documented in [LOCAL_SETUP.md](LOCAL_SETUP.md).

## 2. Required runtime

| Layer | Constraint |
| --- | --- |
| Development | Codex Desktop, Windows, PowerShell, Git |
| Production | AlmaLinux 8.10, WHM/cPanel 138 |
| Application | Native PHP 8.5, object-oriented modules, PDO prepared statements |
| Database | MariaDB 10.11, InnoDB, utf8mb4 |
| UI | HTML5, Bootstrap 5, vanilla JavaScript, Fetch API, Chart.js |
| Integrations | Official Meta Threads API and OpenAI API through server-side cURL |
| Background work | MariaDB queue, bounded PHP CLI invocations, cPanel cron |
| Resource ceiling | 128M PHP memory and 30-second execution limit; design CLI tasks to fit too |

The user has verified curl, openssl, PDO, pdo_mysql, mysqli, json, mbstring, fileinfo, intl, gd, imagick, zip, sodium, and OPcache for PHP CLI. Web-SAPI parity must still be checked. Laravel, Node.js, Redis, Docker, and Python require explicit approval. No build service or persistent worker is assumed. Initially use a small project autoloader and self-hosted, pinned browser assets; no Composer runtime dependency is required.

## 3. Product capabilities and boundaries

| Capability | Proposed delivery | Evidence boundary |
| --- | --- | --- |
| Authentication and administration | One CLI-provisioned administrator/owner, scoped authorization and audit history; account grants later | Private personal access; no inferred access to Threads accounts |
| Threads connection | Server-side OAuth, encrypted tokens, permission/expiry status | Each connected account authorizes the Meta app |
| Import posts and replies | Paginated, resumable, user-selected import window | Only authorized owned/managed root posts in the baseline |
| Unique replying accounts | Distinct stable author IDs where returned; otherwise observed handles | An account is not necessarily a person; handle counts are provisional |
| Sentiment and stance | Text classification with evidence and abstention | Stance target must be explicit; sentiment is separate |
| Communication style | Text-level labels such as question, concise explanation, humor, or disagreement | No enduring personality, intelligence, mental health, or demographic scoring |
| Public profile analysis | Optional summary of permitted, explicitly stated profile content | Permission and policy gated; no private identity enrichment |
| Recurring topics | Topics in collected replies; optional permitted public-post sample | Never promise arbitrary users' complete posting histories |
| Reports and dashboard | Scoped metrics, trends, evidence drill-down, HTML/CSV reports | Show collection window, sample size, coverage, limitations, and model version |
| Operations | Queue status, retries, retention/deletion, cost controls | No scraping, browser-session harvesting, or API restriction bypass |

The API feasibility status and unresolved checks are authoritative in [API_FEASIBILITY.md](API_FEASIBILITY.md). Optional discovery must remain disabled until its exact permission, access level, fields, eligibility, quota, and permitted processing are verified. Missing discovery access does not block discussion analytics on authorized replies.

## 4. User journeys

1. The operator provisions the sole administrator and organization through a CLI-only bootstrap. The administrator signs in and manages their profile, password and display timezone (Phase 1).
2. In a later approved phase, the owner connects a Threads account and sees the permissions actually obtained. No other local users need provisioning.
3. The owner selects authorized posts and a bounded time window. The application creates an import request and returns a job identifier immediately (future phase).
4. Cron imports pages and records checkpoints. The user sees partial, complete-within-requested-scope, blocked, or failed states and the last successful collection time.
5. An authorized user reviews a cost estimate and initiates analysis, subject to organizational budgets and enabled processing policy. The system analyzes only the selected evidence.
6. The dashboard and reports show aggregated discussion results with links to supporting replies. Users can flag mistaken labels; corrections are preserved separately from model output.
7. Owners disconnect, revoke local access, shorten retention, or request deletion. Access stops immediately; bounded purge jobs remove source data and derived artifacts.

## 5. Proposed modules

| Module | Responsibility |
| --- | --- |
| Identity | Sole administrator login, password changes, session revocation and role checks; no invitations |
| Organizations and account access | Tenant scope, membership, account grants, settings |
| Threads integration | OAuth, encrypted credentials, capability registry, API transport |
| Collection | Imports, cursors, root ownership validation, normalized content, provenance |
| Audience | Observed authors, identity quality, public profile snapshots when permitted |
| Analysis | Versioned prompts/taxonomy, requests, validation, labels, evidence, corrections |
| Topics | Reply-topic aggregation and optional separately labeled public-post samples |
| Analytics | Metric definitions, chunked aggregates, filters, freshness and coverage |
| Reports | Reproducible report scope, source references, HTML and CSV exports |
| Queue and scheduler | Dispatch, leases, retries, checkpoints, scheduled-task deduplication |
| Privacy | Retention, deletion, suppression, consent/policy records |
| Administration and operations | Health, budgets, redacted logs, audit events, migration status |

## 6. Analytics definitions

- Imported replies: distinct permitted reply objects currently retained in the selected scope. Exclude duplicates across top-level and conversation retrieval. Root posts are not replies.
- Unique observed authors: distinct stable API author IDs where available, plus separately disclosed unresolved handle-based observations. Never silently equate this to exact distinct humans. Unknown authors are counted as unknown, not combined into one account.
- Sentiment distribution: one latest valid result per unchanged reply and analysis configuration. Show positive, negative, neutral, mixed, and uncertain; disclose successful analyses divided by eligible retained replies. Refused, skipped, and failed items are separate.
- Stance: support, oppose, mixed, unclear, or not applicable toward the chosen post proposition. A negative sentiment label does not imply opposition.
- Topic frequency: distinct replies assigned a topic divided by successfully topic-analyzed replies in scope. Multi-label totals may exceed 100%; disclose that explicitly.
- Returning engagement: authors observed in more than one selected post, subject to identity quality and time window. No global follower or population claims.
- If optional official insights are enabled, show API-provided engagement separately from imported-reply counts. Only compute a view-based engagement rate when a compatible official denominator and time basis exist.

All date filters use explicit timezone boundaries and half-open UTC intervals. Default display timezone is Asia/Kuala_Lumpur; persist timestamps in UTC. Do not treat unavailable metrics as zero.

## 7. Proposed defaults requiring approval

These are product choices, not claims about provider retention rules:

| Setting | Proposed default |
| --- | --- |
| Initial import | User-selected posts in the last 30 days; explicit expansion |
| Optional public discovery and profile analysis | Off until verified and enabled |
| Sending collected text to OpenAI | Off until permitted-processing review and owner enablement |
| Source content and linked analyses | 90 days from collection, unless source deletion or stricter obligation applies sooner |
| Export files | 7 days, private authenticated downloads |
| Redacted operational logs | 30 days |
| Minimal security/audit events | 90 days; no reply bodies or access tokens |
| Encrypted backups | Maximum 30-day rolling window, subject to provider/policy review |
| Deletion service objective | Immediate access block; primary-data purge within 24 hours under normal operation |
| Analysis limits | Per-run estimate and administrator-configured daily budget; no unbounded automatic reanalysis |

Retention includes derivatives, report evidence, exported files, queue payloads, logs, and backups. A derivative's expiry cannot exceed the earliest expiry of its evidence unless it is demonstrably anonymous and permitted; initial release makes no such exemption.

## 8. Success and release criteria

The release must demonstrate authenticated owned-account collection with real permitted credentials in staging, strict cross-organization and account authorization, resumable duplicate-safe jobs, evidence-linked classifications, working deletion and restore controls, and operation below hosting limits. Exact acceptance checks are in [DEVELOPMENT_PHASES.md](DEVELOPMENT_PHASES.md).

Performance acceptance uses a synthetic fixture of 100 posts and 10,000 replies: peak PHP memory below 96M as a safety target, each cron invocation ending within 25 seconds, and representative indexed dashboard requests under two seconds on target-like staging. These are test targets, not capacity guarantees. Larger volumes require measured tuning, storage forecasting, and smaller work units.

## 9. Reading order and approval

1. [SYSTEM_ARCHITECTURE.md](SYSTEM_ARCHITECTURE.md): components, directories, queue, security, dashboards.
2. [DATABASE_DESIGN.md](DATABASE_DESIGN.md): entities, fields, indexes, consistency and deletion.
3. [API_FEASIBILITY.md](API_FEASIBILITY.md): official evidence, limitations, unresolved release gates.
4. [DEVELOPMENT_PHASES.md](DEVELOPMENT_PHASES.md): ordered work, acceptance and testing.
5. [DEPLOYMENT_PLAN.md](DEPLOYMENT_PLAN.md): cPanel layout, release, rollback and operations.
6. [../AGENTS.md](../AGENTS.md): binding engineering constraints and workflow.

Phase 1 was explicitly approved with the personal-use scope above. Review its completion report before authorizing Phase 2. The modular monolith, tenant/account isolation, read-only baseline, bounded cron queue and future retention controls remain the architectural direction. No approval of Phase 1 authorizes production deployment or bypassing later API/policy gates.
