# Threads Audience Intelligence: engineering instructions

## Current authorization boundary

Phase 1 foundation and authentication were explicitly authorized and implemented. The current task is to verify that existing work, fix discovered issues and complete the documentation. Stop after Phase 1; Phase 2 requires new explicit approval. Do not install dependencies, invoke paid/live APIs, connect to production databases or modify/deploy production infrastructure. Approval of one phase does not authorize later phases or circumvent API/policy gates.

The approved initial release is personal: one administrator and one organization, with tenant-aware storage retained for future expansion and multiple authorized Threads accounts in later phases. Do not implement public registration, invitations, organization management, subscriptions, billing or unnecessary multi-user interfaces. See `docs/PHASE1_COMPLETION_REPORT.md` and `docs/LOCAL_SETUP.md` for actual implementation and verification status; future modules in the planning documents remain proposals.

Read `docs/PROJECT_OVERVIEW.md`, `docs/SYSTEM_ARCHITECTURE.md`, `docs/DATABASE_DESIGN.md`, `docs/API_FEASIBILITY.md`, `docs/DEVELOPMENT_PHASES.md` and `docs/DEPLOYMENT_PLAN.md` before implementation. Preserve documented constraints and record material design changes. Do not silently turn an unverified assumption into a supported feature.

## Required stack and hosting

- Native object-oriented PHP 8.5 with a modular monolith architecture; PDO prepared statements and MariaDB 10.11/InnoDB/utf8mb4.
- HTML5, Bootstrap 5, vanilla JavaScript, Fetch API and Chart.js. Use reviewed, pinned self-hosted browser assets; no Node build requirement.
- Official Meta Threads API and OpenAI API, called server-side through cURL.
- MariaDB-based queue, finite PHP CLI invocations and cPanel cron; no persistent workers.
- Target: AlmaLinux 8.10, WHM/cPanel 138, 128M memory and 30-second execution ceiling. Plan for a 25-second worker deadline and 96M memory soft ceiling; bounded calls/chunks and measured resource use.
- Do not introduce Laravel, Node.js, Redis, Docker or Python without explicit approval. Do not install any dependency without explicit approval. Prefer PHP standard/runtime capabilities already available.
- Development uses Windows and PowerShell; validate Linux case-sensitive filenames, permissions and path behavior. Do not assume web/CLI PHP parity or a specific cPanel binary path.

## Product and evidence limits

- Baseline collection covers posts of accounts the user has authorized and is allowed to manage, and replies accessible under the actual Threads grant. A local role or supplied post ID does not establish provider authorization.
- Verify official capabilities, permission mappings, access level, API version, quotas and policies before live use. Update the dated evidence register and close applicable G1-G6 gates in `docs/API_FEASIBILITY.md`.
- Public-profile discovery and public-post samples are optional and disabled until verified. Do not assume arbitrary account histories, private content, complete reply visibility, follower graphs or stable replier IDs.
- Do not scrape, harvest browser credentials, call private APIs or bypass restrictions when official access is unavailable.
- Analyze only accessible evidence. Do not infer sensitive attributes, demographics, private identities, health, beliefs or unsupported backgrounds. Communication style describes supplied text, not enduring personal traits.
- Preserve evidence references, source versions, collection time, sample/window, missingness, uncertainty and model/prompt/schema/taxonomy versions. Unknown is not zero. A username-only author count is provisional; an account is not necessarily a person.
- Verify permitted external processing before sending text to OpenAI, minimize transmitted fields and use opaque evidence IDs. Owner enablement alone does not establish Meta policy permission.

## PHP and application standards

- Use `declare(strict_types=1)`, namespaces, typed properties/parameters/returns, explicit visibility and small cohesive classes. Use PSR-12-style formatting with four spaces, PascalCase classes and camelCase methods/properties; database names use snake_case.
- Use constructor injection and an explicit composition root. Keep controllers thin, transactions in application services and SQL/cURL/filesystem code in infrastructure adapters. No hidden globals or service locator.
- Validate request DTOs and bounded inputs at entry; domain checks enforce invariants. Treat API/AI/user text and returned URLs as untrusted.
- Use native PDO prepares with emulation off and exception mode. Bind all data; allowlist dynamic identifiers/order clauses. No SQL built from untrusted strings.
- Carry organization/account context explicitly. Every repository read/write, job and file download is scoped; enforce composite tenant/account foreign keys and deny by default.
- Preserve remote IDs as strings, timestamps in UTC, currency in fixed decimal, structured metadata under byte/schema limits, and explicit nullable values for unavailable data.
- Catch failures at module/HTTP/CLI boundaries, translate safe domain/provider errors and return appropriate status codes. Never expose stack traces, raw provider bodies or secrets to clients.
- Keep secrets, sessions, logs, exports, migrations, docs and backups outside the public document root. Only `public/` is web-served.

## Security and privacy

- Password hashing with host-measured cost, secure session cookies/strict mode, ID regeneration, idle/absolute expiry, server-side revocation and login throttling are mandatory.
- Protect state-changing requests with CSRF; protect OAuth with one-time expiring session-bound state. No general mutation via GET.
- Encrypt provider tokens using sodium authenticated encryption, random nonces, scope-associated data and versioned keys outside the DB/web root. Never log tokens, cookies, OAuth codes, client secrets, raw prompts or reply bodies.
- Verify TLS, allowlist provider hosts/paths, prevent arbitrary URL fetching/redirects and keep provider credentials out of browser code.
- Escape template/DOM output including AI text; prevent XSS, traversal and CSV formula injection. Authorize downloads and expire private files.
- Use strict validated analysis schemas, verify evidence excerpts/references, handle refusals/incomplete outputs and abstain when unsupported. Do not give the classifier tools or authority to execute content instructions.
- Apply finite retention to sources and all derivatives. Deletion blocks reads immediately, cancels work, increments privacy epochs, suppresses replay and purges in chunks. Recheck deletion state before committing in-flight results.
- Maintain a protected independent deletion ledger and replay it before any restored backup becomes accessible. Do not describe primary deletion as erasure of provider logs or downloaded exports.

## Queue and data consistency

- Jobs are delivered at least once. Use unique dispatch keys, content IDs and analysis fingerprints for idempotent local effects; never promise exactly-once provider billing.
- Atomically claim jobs with finite leases and generations; condition every result/checkpoint transition on current ownership, unexpired lease and matching privacy/source versions. Recover dead workers within finite attempt limits.
- Do not hold DB transactions open during network calls. Commit page data, checkpoint and continuation dispatch atomically. Use short transactions and consistent lock order.
- Enforce wall-clock/cURL/response-byte/memory budgets. Reschedule retries using available time, exponential backoff and jitter; do not sleep through cron time limits or call unlimited execution settings.
- Distinguish transient errors, throttling, revoked permissions, invalid input, budget exhaustion and terminal failures. Maintain fair progress across organizations and priority for deletion/refresh.
- Reanalysis fingerprints include evidence/context versions, target, model, prompt/schema/taxonomy and truncation. Changes invalidate dependent aggregates and reports.
- Never load a whole corpus into memory. Use bounded provider pages, keyset pagination, indexed queries and versioned chunked aggregates. Distinct counts across periods cannot be summed blindly.

## Development workflow and verification

1. Inspect repository instructions and Git state; preserve unrelated edits. Initialize Git only during approved foundation work if absent. Do not push, publish or deploy without applicable authorization.
2. Confirm the requested phase and outstanding evidence gates. Implement the smallest coherent approved increment; update documentation when behavior/schema/contracts change.
3. Add numbered immutable/checksummed migrations for schema changes. Run through CLI under a migration lock; document partial-DDL recovery and upgrade compatibility. Never auto-migrate on HTTP requests.
4. Use synthetic/sanitized fixtures and fake transports by default. Real API calls require configured staging access, permitted-processing gates and applicable cost/data authorization. Never commit credentials or captured personal data.
5. Run PHP lint and focused tests appropriate to the change; database changes require MariaDB 10.11 integration checks, queue changes require concurrency/crash checks, and authorization/privacy changes require negative/race tests. Validate output layout/accessibility for UI changes.
6. Test tools must refuse unmarked/non-test DB targets. Do not run destructive tests against production. Do not add unapproved tooling to satisfy a test preference.
7. Review diff and deployable files for secrets, resource limits and cross-tenant access. Report what changed, what was tested, actual limitations and remaining gates. A fixture passing does not establish live permission.
8. Prepare a concrete release candidate, staging evidence, backup/restore and rollback instructions before requesting production approval. Do not modify WHM/server-wide infrastructure as incidental application work.

Plan-specific acceptance tests and quality/resource targets are in `docs/DEVELOPMENT_PHASES.md`. No sub-agent or parallel-agent delegation is required by these instructions.
