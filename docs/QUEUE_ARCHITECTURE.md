# Phase 2 queue architecture

Implemented 25 October 2026. This queue executes synthetic demonstrations and local retention only. No Threads, OpenAI, OAuth, provider-budget or collection handler exists. The immutable SQL migrations and PHP implementation are authoritative; later contracts in the original architecture remain proposals.

## Dispatch and states

`QueueService` owns short PDO transactions; infrastructure repositories contain SQL. The explicit `QueueComposition` wires handlers, retry policy, scheduler, privacy services, locks and logging. Every operation carries an organization ID. Account/source authorization must be added with those parent modules in Phase 3, before accepting any account or provider reference. Unsupported fields/types/versions fail closed.

Jobs contain bounded, schema-validated reference metadata (maximum database payload/checkpoint size 2 KiB), a tenant-scoped unique dispatch key, type/version, priority, UTC schedule, attempt limit, lease digest/generation/expiry, privacy epoch, timestamps, expiry and an allowlisted error code. Demo payloads accept only a random synthetic run ID, page and page count (at most 20), or a fixed failure mode. Credentials, text bodies and URLs are not accepted. Dispatch-key reuse with different work is rejected; canonical key order makes equivalent payloads match.

States are `ready`, `running`, `retry_wait`, `succeeded`, `failed` and `cancelled`. `failed` is the dead-letter state. Phase 2 has no manual retry/cancellation web interface and no provider-specific `blocked` state. Privacy blocking belongs to the scope. Retention uses priority 100; ordinary jobs are limited to -100 through 99.

## Claim and publication protocol

1. Begin a transaction; lock the existing organization row, then its `privacy_scopes` row. Initialize that synthetic scope lazily when necessary. The existing organization `privacy_epoch` is the canonical fence; synthetic deletion updates it and its scope mirror together without disabling administrator login.
2. Select one due, unexpired ready/retry job for that organization with `FOR UPDATE`, ordered by priority descending, UTC availability and ID. Check the job allowlist, schema, attempt limit, organization state and epoch.
3. Set running, increment attempts and claim generation, store a SHA-256 digest of a fresh random 32-byte ownership token, and assign a database-clock lease (default 90 seconds). Insert the attempt record in the same transaction, then commit.
4. Prepare the bounded handler result outside the transaction. No network calls are made by Phase 2 handlers.
5. To publish, lock organization, scope and job in that order. Require matching job type, token, generation, row/caller/current organization epoch, running state and an unexpired lease. Recheck organization/scope access. Publish local effects, checkpoint and continuation inside this transaction; recheck ownership/expiry after publication, then mark success and finish the attempt. Any error rolls back all these local effects.

The organization row intentionally serializes short claim/publication transactions within one tenant. Preparation occurs outside that lock, so multiple processes can own different jobs. This simple personal-installation strategy uses ordinary MariaDB transactions and row locks, without relying on `SKIP LOCKED`. SQL is designed for MariaDB 10.11; execution has been verified only on 12.3.3. Native PDO prepares bind values, including integer LIMIT parameters.

The private, per-organization process file lock reduces overlap on one host. Database fencing is the correctness boundary: concurrency tests bypass that file lock and use separate PHP CLI processes and PDO connections. A process kill releases its locks; uncommitted publication rolls back.

## Retry, crash recovery and idempotency

Automatic attempts default to five and are capped at ten. Transient failures retry after `min(cap, base * 2^(attempt-1) + jitter)`, with default base 60 seconds, cap 14,400 seconds and up to 25% jitter. `available_at` paces work; workers never sleep for retries. Permanent/unknown handler errors fail safely. Lock waits/deadlocks/statement timeouts and temporary file-purge failures have finite retries. Budget exhaustion is recorded separately. Provider throttling, permission, billing and ambiguous network-outcome classifications remain Phase 3/4 work.

Recovery locks at most 20 expired running jobs per ordinary worker invocation (service maximum 100). It marks the old attempt `retry_wait`, `failed` at the attempt limit, or `cancelled` after scope revocation. Expired pending/retry jobs are cancelled in a bounded update. A stale token/generation/epoch or expired lease cannot complete, fail or publish. There is no lease renewal: work units must fit well inside the lease and the worker deadline.

The demo handler has a unique `(organization_id,job_id)` effect and writes the page checkpoint plus deterministic continuation key atomically. Tests kill workers after claim, after a simulated external effect, after local writes before dispatch, and after continuation insertion before commit. Uncommitted effects/continuations disappear; recovery can then publish once locally.

Delivery is **at least once**. Dispatch keys and local effects are deduplicated only while their retained rows exist (normally up to the operational retention period). These guarantees do not establish exactly-once execution or exactly-once provider billing. A remote call might succeed before an ambiguous crash and execute again on retry. Future provider handlers must preserve their own source IDs/fingerprints, policy gates, budgets and unknown outcomes; do not put external writes inside the fenced local transaction.

## Scheduler and worker

Each invocation explicitly selects one organization. The hourly retention schedule is created lazily; dispatch and moving `next_due_at` occur in one transaction under the organization lock. UTC slot keys deduplicate work. Downtime coalesces to one dispatch and the next future slot; missed hours are not backfilled. Multi-tenant automatic discovery/fair scheduling is deferred because the approved product has one organization. No artificial system tenant exists.

The CLI worker defaults to 25 seconds, 20 jobs and a 96 MiB soft ceiling. It reserves one second before starting another unit, checks monotonic elapsed time and peak memory around preparation/publication, and stores measured run time and peak memory. Its MariaDB session limits individual statements and InnoDB lock waits to one second. It performs at most 100 jobs per invocation and uses bounded handlers/cleanup. These are cooperative application limits, not an OS kill guarantee for arbitrary future blocking handlers or filesystem stalls. Validate complete command wall time and resource usage on the target host; future cURL work must set independent byte/time limits and a larger call/persistence reserve.

On platforms with `pcntl`, SIGTERM/SIGINT request a stop before the next claim. Windows forced-stop tests prove lease recovery; Linux signal delivery still needs staging verification. An abrupt kill can leave a worker-run row `running`; System Status reports unfinished runs older than 60 seconds instead of calling them successes. Successful-exit heartbeat becomes stale after three minutes. A successful invocation can contain failed jobs, which remain separately visible.

After queue work, if time/job budget remains, installation maintenance prunes bounded global throttle/session state and allowlisted private log/session filenames, while audit deletion is organization-scoped. Log files contain only allowlisted operational metadata. No remote logging or notifications are configured. See the bounded-directory limitation in the completion report.

## Operational read model

System Status and `/status.json` use the organization resolved from authenticated membership, ignoring submitted tenant IDs. Existing administrator/owner checks protect both routes. Counts come from retained database jobs; last success, run errors, processed totals, duration, peak memory and unfinished-run counts come from actual worker runs. Recent job errors are safe attempt codes. Unknown measurements are null and rendered as unrecorded, rather than fabricated zeros. Counts include maintenance/demo work and are not audience analytics.

See [CRON_SETUP.md](CRON_SETUP.md), [PRIVACY_RETENTION.md](PRIVACY_RETENTION.md) and [PHASE2_COMPLETION_REPORT.md](PHASE2_COMPLETION_REPORT.md) for operation, privacy boundaries and evidence.
