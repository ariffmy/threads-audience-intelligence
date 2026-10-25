# CLI and proposed cPanel cron setup

25 October 2026. Commands exist; no cron or production infrastructure was installed or modified. Run only against an explicitly authorized local or staging database. Production release requires separate approval and the checks below. Only `public/` is web-served; `bin/console.php` is CLI-only and outside that document root. There is no HTTP worker endpoint.

Phase 3B update: the worker also runs fixture-only Threads activation/collection with account fences and one fake request per invocation. No live executor is available. The authenticated Threads page queues bounded imports; activation needs separate long/debug/profile invocations. `privacy:replay-threads-ledger --offset=0` replays the independent Threads ledger in isolated local/test restoration; also replay the original synthetic ledger. Continue returned offsets until done and purge before reopening. See [THREADS_DATA_COLLECTION.md](THREADS_DATA_COLLECTION.md).

## Local preparation

Preserve the existing Phase 1 private configuration and administrator. From the repository root:

```powershell
php -v
php -m
php bin/console.php config:check
php bin/console.php db:check
php bin/console.php migrate:status
php bin/console.php migrate
php bin/console.php migrate:status
php bin/console.php help
```

There are now twenty-five immutable migrations. Upgrade from Phase 2 applies ten; Phase 1-to-Phase 2 applies the original nine; a fresh installation applies twenty-five. Do not rerun administrator bootstrap on an existing installation. Migrations are never applied through HTTP or by the worker. Use the separate migration identity where configured. A started/failed/checksum-mismatched history stops execution: inspect schema and journal under maintenance, preserve a backup and use a reviewed forward repair or isolated restore. Do not edit an applied file or assume DDL rollback.

Use the sole administrator's real organization ID from a trusted local database administration session; examples use `<organization-id>`, not an assumed production ID. Queue commands require explicit organization context and a valid configured database. OS access to private CLI configuration is the operator authorization boundary. No multi-user administration interface was added.

In a local/test environment, enqueue a bounded demo, then run a worker:

```text
php bin/console.php queue:demo --organization=<organization-id> --pages=3
php -d memory_limit=128M -d max_execution_time=30 bin/console.php queue:run --organization=<organization-id> --max-seconds=25 --max-jobs=20 --memory-mb=96
```

`queue:demo` refuses `app.env=production`. An optional `--key=<32-lowercase-hex>` makes a repeated demo dispatch idempotent for its retained lifetime. Output includes only a synthetic job ID or a JSON run summary. Do not insert credentials/text into payloads. The handler allowlist contains only local/synthetic transports. Open the authenticated System Status page to see actual retained job/run values and refresh them.

Private config can override `queue.max_seconds` (1–25), `max_jobs` (1–100), `memory_mb` (16–96), `lease_seconds` (30–300), and bounded retry base/cap. Command options can select limits within those ceilings. Smaller budgets may intentionally claim zero jobs because the worker reserves one second for exit. The 90-second lease outlasts a normal invocation; crash recovery starts on a later invocation after expiry and backoff, rather than immediately repeating work. Never set unlimited PHP execution to solve a slow handler.

## cPanel command template — not installed

First verify the actual absolute CLI PHP binary, PHP 8.5.12/extensions/INI, MariaDB 10.11, HTTPS document root, private ownership, staging DB privileges, cron frequency and resource allowance. Do not assume an EasyApache path or parity with web PHP. The path, organization and user tokens below are placeholders.

Example cron fields: `* * * * *` (every minute, if hosting permits). Command:

```text
TAI_CONFIG_FILE=/home/<cpanel-user>/apps/threads-audience-intelligence/shared/config.php /absolute/verified/php -d memory_limit=128M -d max_execution_time=30 /home/<cpanel-user>/apps/threads-audience-intelligence/current/bin/console.php queue:run --organization=<organization-id> --max-seconds=25 --max-jobs=20 --memory-mb=96 >/dev/null 2>&1
```

The JSON-line application log and worker-run table retain operational evidence; `/dev/null` avoids an unbounded cron redirection file. Failure before configuration/database startup can only reach safe stderr, so the operator must also validate cron execution/exit externally; do not interpret a missing DB heartbeat as proof the process started. If a private cron output file or mail alert is desired, provision and rotate it separately with approval. Put no passwords, application keys or provider tokens in command arguments. Restrict the private config and independent ledger with verified POSIX ownership/modes (aim for directories 0700, files 0600); Windows ACLs require separate review.

The worker creates/advances its hourly retention schedule, recovers bounded expired leases, processes small units, optionally runs bounded installation maintenance and exits. A private process lock skips overlapping invocations for that organization; it is an efficiency guard. Competing manual workers remain safe through DB locking/fencing. Retention/deletion continuation jobs have higher priority. Retry delays are stored in UTC, with no worker sleeps or persistent services.

## Monitoring and recovery

- Check last successful run, pending/running/retry/failed counts, recent safe attempt codes and retained timing/memory on System Status. A heartbeat older than three minutes needs review; first-run absence is explicit.
- `running` history older than 60 seconds indicates an unfinished invocation, including a killed process. Job leases recover independently and consume finite attempt allowances.
- Permanent failures stay failed; Phase 2 has no general manual-retry UI or provider error recovery. Correct a defective handler in a reviewed release; do not reset attempts in production with ad hoc SQL.
- Hourly cleanup is bounded. Keep monitoring backlog and storage; one completed maintenance job does not certify every retention backlog is empty. Installation file scans currently visit at most 500 entries per directory and have no persistent cursor.
- Run `php bin/console.php maintenance:prune --organization=<organization-id>` for bounded scoped audit and installation auth/log cleanup. This command now requires the organization option. Throttle buckets, native session files and application log files are installation-wide, content-free stores for the approved personal installation.

## Release and rollback preparation

Before any approved deployment: identify the reviewed commit, create a secret-free release package from tracked deployable files only, record migration/asset checksums, take an encrypted backup, verify private ledger/signing-key recovery, and rehearse fresh install/upgrade/failed-DDL recovery and rollback in isolation. Keep docs/tests/Git/config/runtime data outside the served root.

Stop cron/mutations and let leases expire before switching code. Phase 2 migrations only add tables, so Phase 1 code can use its unchanged identity schema, but its migrator will flag unknown applied migrations. Freeze migration commands during such a code rollback and retain the Phase 2 release/SQL history. Do not delete migration journal rows or drop Phase 2 tables to silence that warning. Phase 2 jobs must remain paused under older code. Returning to Phase 2 resumes the same history after verification.

A database restore stays inaccessible with cron stopped until all independent deletion intents have been replayed and blocked scopes physically purged. Review backup loss and ledger completeness before reopening; see [PRIVACY_RETENTION.md](PRIVACY_RETENTION.md). No automatic down migration, production restore, deployment or server-wide configuration change is authorized by this document.
