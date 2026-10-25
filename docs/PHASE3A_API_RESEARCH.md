# Phase 3A — Threads API research

Verification date: **25 October 2026, Asia/Kuala_Lumpur**. Repository baseline: `dfba94e`. Scope: public documentation research and future integration design only. No authenticated Graph requests, app-dashboard inspection, tokens, personal data, application changes, migrations, dependency installation or deployment.

## 1. Method and evidence boundaries

The repository was clean at the expected Phase 2 commit. Reviewed AGENTS, project overview, architecture, database design, API feasibility, development phases and Phase 2 completion report; also inspected the existing queue/privacy contracts read-only. Phase 2's reported 86-test result is historical evidence, not a new Phase 3A execution or live permission test. Its PHP 8.5.12/MariaDB 10.11/target-host gaps remain open.

Direct web retrieval of Meta documentation returned HTTP 429/inaccessible results. Normal public browser navigation successfully loaded Meta's current `/documentation/` guides and policies without login. The conclusions below use those rendered official pages. Meta's official Postman workspace was checked as a secondary official source; its collection warns that it can lag the developer changelog. Third-party search results were not accepted as technical or policy evidence.

All sources below were read on the verification date. A page's displayed update date is separate from our verification date. Documentation establishes a published contract, not that this installation has an approved purpose, Advanced Access, a working grant, complete data, or a successful staging call. The four matrix classifications are defined in [PHASE3A_CAPABILITY_MATRIX.md](PHASE3A_CAPABILITY_MATRIX.md). No G1–G6 live/policy gate is closed by this desk research.

## 2. Official source register

| ID | Official source | Displayed update | Evidence used |
| --- | --- | --- | --- |
| T01 | [Threads overview](https://developers.facebook.com/documentation/threads/overview) | 22 Dec 2025 | API hosts; general call-count and time formulas; distinct publishing quotas. |
| T02 | [Get started](https://developers.facebook.com/documentation/threads/get-started) | 30 Jun 2026 | Minimum permissions, testers versus non-role users, token and permission lifetimes. |
| T03 | [Get access tokens](https://developers.facebook.com/documentation/threads/get-started/get-access-tokens-and-permissions) | 12 Aug 2026 | Authorization URL, exact redirects, single-use code and POST exchange body. |
| T04 | [Long-lived tokens](https://developers.facebook.com/documentation/threads/get-started/long-lived-tokens) | 27 Mar 2025 | Exchange/refresh, 24-hour minimum token age, expiry and private-profile token refresh. |
| T05 | [Threads profiles](https://developers.facebook.com/documentation/threads/threads-profiles) | 13 Apr 2026 | Own profile versus exact-username public discovery; fields, eligibility and lookup limit. |
| T06 | [Retrieve user posts](https://developers.facebook.com/documentation/threads/retrieve-and-discover-posts/retrieve-posts) | 14 Apr 2026 | Owned list, public-profile list, single media, ownership field restriction. |
| T07 | [Replies and conversations](https://developers.facebook.com/documentation/threads/retrieve-and-manage-replies/replies-and-conversations) | 2 Feb 2026 | Direct versus flattened replies, root-only conversation, field missingness/identity limits. |
| T08 | [User reference](https://developers.facebook.com/documentation/threads/reference/user) | 16 Dec 2025 | Date/limit/cursor parameters for owned and public-profile post lists. |
| T09 | [Reply management reference](https://developers.facebook.com/documentation/threads/reference/reply-management) | 13 Feb 2026 | Reply/conversation fields and mutually exclusive cursors. |
| T10 | [Debug reference](https://developers.facebook.com/documentation/threads/reference/debug) | 18 Aug 2026 | Same-app token inspection, supported auth and separate token/data-access expiries. |
| T11 | [Threads changelog](https://developers.facebook.com/documentation/threads/changelog) | 18 Aug 2026; latest visible entry | App-token debugging change, token_type addition, earlier discovery/author-field changes and v1.0 history. |
| T12 | [Permissions reference](https://developers.facebook.com/documentation/development/permissions) | **19 Oct 2026** | Current business-app/allowed-purpose rules, scope dependencies, Advanced Access/business verification and permission-specific review evidence. |
| T13 | [App Review process](https://developers.facebook.com/documentation/resp-plat-initiatives/individual-processes/app-review) | 10 Apr 2024 | Role-only versus non-role use; accessible, testable review submission. |
| T14 | [Create a Threads app](https://developers.facebook.com/documentation/threads/get-started/create-an-app) | 4 Jun 2025 | Threads use case, callback settings, privacy/deletion URLs, publication and optional webhooks. |
| T15 | [Platform Terms](https://developers.facebook.com/terms/dfc_platform_terms/) | 3 Feb 2026 | Sections 3, 4, 5, 6 and 12: purpose/consent, retention/deletion, notices, processors, security and derived data. |
| T16 | [Developer Policies](https://developers.facebook.com/devpolicy/) | 3 Feb 2026 | General sections 1–3: allowed usage, user trust, support and problematic personal-assessment products. Ads/Instagram-specific sections were not treated as Threads-specific grants. |
| T17 | [Data deletion callback](https://developers.facebook.com/documentation/development/create-an-app/app-dashboard/data-deletion-callback) | 7 Nov 2025 | Generic Meta HTTPS signed-request callback and status response. Its examples/testing describe Facebook; Threads identifier/delivery details still require a contract test. |
| T18 | [Threads troubleshooting](https://developers.facebook.com/documentation/threads/troubleshooting) | 14 Jul 2025 | Publishing-limit endpoint covers publishing/reply publishing/deletion/location usage, not a general read-quota counter. |
| P01 | [Meta's official Postman workspace](https://www.postman.com/meta/threads/overview) and [collection](https://www.postman.com/meta/threads/documentation/dht3nzz/threads-api) | Workspace visible 23 Oct 2026; collection may lag | Official provenance and corroborating examples only; newer developer docs take priority. |

## 3. Findings for the twelve research questions

### OAuth, permissions and token lifecycle

T03 documents a browser authorization-code flow at `https://threads.com/oauth/authorize`. Its parameters are `client_id`, exact configured `redirect_uri`, requested `scope`, `response_type=code` and optional provider `state`. Our application must require random, expiring, one-time, session-bound state even though Meta labels it optional. Codes last one hour and are single-use. Exchange uses a server-side POST to `https://graph.threads.com/oauth/access_token`, with the documented form-body parameters. PKCE is not established by the inspected contract; do not invent support or silently claim it is unavailable.

The read-only minimum is `threads_basic` plus `threads_read_replies` (T02/T12). Profile discovery additionally needs `threads_profile_discovery`; it is a separate purpose/access gate. Do not request publishing, moderation, deletion, insights, mentions, keyword or location scopes for the baseline.

T02/T04 describe one-hour short tokens, sixty-day long tokens, and refreshing only an unexpired long token at least 24 hours old. Use returned expiry metadata rather than a hardcoded sixty-day timestamp. Token refresh and permission-grant extension are different: T02 describes ninety-day grants, extension on refresh for public-profile users and renewed consent for an expired private-profile grant. T04 allows private-profile **token** refresh; this does not contradict the separate grant-expiry restriction. T10 exposes `expires_at` and `data_access_expires_at` separately. These facts do not prove the actual app's grants.

### Profiles and owned posts

T05 limits the ordinary user profile to the app-scoped user. It documents `id`, `username`, `name`, `threads_biography`, `threads_profile_picture_url` and `is_verified`. The public-discovery response uses different names (`biography`, `profile_picture_url`) and does not document a stable user ID. Keep these projections separate.

The public-profile example includes `replies_count`, while its inspected field table omits it. Do not promote that example-only discrepancy to a guaranteed field; the matrix uses the explicit table's profile/recent-engagement subset. Guides and older references also differ in some media-field lists; request a minimal reviewed projection and verify it in the actual contract before activation.

T06/T08 document `GET /{threads-user-id}/threads` (including `/me/threads`): a paginated owned list with selected fields, date bounds and cursors. The documented limit defaults to 25 and has a maximum of 100. `owner` is restricted to owned top-level posts. A single public media object has a different Advanced Access rule; it does not establish ownership or unlock another user's ordinary user-post list. Current examples use `v1.0`; T01 recognizes both graph.threads.com and graph.threads.net. Do not substitute Facebook Graph API version numbers.

### Replies and author information

T07/T09 distinguish immediate replies (`/{media-id}/replies`) from root-thread flattened nested replies (`/{media-id}/conversation`). Their fields support discussion reconstruction, text, time, visibility and provenance. Names/permalinks can be unavailable for other private users; text itself can be absent on media replies. Verification/picture fields are documented only for direct replies. Neither a reply's media `id` nor `root_post.id` identifies its author. No stable replier user-ID contract was found in the checked fields. `owner` on owned root posts is not a workaround.

Conversation traversal does not grant invisible/deleted/private content or guarantee a complete discussion. The reply reference documents `before`/`after` and `reverse`; it does **not** establish the owned-list `since`/`until`/`limit` contract for these endpoints. Treat those controls as unverified for media replies; use bounded response handling and explicit coverage, not guessed parameters. Pending-reply moderation remains excluded.

### Public discovery and other users' histories

T05/T06 document `GET /profile_lookup?username=...` and `GET /profile_posts?username=...`, with exact usernames, both basic/discovery permissions, public profiles and a **100-follower** minimum. Standard Access is limited to certain official Meta accounts; the guides list examples, not an exhaustive permanent list. Both describe 1,000 requests per user in a rolling 24 hours. Whether they share one quota bucket is not established here: never budget 2,000 by adding them. T08 documents pagination/date/limit controls for public posts. The public post projection excludes `owner`.

This establishes conditional access to eligible public post samples, not every replier's posting history, immutable identity, complete lifetime coverage, private profiles or a follower graph. Critically, T12 permits discovery for competitor analysis in a business app. Replier-interest enrichment is not automatically that purpose, and Advanced Access cannot widen the allowed purpose. Keep it disabled for this product unless an appropriate, genuinely applicable permitted use and approvals are established; do not relabel audience profiling as competitor analysis.

### Quotas and errors

T01 describes a rolling app/user call allowance based on `4,800 × impressions`, with an impressions minimum of 10, plus CPU/total-time formulas (`720,000 × impressions`, `2,880,000 × impressions`). This is a published accounting formula, not a measured capacity promise. Its precise operational interpretation and current response telemetry require staging evidence. T18's 250-post/1,000-reply publishing examples are **write quotas**, not read limits. No specific read-usage header, exhaustive read error/subcode catalog or general read-counter endpoint was established. Keep G5 open despite this documentation progress.

### Retention, deletion and processing purpose

T15 sections 3.a/3.d, 4 and 5 constrain profiling, permitted purpose, necessary retention, deletion on applicable requests/triggers, privacy notices and service-provider agreements. Its definition includes derived/aggregate data. It supplies no universal ninety-day source or thirty-day backup allowance. Existing project periods remain provisional upper bounds and may need shortening. Local deletion cannot certify provider-log or downloaded-file erasure.

T14 explicitly includes deauthorization and deletion settings for Threads. T17 supplies a generic signed-request/status protocol, but its Facebook ASID example is not evidence that Threads callbacks use the same identifier or secret. The actual Threads signing key, delivery, identifier mapping and retry contract remain unverified. A real-data pilot needs this gap resolved, an accessible deletion mechanism and complete source/derivative purge/restore coverage.

Sending reply text to an external model is processing/sharing, not merely enabling a local option. The current terms' processor conditions, the actual purpose, notice/consent and provider arrangements require review. No onward-transfer approval or general AI permission was established. G3/G6 remain open; Phase 3B should contain no OpenAI integration.

### App Review

T02/T13 allow role/tester testing within relevant limitations; non-role access requires approved permissions and publication. T12 requires business verification for Advanced Access and describes permission-specific use-case/screencast evidence. It also restricts these Threads permissions to business apps. A one-admin private installation is an application deployment choice, not proof of Meta business-app eligibility or an exemption from allowed-purpose rules. Actual app type, role setup, reviewed purpose, access tier and any data-handling/renewal requirements were not inspected.

## 4. Gate disposition

| Existing gate | Phase 3A progress | Remaining condition |
| --- | --- | --- |
| G1 contract | OAuth/owned posts/replies/field and version examples directly documented. | Current app configuration; real authorized contract tests; identity, reply visibility/paging and callback details. **Open.** |
| G2 access | Tester/non-role/Advanced Access rules documented. | Actual app roles, publication/permission tier, business eligibility and required approvals. **Open.** |
| G3 purpose/privacy | Current terms and allowed-use rules now retrieved. | Business purpose fit, any profiling consent, notices/processors, deletion/restore proof. Reading policy is not approval. **Open.** |
| G4 discovery | Endpoints, fields, threshold and published limits now documented. | Product-purpose mismatch, relevant access approval, eligibility and authorized contract tests. **Disabled/open.** |
| G5 quotas | General formula and discovery limits documented. | Actual headers/errors/bucket behavior, reservations and measured bounded operation. **Open.** |
| G6 AI | Not evaluated in this phase. | Permitted transfer, provider terms, quality, budgets and target-host latency. **Open/disabled.** |

See [PHASE3A_DECISION_REPORT.md](PHASE3A_DECISION_REPORT.md) for decisions, [PHASE3A_PRODUCT_FEASIBILITY.md](PHASE3A_PRODUCT_FEASIBILITY.md) for the fallback and [PHASE3A_INTEGRATION_DESIGN.md](PHASE3A_INTEGRATION_DESIGN.md) for proposed implementation contracts. Stop after Phase 3A.
