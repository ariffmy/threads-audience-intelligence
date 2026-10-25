# Phase 3A — Product feasibility

Assessment date: **25 October 2026 (Asia/Kuala_Lumpur)**. Baseline `dfba94e`. Findings use the dated [official research register](PHASE3A_API_RESEARCH.md) and [capability matrix](PHASE3A_CAPABILITY_MATRIX.md). No app approval, real token/data access or legal compliance certification is implied.

## 1. Assessment of the original objective

Objective: analyze people who reply to our Threads posts and identify their frequently discussed topics.

**The unrestricted individual-history version is not a supportable product promise.** Meta documents access to owned discussions and a separate conditional public-discovery interface. It does not make every replier's history available, establish a stable author ID in reply responses, or authorize persistent personal interest profiles merely because the underlying text is public.

The current permissions reference, updated 19 October 2026, places these capabilities in business-app use cases and limits discovery to competitor analysis. Platform Terms §3.a adds valid-consent and permitted-purpose constraints on building profiles. This is a material finding relative to the older planning evidence gaps. A private one-admin installation might be an internal business tool, but its deployment size alone establishes neither that status nor a permitted analysis purpose. Advanced Access approval does not override permitted-use restrictions. See [T12](https://developers.facebook.com/documentation/development/permissions), [T15](https://developers.facebook.com/terms/dfc_platform_terms/) and [T16](https://developers.facebook.com/devpolicy/).

**A narrower owned-discussion product is technically feasible, subject to purpose/access/privacy gates:** help the authorized account owner manage accessible replies and report non-identifying discussion topics within an explicit collected sample/window. This describes supplied discussion evidence, not what its authors generally believe or discuss elsewhere. It is the recommended fallback, not a declaration that the current app is already approved.

## 2. What replies alone can establish

| Potential output | Evidence and appropriate wording | Limitations |
| --- | --- | --- |
| Recurring discussion topics | “In the accessible replies collected for these owned posts during this window, these subjects recur.” Count distinct retained reply objects under a reviewed taxonomy. | Not a population estimate, complete discussion or individual lifetime interest. Multi-topic totals can exceed reply totals. |
| Topic change across collected periods/posts | Compare compatible collected samples with explicit denominators/coverage. | Missing collection, changed accessibility and small samples can explain differences. Do not sum distinct-author counts across windows. |
| Conversation structure | Root/parent relationships, immediate versus nested replies, chronology where supplied. | Conversation is flattened and root-only; missing/inaccessible parents stay unknown. |
| Questions and communication form in supplied text | A later approved text classifier could identify a question or disagreement with cited context. | Classifier/model/quality approval is a later phase. Do not turn form labels into enduring personality, intelligence, health or demographic assessments. |
| Owner's authorized reply-management view | Display currently accessible reply text and available public handle/permalink under the actual grant. | Raw management access is not blanket permission to publish/export identifying analytics. A missing handle is not an anonymous person's stable identity. |
| Observed account/handle counts | Only the identifier quality actually supplied. Provisional handles or unknown authors must be separate. | No verified stable replier user ID in the inspected projection; an account is not necessarily a person. |

Raw text can include sensitive disclosures or identifying facts. An aggregate output is not automatically anonymous: small cells, quotations, unique phrases, timestamps and drill-downs can identify an author. Pseudonymous evidence IDs or hashing a username do not by themselves meet an anonymity condition.

Proposed aggregate release policy: use a reviewed non-sensitive topic taxonomy; suppress small/identifying cells and potentially identifying quotations; bound post/window combinations; review residual reidentification risk before export. Any local minimum-cell rule would be an application safeguard, **not** a Meta-approved anonymity threshold. An owner-only evidence viewer must independently satisfy the reply-management purpose; splitting screens does not create a policy exception for linked analytics.

No topic-classification code/model, audience dashboard or export is implemented or authorized in Phase 3A. Phase 3B should supply the authorized data/collection foundation with fake fixtures first, not start Phase 4 analysis.

## 3. Public profile information

The connected user's own profile is a basic-permission capability. A replier's biography is not part of the reply projection. The separate exact-username discovery API documents public profile statements, verification, follower totals and some recent engagement counts, but eligibility/access/purpose conditions apply. Its biography/picture field names differ from those of `/me`, and no stable user ID is documented there (T05).

For this product, keep replier profile lookup, biography enrichment, profile-picture downloading/analysis and engagement enrichment **off**. A public biography is self-description, not a verified background fact. Follower count is not expertise, demographic data or authority; an image is not evidence of identity. Discovery approval for a legitimate competitor-analysis tool would still not authorize relabeling arbitrary repliers as competitors.

## 4. Historical posts from reply authors

| Scenario | Technical position | Product decision |
| --- | --- | --- |
| Connected account's own posts | Documented owned-list API with pagination/date controls. | Candidate Phase 3B owned-root selection after gates; not arbitrary-account history. |
| Eligible public profile through discovery | Documented public-post list; exact handle, public/100-follower/access restrictions. | Potential bounded sample only if a separate, genuinely permitted purpose and approval are established. Excluded from recommended 3B. |
| Non-Meta target under current Standard Access | Discovery guide restricts the tier to certain official Meta profiles. | Do not assume a tester role unlocks every other public account. |
| Private profile or public profile below threshold | Discovery excludes these targets. | Unsupported through that interface; return unavailable, never zero interests. |
| Complete history of every replier | Exclusions, missing identity and unverified completeness prevent this guarantee. | Do not advertise or design the baseline around it. |
| Arbitrary user ID passed to ordinary owned-post endpoint | Ordinary list is app-scoped-user-only. | Deny; do not use it as a discovery substitute. |

Public-post date parameters do not certify that all historical posts remain visible, that every post was returned or that a handle remained the same person over time. If an optional permitted sample is approved later, label its source, dates, sample size, missingness, access tier, identity uncertainty and expiry; keep it separate from replies to owned posts. An unavailable lookup is not evidence that the person has no topics or no account.

Do not fill gaps with scraping, browser/session credentials, private endpoints, alternate apps/tokens to evade limits, keyword-search harvesting or uploaded scraped histories. Public visibility, a local role and an owner's toggle are not independent authorization or processing exemptions.

## 5. Compliant fallback proposal

Proposed product description:

> Summarize recurring non-sensitive topics in the accessible replies to the Threads posts you are authorized to manage, with a stated collection window, sample coverage and uncertainty.

This is our proposed scope statement, not a quotation or policy approval from Meta.

The future workflow selects a proved-owned root and bounded window, imports permitted text/tree evidence, discloses missingness and produces only approved aggregate discussion outputs. It works without biography/history access and can remain useful when authors are unresolved. Keep the operational owner view scoped to authorized discussions. Avoid an “interests of this person” page, author ranking/targeting, demographic/psychological inference, private-identity linking or follower graph.

Require business-purpose eligibility, basic/read-replies grants, necessary app access and a privacy/retention/deletion review before real collection. Approve any external processor separately before transferring text. Retain only necessary evidence; expire attributable derivatives with their sources and block in-flight results after deletion. The fallback is designed around documented narrower permissions; it still needs the app-specific review in the decision report before it can be called compliant in operation.

## 6. Features that remain disabled

- Automatic replier biography/public-history lookup or persistent account/person interest profiles.
- Private/under-threshold discovery, completeness claims, stable identity inferred from handles or media IDs.
- Keyword/mention harvesting, arbitrary public media imports, publishing/moderation, remote deletion and broad webhooks.
- Follower graphs, sensitive attributes, demographics, personality/health/belief/background scoring and image-based identity analysis.
- OpenAI/external text transfer, model-based analysis, reports and identifying analytics exports until their separate phases and purpose/quality/processor gates pass.

No disabled feature is required for owned-reply collection. A future lack of discovery approval should produce an explicit unavailable capability, not a hidden data-collection workaround.

## 7. Decision implication

Proceed only to consideration of a **fixture-first, owned-discussion Phase 3B**. The broader original replier-history/profile objective is a no-go as a guaranteed or currently authorized capability. Outstanding business-purpose, access, consent/processing, callback, retention/backup and runtime evidence is listed in [PHASE3A_DECISION_REPORT.md](PHASE3A_DECISION_REPORT.md). Phase 3B has not begun.
