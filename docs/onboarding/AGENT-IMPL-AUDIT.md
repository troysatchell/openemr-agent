# Audit — AI Product Impact (streamlined)

> The AI-relevant subset of the five-audit review, re-ranked **most → least
> critical to the clinical co-pilot**. Everything that doesn't directly affect
> the AI product has been removed.
>
> The comprehensive five-audit version (security · performance · architecture ·
> data quality · compliance, with full evidence) is preserved at
> [`AUDIT_FULL.md`](AUDIT_FULL.md) — delete it if you don't want it. Original
> finding IDs (`S#`/`P#`/`D#`/`C#`) are kept so [`ROADMAP.md`](ROADMAP.md) and the
> full audit stay cross-referable.
>
> Read format per item: **finding → why it matters to the AI → action / roadmap phase.**

---

## Tier 0 — GATING · nothing ships past this

### C5 · Sending PHI to an LLM
There is **no runtime PHI→LLM flow today** (the only "Claude" in the repo is
dev-time code generation — source code isn't PHI). The moment an agent sends
patient data out, obligations attach: a **signed BAA + Zero-Data-Retention is
mandatory**; prompts must be **minimum-necessary** (the overnight whole-panel
pre-chart is the single biggest exposure — it includes no-shows he'll never see);
and **every disclosure must be logged**. Reliable de-identification is *not*
achievable here (see D7/D8) — don't architect v1 around it.
→ **Roadmap Phase 1. Gates every PHI feature.**

---

## Tier 1 — CRITICAL · threatens the AI's core correctness & safety

### D8 · Duplicate patients (`pid` ≠ person)
`patient_data` is unique only on surrogate keys (`pid`, `uuid`); nothing prevents
the same human existing as multiple `pid`s. So **"bleed one patient's data into
another's" is a data-model reality**, not merely an LLM hallucination — the agent
can inherit a split/merged identity before it reasons at all.
→ identity/dedup layer, **Phase 2**.

### D7 · Dual identity; `uuid` nullable (31/35 tables), batch-backfilled
Provenance and citations require reliable identity, but `uuid` is often absent
(backfilled non-deterministically) and `pid` ≠ person. **The provenance feature
and identity remediation are the same project.**
→ **Phase 2**.

### D0 · Strict mode disabled (`SET sql_mode=''` on every connection)
Truncation, zero-dates, and coerced values enter silently — the **root cause of
"garbage-in."** A clean, fluent AI summary laundering bad source data makes wrong
data look *more* authoritative, not less.
→ data-trust substrate, **Phase 2**.

### D10 · Soft-delete via `activity` flag (not hard delete)
Discontinued meds that were never deactivated **look active on the default read** —
this *is* the "inaccurate med list" the agent would confidently summarize. Always
filter `activity`.
→ **Phase 2**.

### D9 · Polymorphic `lists` + free-text coding (the seam substrate)
Meds/problems/allergies share one dirty, near-duplicate-prone table; labs live
elsewhere. A **drug-drug / drug-lab interaction exists only *across* these
sources** — the seam. Reconcile and interaction-check in **one synthesis pass**;
never isolated "summarize meds" / "summarize labs" sub-agents.
→ **Phase 2** (roadmap principle 4).

---

## Tier 2 — HIGH · shapes how the AI reads, integrates, and stays safe

### Clean read path *(architecture + data-quality positive)*
The **FHIR/REST API is the one validated, paginated, uuid-resolved read surface.**
The AI reads here — never raw tables, never `globals.php`. This is the single most
important architectural choice for correctness *and* latency.
→ **Phase 1/2** (roadmap principle 3).

### Integration seams
Plug in as a **custom module** (`openemr.bootstrap.php` → subscribe events + add
routes via `RestApiCreateEvent`). No core edits; the EventDispatcher is the shared
backbone.
→ **Phase 1**.

### S5 · Per-route authorization is manual
Every route the AI adds via `RestApiCreateEvent` **must call
`request_authorization_check`** — there is no default-deny gate, so an omitted
check = an unprotected PHI endpoint.
→ **Phase 1**.

### C1 · Audit logging
Strong and granular, with an optional **ATNA external-audit export** — but **not
tamper-resistant** (logs live in the audited DB). The AI must add an
"external-AI disclosure" category; real deployments should enable ATNA/SIEM
shipping.
→ **Phase 1**.

### S1 / S2 / S3 · Breach precursors
API error body leaks `$e->getMessage()` (**S1**); core session cookie is not
HttpOnly (**S2**); `cookie_secure` defaults off (**S3**). A new external
integration amplifies these, and a PHI leak becomes a **reportable breach**. Close
before exposing the AI surface.
→ **Phase 1**.

### D1 · `NOT NULL DEFAULT ''` (318 columns)
"NOT NULL" guarantees the column exists, **not that it has a value** — empty
string = missing (names, `sex`, identifiers). The AI must treat `''` as unknown
(`!= '' AND IS NOT NULL`), or it will act on false-complete data.
→ **Phase 2**.

---

## Tier 3 — MEDIUM · context & secondary

### Why to avoid the legacy tier (P1 / P2 / P4)
`globals.php` loads the whole settings table per request; the frameset UI
multiplies it; `BaseService::search()` is unbounded. These are the concrete
reasons the AI reads via FHIR, not the legacy path.

### D4 / D3 · Boolean chaos & config-optional fields
Booleans appear as `tinyint`, `'YES'`, `'yes'`, `enum('Yes','No')` — **normalize
per column.** Layout/`list_options`-driven field presence is
**config-dependent, not schema-guaranteed** — completeness is a runtime property.
→ **Phase 2**.

### Pre-charting as a background job (S6 / P7)
If the overnight pre-chart runs via `background_services`, note that table is
**executable config** (govern who can insert rows) and the "piggyback" trigger is
coupled to user activity (no logins → work stalls, then stampedes).
→ **Phase 3**.

### P5 / P6 · Write & read cost
Disclosure logging adds synchronous audit writes — consider async/batched.
Per-patient `lists` reads want a composite `(pid, type)` index.
→ **Phase 2/3**.

### CryptoGen *(positive)*
Proper authenticated encryption (AES-256-CBC + HMAC-SHA384) is available if the AI
needs to store anything sensitive at rest.

---

## Rules for the AI consuming this data *(from the data-quality audit)*

1. **Treat `''` as unknown** — especially names, `sex`, identifiers (D1).
2. **Trust `pid`, not `uuid`** — expect absent uuids; resolve via `pid` (D7).
3. **Normalize booleans per column** — accept `1/'1'/'yes'/'YES'/'Yes'` as true (D4).
4. **Never equate a `pid` with a unique person** — dedupe by demographics (D8).
5. **Validate every date defensively** — NULL, `'0000-00-00'`, free-text (D0/D6).
6. **Always apply `activity`/`deleted` filters** — or read stale data as current (D10).
7. **Don't assume Unicode/utf8mb4** — encoding is deployment-dependent (D5).
8. **Read via FHIR/REST, not raw tables** — it's validated, paginated, uuid-resolved.

---

*Streamlined from the full five-audit review for the AI product. For evidence,
positives, profiling queries, and the non-AI findings (login internals, auth
model, general performance tuning), see [`AUDIT_FULL.md`](AUDIT_FULL.md).*
