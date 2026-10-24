# API feasibility and evidence register

Status: researched planning baseline with explicit unresolved gates; no live account testing.
Research date: 24 October 2026 (Asia/Kuala_Lumpur).

## 1. Research method and confidence

Use official Meta documentation and Meta's official Postman workspace as primary evidence. The workspace identifies itself as Meta's official Threads API workspace ([workspace](https://www.postman.com/meta/threads/overview)). Direct Meta documentation requests repeatedly returned HTTP 429 or inaccessible-page errors during this review. Several Postman request pages returned only a shell or missing-request page; search-indexed collection content was available. This is an evidence limitation, not evidence that an endpoint is absent.

Labels used below:

- **Verified documentation**: a relevant official page body was retrieved.
- **Indexed official evidence**: official collection text was visible through search, but its direct page did not expose the complete body. Reconfirm before implementation of that capability.
- **Unverified gate**: exact current behavior, eligibility, approval, policy, or quota could not be established from accessible primary documentation. Keep affected functionality disabled.
- **Proposed design**: our application decision, not an API guarantee.

No development app, token, App Dashboard access, production account, billing setup, or actual permissions were inspected. This document does not certify App Review eligibility or legal compliance. Repeat verification against the current changelog and the actual app before enabling live collection or AI processing.

## 2. Feasibility matrix

| Objective | Official evidence and candidate API | Required access / confidence | Product decision |
| --- | --- | --- | --- |
| Connect account | OAuth authorization-code exchange, long-lived exchange, refresh | Threads user authorization; verified documentation [M1] | Supported baseline, subject to real OAuth test |
| Read connected profile | `GET /me?fields=...` | `threads_basic`; indexed official profile example [M3]; exact grant mapping recheck | Baseline; request minimal fields |
| Import connected user's posts | User Threads collection | `threads_basic`; indexed official collection [M4]; exact ownership contract recheck | Restrict to authenticated owned/managed posts |
| Read available replies | `GET /{thread_id}/replies` | Plan for `threads_basic` + `threads_read_replies`; indexed official examples [M2], permission mapping must be confirmed | Required capability gate before live importer |
| Read nested discussion | `GET /{thread_id}/conversation` | Same candidate reply-read grant; indexed official examples [M2] | Paginate, deduplicate; verify depth/order/visibility in staging |
| Count replying accounts | Derive from fields in collected replies | Reply example includes `username`; stable author ID is not established by that example [M2] | Stable ID if supplied; otherwise disclose provisional handle counts |
| Sentiment, stance, style, reply topics | Local derived analysis; OpenAI integration | Processing and onward-transfer review plus owner enablement | Analyze evidence only; no sensitive person-level inference |
| Other public profiles | Profile discovery is present in official collection scope catalog | `threads_profile_discovery` appears in indexed official evidence [M3]; exact endpoint contract/access tier/eligibility unverified | Optional, disabled pending verification |
| Other public posts | Collection includes public-profile Threads retrieval [M2] | Candidate discovery permission; complete-history availability not established | Optional authorized sample only, separately labeled |
| Official post/account insights | Collection includes insights [M4] and scope catalog [M3] | `threads_manage_insights`; exact metrics/windows/grant mapping recheck | Optional; no fabricated impressions or follower metrics |
| Broader keyword/topic search | Scope catalog lists `threads_keyword_search` [M3] | Approval and current coverage unverified | Outside baseline; no use as a substitute for posting history |
| Publish or moderate replies | Official collection has write operations [M2] | Additional publishing/moderation permissions | Out of scope; do not request write scopes |

Endpoint names in this matrix summarize official examples or are explicitly labeled candidates. They are not implementation specifications. Baseline and optional capabilities must be validated independently; passing OAuth alone does not prove read access.

## 3. Authentication and permissions strategy

The readable Meta collection demonstrates authorization-code exchange, long-lived exchange with `th_exchange_token`, refresh with `th_refresh_token`, and an example expiry of 5,184,000 seconds (60 days). It also contains an app access-token flow; an app token must not be treated as permission to read users' content. Use the returned expiry and actual grants rather than assuming every token lasts 60 days. [Meta authorization collection](https://www.postman.com/meta/threads/documentation/dht3nzz/threads-api) [M1]

Proposed flow:

1. A signed-in account manager starts a connection using a CSRF-protected action. Create random, one-time OAuth state tied to user, organization, intended operation and a ten-minute expiry; store only its hash.
2. Redirect to the provider's documented Threads authorization window using the exact allowlisted callback URI and least-privilege scopes. Confirm current authorization host, scope syntax, and any supported PKCE requirements before implementation; do not assume Facebook Login or Instagram Login is interchangeable.
3. On callback, consume state atomically, validate the active session and membership, reject replay/errors, exchange the short-lived code server-side, and resolve the authorized account identity. A different returned account requires an explicit new connection, never silent reassignment.
4. Encrypt the token, record verified grants and expiry, exchange/refresh according to the current documented eligibility rules, and test minimal read capability. Distinguish denied permission, absent field, ineligible account, transient error, and an empty result.
5. Schedule refresh conservatively ahead of expiry with a per-account lock. Exact earliest refresh age, reauthorization intervals, permission expiry, and any separate discovery-grant lifetime remain gates. An expired/revoked grant requires reconnection; failed refresh must not loop forever.

Minimum planned grants: `threads_basic` and `threads_read_replies`. Optional grants: `threads_manage_insights` for compatible official metrics and `threads_profile_discovery` for verified public discovery. The official examples list several scopes together; this does not prove all are needed. Do not request `threads_content_publish`, `threads_manage_replies`, `threads_delete`, location or mentions scopes for the proposed read-only baseline. [Official scope examples](https://www.postman.com/meta/threads/request/k46c8l0/get-app-scoped-user-s-profile-information) [M3]

Development/test access and live access are separate gates. Confirm tester setup, current access levels, App Review, business verification if required, privacy-policy/deletion URLs, approved purpose and review evidence in the actual Meta app. Local installation roles cannot authorize a managed brand account unless its holder can complete the supported OAuth authorization flow.

## 4. Post and reply retrieval

Official indexed examples show separate replies and conversation endpoints with cursor paging and root/parent relationships. They include reply text, username, timestamp and permalink. The profile example exposes connected-account fields including ID, username, name, biography and profile-picture URL. Availability remains token/field dependent. [Reply examples](https://www.postman.com/meta/threads/documentation/dht3nzz/threads-api?entity=request-34203612-74fb48b1-ad1a-480e-b200-4dcdb8126a2f) [M2], [profile example](https://www.postman.com/meta/threads/request/k46c8l0/get-app-scoped-user-s-profile-information) [M3]

Design rules:

- Validate the root post belongs to a granted connected account using provider evidence before enqueueing replies. Never accept a pasted arbitrary root ID as sufficient authorization.
- Start with a bounded selection of owned posts; fetch only needed fields. Preserve remote IDs as strings and retain endpoint, scope, collection timestamp, API version, and parent/root IDs as provenance.
- Test whether top-level replies and conversation retrieval overlap, their current ordering/depth, supported time filters, approval/hidden-reply behavior and cursor expiry. Store explicit retrieval mode. Pending/ignored replies are excluded unless separately verified and approved.
- Follow opaque cursors via an allowlisted client-built endpoint; never request arbitrary `paging.next` hosts or retain token-bearing paging URLs.
- Commit a page and its checkpoint atomically. Replaying a page must be harmless. Re-run with a small overlap for new activity and revalidate selected objects within quotas; do not assume a cursor is a permanent change feed.
- Absence from a partial page does not establish deletion. A failed request is not a zero count. Hidden/deleted/private/ineligible replies may be inaccessible, and API totals may differ from imported text counts.
- A completed pagination pass means the requested accessible range was exhausted at that time. It is not a guarantee that every historical reply on Threads was retrieved.

## 5. Public profiles and other users' posting histories

Official indexed collection content establishes a discovery permission and a public-profile Threads retrieval operation, but this review could not retrieve a complete current primary specification for public discovery. Candidate routes `/profile_lookup` and `/profile_posts` require verification before use. Do not present their exact fields, eligibility thresholds, quotas, or advanced-access rules as confirmed here. [Official collection](https://www.postman.com/meta/threads/documentation/dht3nzz/threads-api?entity=request-34203612-74fb48b1-ad1a-480e-b200-4dcdb8126a2f) [M2]

The product must distinguish:

1. The connected account's own profile, requested through its grant.
2. An observed reply author handle, which alone does not grant biography or posts access.
3. A permitted discovery result, if the actual app has the required approval and that profile is eligible.
4. A bounded returned public-post sample, which may have gaps and cannot establish complete lifetime interests.

Do not pass an arbitrary replier ID to the connected-user posts endpoint or assume a public page is API-accessible. No follower graph, private posts, private contact details, linked private identities, inferred demographics, image-based identity analysis, or browser scraping is proposed. Profile images are not sent for analysis. Statements in biographies remain self-described and unverified. Optional public-post topics must show dates, number of retrieved posts, missingness, retrieval source and permission state; label them as topics in the collected sample.

## 6. Rate limits and resource use

Exact Threads read/discovery quotas and applicable usage headers were **not verified** from accessible primary documentation. Publishing quotas are not read budgets and are irrelevant to a read-only product. Do not hard-code third-party claims about requests/day, profile follower thresholds or keyword limits. Review [Threads overview](https://developers.facebook.com/docs/threads/) and [Graph rate limiting](https://developers.facebook.com/docs/graph-api/overview/rate-limiting/) before production activation; both are verification targets, not successfully read evidence here.

Proposed transport behavior: per-provider/app/account/capability throttles, one outbound call at a time initially, a conservative configurable ceiling, and a shared database `next_allowed_at`. Capture safe usage metadata when present. Interpret provider error codes as well as HTTP status; throttling may not always be HTTP 429. Pause work at documented limits and reschedule instead of sleeping in a worker. Confirm exact Meta headers and retryable codes during the API contract phase.

OpenAI limits vary by account/model and can constrain requests and tokens. For temporary throttling/overload, honor a valid `Retry-After`; otherwise use bounded exponential backoff with jitter. Billing/quota errors needing user action are blocked states. [OpenAI rate limits](https://developers.openai.com/api/docs/guides/rate-limits) [O3]

## 7. OpenAI integration

Proposed client: direct PHP cURL requests to the Responses API, with a configurable evaluated model, strict structured output, small bounded text inputs, and `store: false`. Avoid persistent conversations, remote tools, web search, provider file uploads and background mode in the baseline. Model selection and exact request schema are verified during Phase 4; no model name, price or latency is promised by this plan.

Structured Outputs supports schema-constrained results, but refusals and incomplete responses require separate handling. Schema validity does not establish factual correctness. [Official Structured Outputs guidance](https://developers.openai.com/api/docs/guides/structured-outputs) [O1]

For each item, supply the reply, necessary root/parent context, the explicit stance target, and opaque evidence references. Ask for sentiment, stance, communication-form labels, non-sensitive topics, short supporting excerpts, and an uncertainty reason. Require abstention when context or language is insufficient. Validate all references and quote spans against the supplied text; reject unauthorized attributes and unknown enum values. Text is untrusted data, never instructions. Disable tools and arbitrary actions. Model results cannot grant access, trigger external writes, or change retention.

OpenAI states API data is not used for model training by default unless opted in. Its default abuse-monitoring logs can retain customer content for up to 30 days, with documented exceptions; special retention controls require eligibility and approval. `store: false` does not mean zero provider retention. Review endpoint, model and caching behavior before activation. [OpenAI data controls](https://developers.openai.com/api/docs/guides/your-data) [O2]

Maintain per-run token/cost reservations and actual usage records, prompt/schema/model versions, and input hashes. Do not log complete prompts/responses by default. A timeout can leave an unknown remote outcome and repeat billing on retry; there is no assumed exactly-once provider guarantee.

## 8. Retention, deletion and onward processing

Meta terms, developer policies and deletion callback documentation were inaccessible during this review. No universal Meta retention period, automatic AI-processing permission, or exception for public data is asserted. Official verification targets are [Platform Terms](https://developers.facebook.com/terms), [Developer Policies](https://developers.facebook.com/devpolicy/), and [data deletion callback guidance](https://developers.facebook.com/docs/development/create-an-app/app-dashboard/data-deletion-callback/). Their exact current requirements must be recorded before real-data collection and external analysis.

Required review questions: Is this specific reply analytics/public-profile purpose allowed? Is transfer to OpenAI as a service provider allowed under the relevant grants/terms? What notices, agreements, geographic controls and deletion obligations apply? Which data must be refreshed/deleted after revocation, user requests, app closure, or source changes? What callback/deauthorization mechanisms apply to Threads? Public visibility and an owner's toggle do not answer these questions.

The proposed local defaults in [PROJECT_OVERVIEW.md](PROJECT_OVERVIEW.md) are maximum operational targets subject to stricter verified requirements. Purge source content and all attributable analyses, aggregates, reports, exports, queued work and caches. In-flight results must be discarded after deletion. Track backup expiry and replay deletion records before restoring access. Do not claim local deletion erases provider logs or files users previously downloaded.

## 9. Required verification gates

| Gate | Evidence required | Owner / timing | Fallback |
| --- | --- | --- | --- |
| G1: baseline contract | Current API version, OAuth hosts/parameters, ownership fields, minimal grants, paging and reply visibility; successful staging calls | Implementer + app owner, before Phase 3 live data | Mock fixtures only |
| G2: app access | App-role tests and approved live access for required permissions; review requirements recorded | App owner, before external-user pilot | Restricted authorized tester use only |
| G3: policy/retention | Read current official terms and record permitted purpose, processor transfer, notices, revocation/deletion/backup duties | App owner with appropriate privacy review, before real-data collection; transfer specifically before OpenAI | No real collection/transfer until resolved |
| G4: discovery | Current public-profile/post endpoints, fields, approval tier, eligibility, grant lifetime, quotas and allowed purpose | Implementer + app owner, before optional feature | Reply evidence only |
| G5: quotas | Current read limits, headers, provider error handling and measured app capacity | Implementer, before scheduled sync/pilot | User-triggered bounded imports after baseline gates |
| G6: OpenAI quality/cost | Available evaluated model, exact schema, budget, retention configuration, completion latency within 20 seconds | Implementer + owner, before Phase 4 live analysis | Pending/manual review; smaller items; report a hosting limitation if deadline cannot be met |

Closing a gate requires a dated entry here with official source, app permission evidence, redacted contract-test result, tester/account scope, API version, and responsible reviewer. Never record tokens or full private payloads. Refresh evidence after a permission/version/policy change and before release.

## 10. Primary evidence register

| ID | Source | Observed access and role |
| --- | --- | --- |
| M1 | [Meta Threads authorization collection](https://www.postman.com/meta/threads/documentation/dht3nzz/threads-api) | Readable official body: code exchange, long-lived exchange, refresh, app-token examples |
| M2 | [Meta Threads reply/public-post collection examples](https://www.postman.com/meta/threads/documentation/dht3nzz/threads-api?entity=request-34203612-74fb48b1-ad1a-480e-b200-4dcdb8126a2f) | Official search-indexed text; direct page shell; reply examples and public-profile retrieval heading |
| M3 | [Meta app-scoped profile example](https://www.postman.com/meta/threads/request/k46c8l0/get-app-scoped-user-s-profile-information) | Official search-indexed fields/scope catalog; direct route returned missing request |
| M4 | [Meta read/manage collection](https://www.postman.com/meta/threads/folder/2tx5bse/read-and-manage-threads) | Official indexed operation list; precise contracts require recheck |
| O1 | [Structured Outputs](https://developers.openai.com/api/docs/guides/structured-outputs) | Official page read; schema and refusal handling |
| O2 | [OpenAI data controls](https://developers.openai.com/api/docs/guides/your-data) | Official page read; training and retention caveats |
| O3 | [OpenAI rate limits](https://developers.openai.com/api/docs/guides/rate-limits) | Official page read; retry/backoff and quota handling |

This register deliberately separates endpoint evidence from access approval and policy permission. Do not implement unauthorized scraping or restriction bypasses when an API capability is absent.
