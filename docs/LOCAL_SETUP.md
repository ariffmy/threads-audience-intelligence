# Local setup and verification

Phase 2 update (25 October 2026): preserve existing configuration/administrator and apply the nine additive migrations. Queue commands and proposed cron setup are in [CRON_SETUP.md](CRON_SETUP.md); privacy scope and optional independent ledger settings are in [PRIVACY_RETENTION.md](PRIVACY_RETENTION.md). Stop after Phase 2; no Phase 3/provider or production work is authorized.

This guide runs the existing personal application on a development computer. It does not authorize production access or deployment. Use a dedicated local database and private configuration. There is one administrator and one organization; no public registration, invitations or organization-management UI.

## 1. Prerequisites

- PHP 8.5 CLI and an existing local MariaDB installation. The production targets are PHP 8.5.12 and MariaDB 10.11. The recorded Windows run used PHP 8.5.8 and MariaDB 12.3.3; it does not establish compatibility with those exact production versions.
- PHP extensions used by Phase 1: PDO, pdo_mysql, session, mbstring and openssl, plus standard JSON/hash/filter capabilities. Tests use cURL, `proc_open` and local sockets. PHP must support secure random bytes and password hashing.
- A local database operator identity for creating the development database/users. Use separate application credentials with limited privileges; do not run the web application as root.
- No Composer, Node, Python, Docker, Redis or package installation is needed. Bootstrap 5.3.8 CSS is already vendored with its license. Chart.js is deferred until charts are required.

From PowerShell, enter the project directory and inspect the current PHP binary:

```powershell
Set-Location 'D:\project\threads-audience-intelligence'
php -v
php -m
php bin/console.php help
```

Use the same PHP 8.5 binary for commands and the local web server. Do not change global/server PHP settings as part of these steps.

## 2. Create a local database and identities

In the existing local database administration tool, create `threads_audience_local` using InnoDB and `utf8mb4_unicode_ci`. Provision a runtime identity with SELECT, INSERT, UPDATE and DELETE on that database only. Provision a separate local migration identity with the DDL privileges needed to create the fifteen tables, foreign keys and indexes, plus the DML needed for migration history and initial administrator creation. Keep both passwords private.

Example SQL template for a local operator to adapt; replace both password placeholders before executing in a private administration session. These statements have not been applied by this guide:

```sql
CREATE DATABASE threads_audience_local
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'tai_local_app'@'127.0.0.1'
    IDENTIFIED BY '<choose-a-unique-runtime-password>';
GRANT SELECT, INSERT, UPDATE, DELETE ON threads_audience_local.*
    TO 'tai_local_app'@'127.0.0.1';
CREATE USER 'tai_local_migrate'@'127.0.0.1'
    IDENTIFIED BY '<choose-a-different-migration-password>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
    ON threads_audience_local.* TO 'tai_local_migrate'@'127.0.0.1';
```

Match the database identity's host to the server's actual local connection/account resolution. The example uses TCP `127.0.0.1`; the application does not configure a Unix socket. No application schema drop command is part of installation.

## 3. Configure privately

Copy `config/config.example.php` to ignored `config/local.php` if it does not already exist. Preserve an existing configuration. Edit the private copy:

- `app.env`: `local`.
- `app.url`: `http://localhost:8080`, exactly matching the server and browser origin.
- `app.key`: a new 64-character lowercase hexadecimal secret from `php bin/console.php key:generate`. Store it privately; the command prints a secret, so do not capture/share that output in tickets or transcripts.
- `database`: the local database name, TCP host/port and runtime credentials.
- `database.migration_username` and `migration_password`: the separate migration identity. If omitted/empty, CLI migration/bootstrap commands use the runtime identity, which must then hold the necessary privileges. Prefer the separated identities.
- Optional `app.storage`: an absolute private directory outside `public/`. The default is the repository's ignored `storage/`. Use Windows ACLs for private access; POSIX permission modes alone do not establish Windows ACL protection.

An alternative private configuration file can be selected with an **absolute** `TAI_CONFIG_FILE` path outside `public/`. Configuration must return a PHP array. Resolution order is versioned defaults, private file, then environment overrides. There is no dotenv parser.

| Environment variable | Configuration key |
| --- | --- |
| `TAI_CONFIG_FILE` | Private PHP file selection |
| `TAI_ENV`, `TAI_APP_URL`, `TAI_APP_KEY`, `TAI_STORAGE_PATH` | `app.env`, `url`, `key`, `storage` |
| `TAI_DB_HOST`, `TAI_DB_PORT`, `TAI_DB_NAME` | `database.host`, `port`, `name` |
| `TAI_DB_USER`, `TAI_DB_PASSWORD` | Runtime `username`, `password` |
| `TAI_MIGRATION_DB_USER`, `TAI_MIGRATION_DB_PASSWORD` | CLI migration/bootstrap credentials |

Use canonical origin-only URLs, without subdirectory, query, fragment or user information. HTTPS is required in production; HTTP is accepted only for loopback local/test origins. The application does not trust forwarded proxy headers. A future reverse-proxy deployment needs a reviewed transport configuration; do not spoof request headers to bypass the check.

The app key authenticates session identifiers and throttle buckets. Preserve it across normal restarts, keep it out of Git and back it up privately. Rotation/recovery is an operator procedure, not a Phase 1 UI feature. No provider credentials are needed or accepted by a Phase 1 integration.

## 4. Validate configuration and migrate

```powershell
php bin/console.php config:check
php bin/console.php db:check
php bin/console.php migrate:status
php bin/console.php migrate
php bin/console.php migrate:status
```

Each command should exit zero. Migration status should list exactly fifteen applied migrations. Upgrade applies nine, a fresh install fifteen, and a repeat zero. The runner creates its own `schema_migrations` table, holds a database advisory lock and checks SHA-256 checksums. Migrations are immutable after application. HTTP requests never migrate the database.

MariaDB DDL can commit independently of the migration journal. A started/failed row, missing historical file or checksum mismatch stops migration. Do not edit SQL to force success or blindly rerun DDL. Inspect the local journal and actual table state, preserve a backup, and reconcile through a reviewed operator procedure or restore a known clean local backup. No automatic down/repair operation is provided.

## 5. Create the administrator

Choose your own email, display name and unique password of 14–72 bytes. Unicode characters may occupy more than one byte. No default credentials exist. Passwords are never accepted as command arguments.

On Windows, run this in an interactive PowerShell terminal. Replace the email/name placeholders. The password prompt hides input and pipes the value directly to PHP without writing a password file or putting it in command history:

```powershell
$adminSecret = Read-Host 'New administrator password (14-72 bytes)' -AsSecureString
$adminPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($adminSecret)
try {
    $adminPlain = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($adminPointer)
    $adminPlain | php bin/console.php admin:create --email="your-address@example.com" --name="Your name" --password-stdin
    if ($LASTEXITCODE -ne 0) { throw 'Administrator creation failed; review the safe CLI message.' }
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($adminPointer)
    Remove-Variable adminPlain, adminSecret, adminPointer -ErrorAction SilentlyContinue
}
```

The stdin mode reads one line without confirmation; choose the password carefully. PowerShell briefly holds a plaintext copy in memory; this is not a guarantee of erasure from managed memory. On a supported POSIX terminal, omit `--password-stdin` to use PHP's hidden entry/confirmation prompt (`stty` and `exec` must be available). Neither path prints the password.

Bootstrap requires all migrations and refuses initialization if **any** user or organization already exists. It atomically creates one administrator, one personal organization, owner membership and an audit event. It cannot add a second administrator or reset a lost password. Email editing and self-service password recovery are outside Phase 1. A signed-in administrator can change their password from Profile; this signs out every session.

## 6. Run locally

```powershell
php -d memory_limit=128M -d max_execution_time=30 -S localhost:8080 -t public bin/dev-router.php
```

Open `http://localhost:8080/login` in Chrome. Use the configured hostname consistently; `localhost` and `127.0.0.1` are different origins. Stop this development server with Ctrl+C when finished. The PHP built-in server is a local verification tool, not a production server.

Check login, Dashboard, Account & profile, Application settings, System status, status refresh and Sign out. Dashboard modules are explicitly marked Not implemented; they contain no fabricated audience metrics. Status displays the actual database version alongside the 10.11 target. Local HTTP appropriately shows Transport as Review and uses a local HttpOnly/SameSite cookie; Secure/__Host cookies require HTTPS.

Only `public/` is served. Never start the server at the repository root. Configuration, migrations, docs, tests, logs and session files remain private. The dev router serves only an explicit static-asset allowlist; Apache rewrite/.htaccess behavior still requires staging validation.

## 7. Run tests

Fast unit/native-session checks without a database:

```powershell
php -d memory_limit=128M tests/run.php
```

The database/authentication/HTTP group is reported as skipped in this mode. It is not a full verification result.

For the complete suite, provide explicit **local test** credentials through `TAI_TEST_DB_USER` and, when needed, `TAI_TEST_DB_PASSWORD`. Optional variables are `TAI_TEST_DB_HOST` (loopback only) and `TAI_TEST_DB_PORT` (default 3306). Use a private environment/session to supply passwords rather than typing literal secrets into reusable scripts/history.

```powershell
# Supply the name of your existing local test operator, not production credentials.
$env:TAI_TEST_DB_USER = 'your_local_test_operator'
php -d memory_limit=128M tests/run.php --database
$testExit = $LASTEXITCODE
Remove-Item Env:TAI_TEST_DB_USER -ErrorAction SilentlyContinue
Remove-Item Env:TAI_TEST_DB_PASSWORD -ErrorAction SilentlyContinue
if ($testExit -ne 0) { throw 'Phase 1 tests failed.' }
```

The local test operator needs CREATE/DROP DATABASE and DDL/DML in newly created test databases, plus local subprocess/socket access. It is separate from the limited application runtime identity. The suite creates a random `tai_test_<16 hex>` database and verifies an ownership marker before deleting it. It does not load the application's local config or select an existing application database. Never supply production credentials. Interrupted test processes can leave private runtime files or a test database; inspect ownership and markers before any manual cleanup.

The Phase 1 baseline had 50 tests. See PHASE2_COMPLETION_REPORT.md for the current full-suite result. Missing cURL/proc_open can skip HTTP tests; a missing database or invalid credentials fails the environment setup. Read the final passed/failed/skipped totals and actual engine version. Test evidence and remaining target-host checks are in [PHASE1_COMPLETION_REPORT.md](PHASE1_COMPLETION_REPORT.md).

PHP syntax checks (no dependencies):

```powershell
$phpFiles = @(rg --files -g '*.php' app bin config public resources tests)
foreach ($file in $phpFiles) {
    php -l $file
    if ($LASTEXITCODE -ne 0) { throw "PHP lint failed: $file" }
}
```

## 8. Private logs, retention and troubleshooting

Operational logs are JSON lines under private `storage/logs`; database audit events contain allowlisted action metadata. Requests return a correlation reference instead of credentials or exception details. Initial defaults: 30-minute idle sessions, 12-hour absolute sessions, 30-day operational logs and 90-day audit events. Login limits count every attempt, successful or failed: five per normalized email and thirty per IP in a 15-minute fixed window. These are small personal-use defaults, not distributed abuse protection.

Run `php bin/console.php maintenance:prune --organization=<organization-id>` manually to remove bounded expired auth/audit rows and old private log/session files. Each table deletion is capped at 500 rows; each directory visit is capped at 500 entries. No cron is installed; the Phase 2 queue and hourly maintenance schedule run only when the CLI worker is invoked. Monitor growing directories: the current bounded file scan has no persisted cursor, so it does not guarantee progress past the first 500 entries. Revisit that operational limit before higher-volume use. Expiry checks revoke access independently of physical cleanup.

- Connection failure: check the private local DB settings, identity host grants and `db:check`. Do not print credentials or enable public PHP error output.
- HTTP 400: use the configured origin/port and transport. Do not mix hostnames.
- HTTP 419: reload the form; sessions/CSRF tokens may have expired or rotated.
- HTTP 403 with `Origin: null`: application pages now use `Referrer-Policy: same-origin`; foreign/null origins remain rejected. A sandboxed embed or browser policy that still supplies a null origin needs a normal top-level application tab.
- Loopback resets: some `127.0.0.1` HTTP probes reset on this machine; `localhost` worked. The precise cause was not isolated. The guide and HTTP test harness use localhost. No endpoint protection was disabled.
- HTTP 503: check private configuration/storage, completed migrations and local database availability. The fallback intentionally avoids database secrets and stack traces.
- Missing styling: ensure `-t public`, the dev router, and vendored asset paths are intact. Do not replace self-hosted assets with an unreviewed CDN dependency.

Linux/cPanel file ownership, actual HTTPS behavior, web/CLI PHP parity, MariaDB 10.11 execution, resource limits and backup/restore still need authorized staging checks. See [DEPLOYMENT_PLAN.md](DEPLOYMENT_PLAN.md). Stop after Phase 2 review; do not begin Phase 3, connect external APIs or deploy without new explicit approval.
