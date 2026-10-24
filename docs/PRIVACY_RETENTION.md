# Phase 2 privacy and retention controls

Implemented 25 October 2026 for synthetic jobs/artifacts and operational records. These are provisional product maximums, **not verified Meta policy requirements**. G1–G6 in [API_FEASIBILITY.md](API_FEASIBILITY.md) remain unresolved. No real Threads content, AI output, provider logs or production backups were processed or deleted.

## Configuration and implemented stores

Private PHP config accepts shorter positive retention periods; increasing above these provisional maximums is rejected and requires a reviewed policy/code change. Configuration is the operator-controlled source of truth. Worker startup records a new immutable per-organization policy row when values differ; there is no retention-settings web interface.

| Setting | Default / maximum | Phase 2 enforcement |
| --- | --- | --- |
| `privacy.source_days` | 90 days | Synthetic demo effects only: expiry and read filters, bounded physical purge. No Threads/analysis tables exist. Effects also expire no later than their parent operational job. |
| `privacy.export_days` | 7 days | Inventoried private synthetic artifacts, scope/epoch/expiry checks on every read and bounded unlink/inventory cleanup. No report generator or HTTP download exists. |
| `privacy.log_days` | 30 days | Queue/attempt/run TTLs, bounded operational DB cleanup and private application log cleanup. The stricter legacy `security.log_days` also applies to log files. |
| `privacy.audit_days` | 90 days | Organization-scoped expiry/age purge; privacy audit events use this TTL. The stricter legacy security audit setting applies to general maintenance. |
| `privacy.backup_days` | 30 days | Recorded as a backup-expiry target for deletion requests. Backup creation, encryption, inventory and actual provider/cPanel backup erasure require operator infrastructure and are not implemented or certified. |

Expiry/read predicates stop access independently of physical removal. Shortening source/artifact retention applies to reads immediately through created-time bounds. Cleanup checks current policy as well as stored expiry; bounded backlogs can remain on disk/DB temporarily while access is already denied. Audit/run/job/file cleanup uses explicit organization predicates. Global login-throttle/native-session/application-log maintenance is a separate installation concern for one personal administrator; no tenant content is logged there.

## Synthetic scope deletion

The only deletion target is `synthetic_scope`: this organization's demo workload and synthetic artifacts. It does not delete the administrator, organization identity, account grants, source content or analysis (the latter modules do not exist). No broad deletion web action was introduced. The CLI deletion command refuses the production environment.

1. A trusted local/test operator supplies an organization ID and stable random request key. `DeletionLedger` appends and flushes/fsyncs a minimal signed intent outside both the DB and application storage before the primary transaction starts. Missing/unwritable/unsafe ledger configuration fails closed.
2. Under the organization/scope lock, the service increments the existing organization privacy epoch and data version, mirrors the scope epoch, sets its irreversible synthetic-scope block, creates an idempotent deletion request, audits the block and enqueues high-priority purge work atomically. The organization stays accessible for administrator health/login.
3. Demo reads/artifact reads and new demo dispatches fail immediately. Existing workers must match the current epoch and scope at publication; late results are rejected. No release/unblock interface exists, so later replay cannot reopen that synthetic scope accidentally.
4. Maintenance purges at most 100 demo jobs, their bounded attempts/effects, and 100 inventoried artifacts per chunk. It deletes dependency rows before jobs and never disables FKs. More work dispatches an atomic continuation. Expired operational/audit/run rows have separate bounded cleanup.
5. File failures preserve the block and deletion request; retries use the same finite backoff/attempt rules. Unlink precedes inventory-row deletion. A DB rollback after unlink can leave a tracked missing file, which retry safely handles. Primary completion requires no demo jobs or registered artifacts left; it does not certify backup erasure.

Minimum deletion request IDs, scope blocks and ledger intents are suppression/recovery records. They persist rather than expiring with ordinary logs: removing them before every restorable copy is gone could resurrect deleted data. Their eventual lifecycle/release rules require review before real personal-data processing. Privacy audit events themselves expire. `backup_expiry_at` is an operational target, not a measured deletion confirmation or provider-erasure statement.

Example, local/test only:

```text
php bin/console.php privacy:delete-synthetic --organization=<organization-id> --key=<32-lowercase-hex>
php bin/console.php queue:run --organization=<organization-id>
```

Repeated requests with the same key are locally idempotent. A durable intent may exist without a primary tombstone after a crash; replay conservatively applies that intent. Never reuse the CLI for a live account deletion promise.

## Private artifact lifecycle

`PrivateFileStore` uses a private `exports/<organization-id>/<random-32-hex>` path, never a user-supplied filename or URL. Traversal, symlinks, out-of-scope reads, oversized content and production creation are rejected. Synthetic files are limited to 64 KiB. Registration commits before writing, and a separate locked transaction validates the epoch/registration, writes the file and marks it ready. A killed write leaves a non-ready expiring inventory row; a read requires ready state. Creation refuses an enclosing transaction to preserve this recovery contract. Read/unlink operations are bounded infrastructure work.

This is an internal synthetic lifecycle adapter, not a report download feature. Phase 3/5 must add account authorization and source/derivative manifests before sensitive exports. Operator isolation and private directory ownership are required; symlink checks do not defend a compromised OS identity that can mutate the same paths concurrently.

## Independent ledger and restore procedure

Configure `privacy.ledger_path` to an absolute private directory independent of `app.storage` and outside `public/`. Set `privacy.ledger_key` to a separate 64-character lowercase hexadecimal secret, provisioned privately and backed up separately. Neither is printed by application commands. Signed JSONL records contain only organization ID, request key, fixed target kind and UTC time; no text bodies, credentials or handles. Files use restrictive modes on POSIX and need verified ACLs on Windows.

Replay streams at most 100 records per call and returns `offset`, `records`, and `done`. It validates signatures, schema, line boundaries and bounded line size. Reapply from offset zero for every isolated restore, then continue at each returned offset until `done=true` (a final zero-record call is possible):

```text
php bin/console.php privacy:replay-ledger --offset=0
php bin/console.php privacy:replay-ledger --offset=<returned-offset>
```

The current command intentionally requires local/test configuration; no production replay switch was enabled. For a future authorized production recovery, use an isolated restored environment and keep web/cron inaccessible. Restore the matching ledger signing key, replay the **complete independently protected ledger**, run bounded purge workers until every blocked request completes, verify private files/suppression and authorization, and only then request approval to reopen. Tests cover replay and crash-equivalent missing primary state, not an encrypted cPanel backup/restore drill.

HMAC detects changed record contents, not deletion/truncation of whole signed records. Maintain independent access controls, backups and a separately protected ledger size/checksum/position at backup/recovery time. This external completeness check and key lifecycle must be rehearsed on staging. The application cannot certify a host's backup retention or destroy provider-controlled logs. Local purge does not erase already downloaded exports.

## Remaining privacy gates

Actual account/subject/content suppression, source-version fencing, derivative invalidation, provider deletion callbacks, permitted processor transfer, encrypted backup inventory and verified external erasure are later modules/gates. The Phase 2 synthetic target must be expanded and independently tested when those stores exist. Owner enablement never substitutes for Meta permission or processing-policy verification. See [PHASE2_COMPLETION_REPORT.md](PHASE2_COMPLETION_REPORT.md) for measured evidence and release limits.
