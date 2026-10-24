# cPanel deployment and operations plan

Status: proposed runbook; no infrastructure inspected or changed.
Planning date: 24 October 2026.

Phase 1 local installation and executable commands are in [LOCAL_SETUP.md](LOCAL_SETUP.md). This document describes future staging/release operations: queue, provider, deletion-ledger and token-rotation commands are not Phase 1 capabilities. No deployment or production database access is authorized or performed. See [PHASE1_COMPLETION_REPORT.md](PHASE1_COMPLETION_REPORT.md) for remaining target-host validation.

## 1. Target and preflight

Target supplied by the user: AlmaLinux 8.10, WHM/cPanel 138, PHP 8.5, MariaDB 10.11, 128M PHP memory, 30-second execution limit and cron availability. Treat these as the deployment contract, not a reason to upgrade packages or change server-wide settings. The application needs no persistent process, root access, Redis, containers or Node build.

Before a staging deployment, the operator verifies:

- Domain/subdomain and SSL are ready; its configurable document root can point only to `public/`. Confirm the actual Apache/PHP handler and rewrite support.
- The host really offers PHP 8.5 for both this domain's web SAPI and an absolute CLI binary. cPanel's selected web version does not establish the version of an unqualified `php` command. The plan does not assume an EasyApache version-specific path exists.
- CLI and web have required extensions and compatible INI limits. Check curl, openssl, PDO/pdo_mysql, json, mbstring, intl and sodium; other user-verified extensions are optional unless a feature explicitly requires them. Measure password-hash support, OPcache configuration and CA certificates.
- MariaDB reports the expected version, InnoDB, utf8mb4 and usable strict SQL settings. Verify database user privileges, prepared statements, UTC connection behavior, max packet/connection limits and transaction/deadlock behavior.
- Cron permits the chosen frequency, CLI processes fit hosting resource quotas, and the account can run finite commands and private file locks. Confirm host-wide wall-clock/CPU limits without raising them.
- Outbound TLS/DNS to approved Meta/OpenAI hosts works and callback URLs are exact. Record provider access/policy gates before using real data.
- Disk/inode quota, database size allowance and backup capacity support the approved retention window. Identify a usable secure backup destination and restoration procedure.

No `phpinfo()` page remains public. Diagnostics are CLI-only or a tightly authorized redacted health page. If document-root isolation or PHP parity cannot be achieved, stop deployment and resolve that hosting prerequisite; do not expose the whole repository as a shortcut.

## 2. Production filesystem layout

Example paths below use placeholders, not discovered live paths:

```text
/home/<cpanel-user>/apps/threads-audience-intelligence/
  releases/
    <release-id>/               reviewed application and pinned public assets
      app/ config/ database/ resources/ bin/ public/
  current -> releases/<release-id>
  shared/
    config.production.php      private secrets and environment overrides
    keys/                      encrypted-token master keyring; restrictive access
    storage/
      logs/ sessions/ reports/ tmp/ locks/
    deployment-metadata/
    deletion-ledger/            protected restore-time deletion/suppression records
  backups/                     encrypted local staging area, never a public download
```

Preferred document root: `/home/<cpanel-user>/apps/threads-audience-intelligence/current/public`. Confirm that the host's web server follows the approved symlink and its owner restrictions. If symlinks are unsupported, use a real release-specific `public/` document root and an operator-controlled document-root switch after staging validation. If the domain root is fixed, deploy only public files there, with an explicit bootstrap path to the private release and maintenance during the switch; everything else stays outside. Test the chosen mode before the first release.

Versioned application files are read-only to the runtime where hosting ownership permits. Shared runtime directories are writable only by the application account. Aim for 0700 private directories and 0600 secrets/keys, adjusted only when the verified PHP handler requires specific group access. Public assets can use ordinary readable permissions. Never use 0777. Do not place `.git`, credentials, migrations, tests, docs, backups, logs or reports under a web-served root. Deny-directory rules are defense in depth, not the primary isolation control.

## 3. Environment-specific configuration

Use versioned non-secret defaults plus a private environment-specific PHP config file or real process environment. Load configuration once through the composition root. No dotenv dependency or parser is assumed. Both HTTP and CLI resolve the same explicit environment and release configuration; neither guesses from the current working directory.

Configuration groups:

| Group | Required values |
| --- | --- |
| Runtime | environment, canonical HTTPS base URL, release ID, display timezone, private storage path, debug=false in production |
| Database | host/socket, database name, runtime username/password, UTC connection and utf8mb4 settings |
| Security | session name/limits, trusted proxy settings if actually needed, keyring path and current encryption key ID, HMAC key ID |
| Threads | app ID, app secret, exact callback URI, verified API version/hosts, approved capabilities, token-refresh policy |
| OpenAI | private API key, approved evaluated model, request/output limits, strict schema version, `store=false`, project spend controls |
| Queue | 25-second deadline, memory soft ceiling, call timeout, lease length, retry limit, batch/byte caps, scheduler cadence |
| Privacy/operations | approved retention policy revision, collection/AI/discovery gates, export TTL, budgets, safe logs and alert destinations if approved |

Staging and production have separate databases, credentials, API apps/projects when feasible, token keyrings, callbacks, session names and private storage. Production tokens never enter development fixtures. Secrets must be provisioned through private operator-controlled channels, not Git, query strings, shell histories or deployment logs. Keep encrypted data backups and key recovery material separate, both access controlled. Test rotation by re-encrypting bounded batches, retaining old decryption keys until all live/backup data expires.

Use a dedicated runtime DB user with only the necessary application-table DML privileges. Use separate operator migration credentials for DDL; cPanel account privilege granularity must be checked. If cPanel cannot provide the planned separation, record the remaining privilege scope and keep migration entry points CLI-only. Never run the web application as a database administrator.

## 4. Release preparation on Windows

1. Confirm explicit implementation/deployment authorization at the applicable stage. Inspect repository instructions and Git status; preserve user changes.
2. Build a release from an identified reviewed Git commit/tag with no secret/runtime/test data. Browser assets are pinned and license/version recorded; no production dependency download or build step is assumed.
3. Run PHP 8.5 lint, focused automated checks, MariaDB integration checks, and a deploy-tree secret scan. Validate filename casing and `/` path handling for Linux.
4. Record release manifest, migration checksums, asset hashes/versions, API version, prompt/schema/taxonomy versions and required configuration changes.
5. Validate the exact package in staging with its own credentials and synthetic data first. Close required live API/policy gates before any authorized small real-data pilot.

The release archive contains only what the chosen deployment mode needs. Store a checksum outside the archive and verify after transfer. Do not commit generated reports, DB dumps, captured user text or private configuration.

## 5. Cron strategy

Proposed cadence: every minute for one scheduler/queue runner, subject to hosting allowance. A single finite invocation dispatches due per-account tasks, runs a bounded number of chunks and exits. Daily/hourly maintenance is represented by deduplicated scheduled-task rows; additional cron commands are unnecessary unless measured isolation is useful.

Future command shape (documentation only):

```text
/absolute/verified/php /home/<cpanel-user>/apps/threads-audience-intelligence/current/bin/console.php queue:run --max-seconds=25 --memory-soft-limit=96M
```

This command does not exist in Phase 0. Configure its eventual stdout/stderr to a private rotated log with safe output. Do not put secrets in cron command arguments. Cron uses an explicit environment/configuration path and absolute paths throughout. Persist an independent runner-start and successful-exit heartbeat so failure before scheduler startup is visible.

Use a shared private file lock to avoid overlapping invocations on the same host, plus the database lease protocol for correctness across manual/competing invocations. The file lock is released when a process exits; DB leases recover abandoned chunks. No service manager or process supervisor is needed. Respect the host's 30-second ceiling even if CLI defaults happen to be unlimited; enforce wall time in application code and cURL total timeouts, not PHP CPU accounting alone.

If cron misses intervals, dispatch at most one due synchronization per task and advance to the next slot; do not enqueue every missed minute. A long import resumes page by page. Backlog progress must not starve deletion/token refresh; schedule a bounded fair share across organizations with deletion priority. Stale cron appears on the dashboard after three expected intervals; delayed deletion is escalated according to the approved operator alert channel.

## 6. Staging and deployment sequence

1. Stage a complete immutable release and verify its checksum, private paths and configuration. Keep current production web/cron unchanged during preparation.
2. Obtain the authorized deployment window after presenting the concrete release, migration risk, staging results and rollback plan.
3. Enable maintenance for mutations and pause scheduled dispatch; allow bounded jobs to finish or expire. Verify no old runner remains active before schema/secret changes.
4. Take an encrypted database backup and necessary private-file/configuration metadata backup; confirm recoverability and record the current deletion-ledger position. Keep encryption keys separate.
5. Run reviewed CLI migrations under a migration lock with migration credentials. Detect partial DDL and stop on failure; do not blindly rerun destructive SQL. Validate schema version/checksums.
6. Switch to the new release using the staging-proven document-root/symlink method. Apply only approved configuration changes. Refresh PHP workers/OPcache through supported cPanel controls if needed; verify the serving release ID.
7. Perform authenticated smoke checks: login, role denial, private-path denial, queue enqueue/claim, synthetic import/analysis adapter, report download authorization, deletion and health. Small live provider checks require the established data/cost authorization.
8. Resume finite cron and confirm heartbeat, successful exit and advancing checkpoints. Leave maintenance only when access/privacy gates are correct.
9. Monitor error rate, latency, memory/time peaks, queue age, provider budgets, token refresh, disk quota and deletion delay. Keep the prior compatible release for rollback.

Do not bootstrap accounts via public installer pages, auto-run migrations on first request, turn on debug output to diagnose production, or change WHM/global PHP/database infrastructure as part of an application release.

## 7. Backup, restore and deletion

Proposed baseline: encrypted daily consistent DB backup and approved private artifacts needed for recovery, retained for at most 30 days unless verified policy requires a shorter period. Export files need not be backed up if regenerable. Confirm whether hosting-provider backups and cPanel snapshots can meet the same retention/deletion obligations; an application-only purge cannot control an undocumented provider backup.

Track backup creation, expiry, key version, restore test and deletion-ledger position. The ledger must survive restoration of an older application database and contain minimal protected deletion/suppression records. Until an operator has a reliable independent ledger and backup expiry process, real-data deployment fails the privacy readiness gate.

Restore exercise: isolate the restored database/files from public access and cron, restore matching keys/configuration, replay all deletion/retention/revocation records newer than the backup, remove restored report files and source data as required, invalidate stale aggregates/reports, rotate compromised credentials if applicable, verify account capability state, and only then enable access. Never restore deleted content to active service first and purge later.

Proposed recovery objectives: RPO up to 24 hours and RTO four hours, to be measured in staging and approved by the operator. Hosting quotas and account-level restore capabilities may change the achievable result. Test key recovery separately: a database backup without token keys cannot recover integrations. Local deletion reports distinguish primary completion from backup expiry and cannot promise deletion of provider retention or previously downloaded exports.

## 8. Rollback and failed migration recovery

- For a code-only or expand-compatible release, stop cron/mutations, switch to the prior verified release, confirm schema compatibility, refresh supported opcode caches and smoke-test before resuming.
- If a migration partially applies, keep maintenance on, inspect the migration step log/schema, and use its reviewed forward-repair path. MariaDB DDL is not assumed to roll back with the enclosing transaction.
- For incompatible destructive changes, prefer an explicit forward fix. Restoring a backup requires an approved data-loss assessment, isolated restore, deletion-ledger replay and verification before reopening. Do not blindly run down migrations.
- Check queue payload versions and analysis schemas during rollback. Prior code must block unknown job types/payload versions safely rather than misprocess them. Preserve the relevant reviewed release until old jobs complete or are explicitly cancelled/replaced.

## 9. Operations checklist

| Cadence | Checks |
| --- | --- |
| Every cron run | Deadline/memory, claim success, oldest due jobs, safe exit/heartbeat, pending priority deletions |
| Daily | Failed/blocked jobs, provider throttles/costs, expiring tokens, deletion age, retention progress, disk/database size, backup completion |
| Before release | Required tests, current official API/policy gates, schema/payload compatibility, package secret scan, backup and rollback evidence |
| Monthly or after material change | Restore drill, key recovery/rotation status, user/account access review, retention configuration, API permission/version/changelog review |

Incident controls include disabling collection/AI per organization, blocking a connected account, revoking/rotating leaked secrets, cancelling queued work and preserving redacted operational evidence. Never copy raw tokens/reply bodies into issue trackers. A single unavailable optional feature must not prevent permitted baseline dashboards from loading.

## 10. Deployment approval gate

The operator approves a concrete candidate only after document-root isolation, PHP/DB compatibility, actual provider permissions/policy, cost limits, retention/deletion, resource tests and restore/rollback rehearsal are demonstrated. This planning package authorizes no deployment, cron installation, production database change, secret provisioning or hosting modification.
