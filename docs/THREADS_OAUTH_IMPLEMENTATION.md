# Threads OAuth and credentials — Phase 3B

25 October 2026 (Asia/Kuala_Lumpur). Implemented for **local synthetic fixtures only**. The dated Phase 3A capability matrix remains the protocol planning baseline. No real app ID, secret, authorization code, token or Meta account is configured.

## Execution boundary

`ThreadsComposition` selects `DisabledTransport` unless `app.env` is local/test and `threads.fixture_enabled` is exactly true. Even when enabled, the only executable implementation is the in-memory `FixtureTransport`. There is no cURL executor, socket client, live transport factory, production switch or arbitrary URL entry point in this module. Production refuses fixture actions even if the flag is true. Implementing a live adapter requires a future reviewed code change and separate authorization; changing configuration cannot activate live collection.

The public client ID/app namespace is literally `fixture-app`. All codes and token responses must use the `fixture-` vocabulary. The built authorization URL uses the fixed `https://threads.com/oauth/authorize` contract, minimal `threads_basic,threads_read_replies` scopes and exact configured application origin plus `/threads/oauth/callback`. It is tested, but the UI never redirects to Meta. No PKCE contract is assumed.

## Administrator flow

1. Authenticated owner POSTs `/threads/connect`; existing Origin, administrator and CSRF controls apply. The service independently checks the current owner membership and session registry. The one-account-per-organization constraint is enforced in SQL.
2. Under organization/account locks, cancel old work, increment credential generation/grant revision, remove old credentials/grants/state and create a random 32-byte state. Persist its SHA-256 digest, session digest, user/session/organization/account, exact redirect, expected generation/privacy epoch and ten-minute expiry.
3. The fixture UI simulates consent inside this POST. It closes the PHP session lock, then invokes the same callback service using a synthetic code. This avoids a provider redirect or credential-bearing browser URL. It does not claim to test a real consent window.
4. The callback service strictly parses bounded parameters before PHP-style duplicate keys can erase tampering evidence. Missing/duplicate/array/unknown parameters, code+error ambiguity and non-fixture codes fail closed. It locks the account before state, checks session/user/tenant/redirect/expiry/fences, and atomically consumes state **before** any exchange. A declined flow consumes valid state too.
5. Reserve a bounded account request, commit, then perform one fake code exchange outside all DB transactions and session locks. An ambiguous result requires restarting consent; a consumed code is never automatically replayed.
6. Recheck identity/session and account fences, reject identity substitution, encrypt the pending short token and atomically dispatch long-token activation. The short expiry is a conservative one-hour estimate from the documented contract, not observed real metadata.
7. Separate queue units exchange a long token, debug its app/user/grants/separate expiries, and obtain the own profile. Identity must match the code exchange and any reconnect identity. Only the final verified profile step activates the fixture account. No content can be read/collected while pending.

`GET /threads/oauth/callback` exists as a testable callback boundary. Every handled outcome immediately redirects to a clean local URL. Its response uses `Referrer-Policy: no-referrer`, no assets and no raw provider descriptions. Anonymous callbacks also receive the no-referrer header. An invalid callback yields a generic failure URL, not a token/code echo. Upstream web/proxy callback-query redaction remains a staging requirement; application logging does not log queries, codes or tokens.

## Token vault and rotation

`TokenVault` uses sodium XChaCha20-Poly1305 AEAD, a fresh 24-byte random nonce and a versioned JSON envelope. Associated data binds envelope version, provider, fixture environment/app, organization, account, token kind, generation and key ID. Wrong scope, kind, generation, key, nonce or ciphertext fails integrity verification. Missing sodium or keys fails closed.

Configure an absolute private JSON `threads.keyring_path` outside `public/` and Git. The schema is `current` (key ID) and `keys` (at most eight key IDs mapped to 64 lowercase hexadecimal secret characters). Provision keys privately using existing PHP random-byte capabilities; do not put key values in tickets, commands or this document. POSIX permissions/Windows ACLs and backup/key separation need operator verification. Keep the token keyring independent of DB backups and of the session application key.

The authenticated POST rotation action loads the current private key, decrypts under the old context, re-encrypts under the new generation/key, cancels old work and atomically replaces ciphertext. Issuance and expiry do not change. Old decryption keys must survive until every relevant live/backup envelope has expired or been rotated. A missing old key cannot be repaired from the DB. There is no automatic whole-installation/key-backup rotation certification.

## Refresh, expiry and disconnect

Refresh accepts only a verified, unexpired long token at least 24 hours old and independently unexpired permissions/grants. Dispatch is deduplicated by credential generation. An account reservation prevents two distinct jobs/processes from refreshing concurrently. Reservations last 90 seconds; unknown outcomes retain a content-free request record and count against the local allowance. Publication requires the original lease, generation, account/privacy/grant/source fences and reservation. Refresh increments credential generation and returns to pending debug/profile validation before activation. Old collection jobs cannot publish the new token or old content.

Expiry checks exist on reads, dispatch, pre-call and publication. Invalid grants or identity cause reauthorization-required status and token/grant removal; no infinite refresh loop. Local synthetic revocation uses the durable deletion path and a revoked status. Real provider revocation/deletion callbacks remain unavailable because their Threads signature/identifier mapping is unverified.

Disconnect is authenticated POST+CSRF. It records an independent deletion intent, blocks access, invalidates epochs/generations, removes credentials/grants/states and cancels work before bounded content purge. Disconnect is intentionally permanent for this fixture scope. Reauthorization of an expired connection must preserve its remote identity; releasing a disconnected suppression scope requires a future reviewed recovery design, not a username match.

See [THREADS_SECURITY_REVIEW.md](THREADS_SECURITY_REVIEW.md) and [THREADS_STAGING_TEST_PLAN.md](THREADS_STAGING_TEST_PLAN.md). Nothing here closes G1–G6 or permits live processing.
