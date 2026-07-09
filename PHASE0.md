# PHASE0.md — Founder-run, in-house validation pass

> **Read this first — the whole document is a claim to review.**
> Nothing here is signed off. This pass was **founder-run and in-house**
> (decided 2026-07-07; `ARCHITECTURE.md` §7, `USERS.md` §2). It was assembled
> with AI assistance, which makes it *doubly* a draft: a model cannot validate
> a founder-authored persona, and a founder cannot validate his own hypothesis.
> What this pass can do is **sharpen** hypotheses, **rate** their confidence,
> **rank** them by exposure, and **ground** the deterministic critical-subset
> candidates in named published references. What it cannot do is **arm the
> accuracy gate** or **resolve R12**. Both stay open. See §4.
>
> **Update 2026-07-08 (after the pass):** two things changed downstream of
> this document, recorded here so it reads coherently. (1) The requirements
> PDF was checked: **H1, H2, and H10 are program-stipulated** — the case
> study hands us the 90-second moment, the multi-turn agent shape, and
> answer-in-seconds — so they are reclassified in §1 and removed from the
> exposure ranking; their break cases stay named as *real-world* tension
> under R12. Likewise **C5's BAA/ZDR is stipulated by the program** (demo
> data only; act-as-if no training). (2) The **acting clinical-governance
> owner** (founder — a named stand-in, §3c) **signed off the §3a
> reference-grounded labels**, as one decision with the detector tables, so
> the accuracy gate is now **ARMED on §3a only**; §3b stays unadjudicated
> and never gates. R12 itself stays open for the real world — see §4.
>
> **Epistemic contract (the bright lines this pass was held to):**
> - No clinical threshold, panic value, interaction pair, or golden-chart label
>   was authored from the model's own knowledge. Every clinical value in §3 is
>   **transcribed from a named published reference with a citation**, or marked
>   **UNSOURCED** with the value left blank.
> - Nothing here is adjudicated. Every item is a **CANDIDATE for human
>   sign-off**.
> - The §3a candidate labels were derived **independently of the detector DRAFT
>   tables** (sourced from published references *before* the detector code was
>   read), so the gate can test whether those tables are *right*, not merely
>   whether the code runs them.
> - The eye-contact-as-loyalty-driver assumption stays **FLAGGED**, not
>   resolved (§1, row H4).

---

## Section 1 — Assumption ledger

Each `USERS.md` "→ Test by" prompt (and the ranked hypotheses in `USERS.md` §2)
is turned into a row: **hypothesis · confidence · evidence basis · what breaks
downstream if wrong · the strongest case it is false.** Confirmation is the
failure mode of self-review, so every row carries an explicit break case;
several rows come out **likely-wrong on purpose** — if they had all confirmed,
the pass would have been done wrong.

**Evidence-basis classes** (deliberately conservative):
- **Population literature (cited)** — a named, dated source establishes the
  *phenomenon* exists in the population. It does **not** establish that it
  manifests in *this* user. Per `USERS.md` §1: "Real as phenomena; whether and
  how they manifest in our user is unverified." So a literature-backed row is
  capped at **medium** confidence for the *persona-specific* claim.
- **Analogy** — reasoned from other tools / general UX, no user data.
- **Pure guess** — stipulated by the founder, no external support.

### 1.1 The rows

---

**H1 — [PROGRAM-STIPULATED 2026-07-08] The ~90-second between-patient moment is the felt pain the product should target.**
- **Status:** the case study *hands us* the 90-second moment — confirmed in
  the requirements PDF. In-project, this is a given to build against, not an
  open hypothesis; it is removed from the exposure ranking (§1.2). The break
  case below is kept verbatim as the named **real-world** tension, carried
  under R12 for the clinician interview — a stipulation binds the project,
  not reality.
- **Confidence (as a real-world claim):** low–medium.
- **Evidence basis:** Analogy + population literature that arguably *cuts against
  it*. Sinsky et al. 2016 (Ann Intern Med 165(11):753–760) found ambulatory
  physicians spend **49% of the day on EHR/desk work vs 27% in direct patient
  contact** — i.e. the largest measured time sink is *documentation*, not
  between-room orientation.
- **What breaks downstream if wrong:** Everything. UC1–UC4, the snapshot
  concept, the entire latency budget (§2), Phase 3. If the real bottleneck is
  documentation, inbox, or prior-auth, the product is optimized for the wrong 90
  seconds. Named risk: **R12** (`ARCHITECTURE.md` §5).
- **Strongest case it is false:** The one time-and-motion study we can cite
  points at documentation as the dominant burden, not orientation. "Pajama
  time" (which `USERS.md` §6 treats as the emotional nerve) is a *documentation*
  problem — a between-patient snapshot does not close a single note. A tool that
  shaves 30 seconds of orientation while the physician still spends the evening
  writing notes has not touched the pain he actually feels. The persona may love
  the snapshot and still churn because it didn't move the number that hurts.

---

**H2 — [PROGRAM-STIPULATED 2026-07-08] A multi-turn conversational agent is the right shape (not a better chart view / dashboard / sorted list).**
- **Status:** the case study specifies a multi-turn conversational agent —
  confirmed in the requirements PDF. In-project, the shape is a given;
  removed from the exposure ranking (§1.2). The break case below stays as
  the named real-world tension under R12 (the `USERS.md` §5 kill-switch
  question remains the right one to put to a real internist).
- **Confidence (as a real-world claim):** low–medium.
- **Evidence basis:** Pure guess, explicitly flagged as such in `USERS.md` §2
  ("does 'agent' read as one more thing to babysit?") and §5.
- **What breaks downstream if wrong:** The core architectural thesis
  ("it's an agent, not a dashboard," `ARCHITECTURE.md` Executive Summary),
  multi-turn context (§3.5), UC2, and the justification for the whole
  orchestration layer. If he wants a screen, we built the wrong interaction
  model.
- **Strongest case it is false:** Every incumbent EHR trained him to distrust
  software that "wants to talk." Under 90-second time pressure, typing or
  speaking a question is *slower* than a glance at a well-designed panel. The
  `USERS.md` §5 test ("would he have asked a question or wanted a better
  screen?") is written as a kill-switch precisely because the honest default
  answer from a busy internist is plausibly "just show me the chart, better."
  Multi-turn may be a solution in search of his problem.

---

**H3 — v1 should target S1 (his own established patients) first; S2/S3 (covering / new) are the fast-follow.**
- **Confidence:** low.
- **Evidence basis:** Pure guess on the *state mix*; `USERS.md` §3 itself flags
  this as "the most important correction to the original framing" and
  Open Question #4 admits the S1/S2/S3 share is unknown.
- **What breaks downstream if wrong:** The roadmap ordering. `ARCHITECTURE.md`
  §7 defers UC5 (covering/new) to Phase 4. `USERS.md` §3 argues S2 is *higher
  value and higher risk* (no memory fallback, degraded-mode bites hardest). If
  most of his week is covering, we deferred the most valuable case and shipped
  the least.
- **Strongest case it is false:** In many real practices — hospitalist-adjacent
  coverage, call pools, growing panels — a large share of encounters are *not*
  the physician's own longitudinal patients. The document's own §3 says the
  highest-value quadrant is "complex × unfamiliar," which is **S2, deferred to
  Phase 4.** If the mix skews S2, v1 optimizes for the case where he needs the
  tool *least* (he has his own memory) and defers the case where he needs it
  *most*.

---

**H4 — [FLAGGED — NOT RESOLVED] Eye contact is the dominant loyalty driver; a tool that buys back eye contact wins more than one that saves time.**
- **Confidence:** low — **the boldest single assumption in the persona**
  (`USERS.md` §4).
- **Evidence basis:** **Pure guess.** No population literature, no user data.
  `USERS.md` §1 explicitly names this among "the lines that read most like fact
  [that] are the guesses."
- **What breaks downstream if wrong:** The adoption thesis (`ARCHITECTURE.md`
  Executive Summary — "buys back eye contact wins him"), the preserve-distrust
  UX rationale, and the churn-instrumentation choices in `USERS.md` §9 (e.g.
  "walks in without the laptop").
- **Strongest case it is false:** Eye contact may be a *stated* value that does
  not drive *behavior*. He may trade it away instantly for defensible
  documentation, throughput, or liability cover — the buyer's metrics
  (`USERS.md` §10), not his. Or the binding constraint may be cognitive load,
  not gaze direction. We have literally no evidence either way.
- **Status: STAYS FLAGGED.** This row is not confidence-rated up or down by this
  pass and does not feed any build decision. It is carried forward, unresolved,
  as the single highest-priority item for a real-clinician interview.

---

**H5 — One confidently-wrong clinical fact ends his trust (largely one-strike churn).**
- **Confidence:** low–medium.
- **Evidence basis:** Analogy (high-stakes domain) + partial population support:
  alert-fatigue / override literature shows clinicians *do* disengage from
  low-trust decision support (van der Sijs et al. 2006, JAMIA 13(2):138–147 —
  drug-safety alerts overridden **49%–96%** of the time). That supports the
  *disengagement* mechanism, not the specific "one strike" threshold.
- **What breaks downstream if wrong:** The entire zero-miss / provenance-
  everywhere posture (`ARCHITECTURE.md` §6, the accuracy gate) is calibrated to
  a one-strike user. If he is more forgiving, we may be over-engineering
  correctness at the expense of latency or coverage; if his trigger is a
  *different* dimension (e.g. slowness, or a single alert-storm), we hardened
  the wrong surface.
- **Strongest case it is false:** "One wrong fact ends trust" is a satisfying
  story but calibration is unknown. The override literature suggests clinicians
  actually tolerate a *high* rate of low-value signals by ignoring them —
  the opposite of one-strike. His real churn trigger may be **alert fatigue**
  (too many flags), not **commission** (one wrong flag), which would flip the
  optimization from recall toward precision.

---

**H6 — The four orientation needs (`USERS.md` §4) are the right four, and complete.**
- **Confidence:** low.
- **Evidence basis:** Pure guess; `USERS.md` §2 lists "which does he reach for
  first — and are any missing?" as an open test.
- **What breaks downstream if wrong:** UC1 snapshot content — what the opening
  turn actually shows. A missing fifth need, or a dominant single need, changes
  the snapshot design and the salience model.
- **Strongest case it is false:** The four needs were introspected, not
  observed. A real internist's first reach might be something absent from the
  list — e.g. "what did the MA/nurse already flag," "what's the billing/coding
  status," "is there a pending result I'm waiting on" — and the framing would
  miss it because it was never in the room to be asked.

---

**H7 — "22/day" hides high variance; ~5 are complex/changed and the rest largely stable, so salience concentrates in the few.**
- **Confidence:** medium (structurally plausible) — but the specific split is a
  guess.
- **Evidence basis:** Analogy + stipulated archetype parameters (`USERS.md` §2
  notes the §2 numbers are "stipulated archetype parameters," not observations).
- **What breaks downstream if wrong:** The "silence when nothing changed" UX
  (R5/R11), the precision floor (R7), and the pre-chart's "10-minute skim
  flagging the ~5" workflow (UC3).
- **Strongest case it is false:** The variance may not cleave neatly into
  "~5 complex." If *most* patients have *some* actionable change (an outside
  med, a lab drift, an overdue screening), the tool is never silent, "silence
  when nothing changed" almost never fires, and the design premise that salience
  concentrates collapses into "everything is flagged" — i.e. the alert fatigue
  we were trying to avoid.

---

**H8 — Degraded-mode dependence / deskilling is real and bites hardest in S2 (covering).**
- **Confidence:** low–medium.
- **Evidence basis:** Population literature on **automation bias** — Goddard,
  Roudsari & Wyatt 2012 (JAMIA 19(1):121–127) — establishes over-reliance on
  decision support as a real, measurable phenomenon. Manifestation in *this*
  user, and the S2-specific claim, are unverified.
- **What breaks downstream if wrong:** The preserve-distrust UX (R5), the
  graceful-degradation requirement (R11), and the argument for deferring S2.
- **Strongest case it is false:** Deskilling is a long-horizon effect that may
  never surface in the v1 evaluation window, and the "preserve distrust"
  UX — friction deliberately added — may simply read as the tool being annoying
  and drive churn faster than any deskilling it prevents. The mitigation could
  cost more adoption than the risk it addresses.

---

**H9 — The buyer is not Ellis and needs a separate persona (admin / CMIO / owner) with different metrics.**
- **Confidence:** medium — this one is *more* likely right than most.
- **Evidence basis:** Analogy + `ARCHITECTURE.md` §9 (buyer named as hospital
  CTO) and `USERS.md` §10 (buyer priorities: throughput, liability, quality,
  compliance — partly in tension with Ellis's eye-contact/pajama-time values).
- **What breaks downstream if wrong (or unaddressed):** Go-to-market and the
  definition of success. If we design only for Ellis's love, we may never earn
  the buyer's yes; if we design only for the buyer, we lose Ellis. Less on the
  *build*, heavy on *whether it sells*.
- **Strongest case it is false:** In a solo/small-group practice (`USERS.md`
  §11 #3) Ellis may well *be* the buyer, collapsing the tension — but we don't
  know his practice context, so even "the buyer is someone else" is an
  unverified assumption.

---

**H10 — [PROGRAM-STIPULATED 2026-07-08] The between-patient moment tolerates roughly a 90-second window, and the machine budget in §2 fits inside it.**
- **Status:** the case study requires answers **in seconds, inside the
  between-patient window** — confirmed in the requirements PDF. The window
  and the answer-in-seconds bar are program givens; removed from the
  exposure ranking (§1.2). The §2 budget remains OUR stipulation of how to
  meet that bar, and the break case below stays as the real-world tension
  under R12 (the true interval may be shorter and more variable than any
  stipulation).
- **Confidence (as a real-world claim):** low — **stipulated, not measured** (see §2).
- **Evidence basis:** Pure guess / stipulation. `ARCHITECTURE.md` §9 lists the
  latency tolerance as an open question to be "set during in-house Phase 0."
- **What breaks downstream if wrong:** The latency budget (§2), the p95 targets,
  the pre-chart-vs-live fallback design, and the Phase 3 performance bar.
- **Strongest case it is false:** 90 seconds is a founder-chosen number. The
  real between-room interval may be far shorter and far more variable (a hallway
  glance, an interruption), such that *any* interactive turn is too slow and the
  only acceptable latency is "already on screen when he looks." If so, the
  interactive UC2 turn is too slow to use in the moment, and only the
  pre-charted snapshot survives.

### 1.2 Ranking — (exposure: how much is built on top) × (likelihood wrong)

Ranked most-critical-to-resolve first. "Exposure" = how much of the build
collapses if the row is false; "P(wrong)" is this pass's rough rating.
**Re-ranked 2026-07-08:** H1, H2, and H10 are PROGRAM-STIPULATED (see their
rows) and are removed from this table — a stipulated row has no in-project
resolution to prioritize. Their real-world break cases are carried under R12
(§4) for the clinician interview, not dropped.

| Rank | Row | Exposure | P(wrong) | Why it ranks here |
|---|---|---|---|---|
| 1 | **H3** — S1-first roadmap | High (phase ordering) | High | State mix unknown; §3 argues the deferred S2 is the higher-value case. |
| 2 | **H4** — eye contact drives loyalty | High (adoption thesis, UX) | Unknown → **FLAGGED** | Boldest guess, zero evidence. Not resolved here by design. |
| 3 | **H6** — the four needs are right/complete | High (snapshot content) | Med–High | Introspected, never observed. |
| 4 | **H5** — one-strike churn on a wrong fact | High (zero-miss posture) | Med | Override literature hints the real trigger may be fatigue, not commission. |
| 5 | **H7** — ~5-of-22 complexity concentration | Med (silence UX, precision floor) | Med | Structurally plausible, split is a guess. |
| 6 | **H8** — deskilling worst in S2 | Med (preserve-distrust UX) | Med | Phenomenon is real in literature; persona/S2 specificity unverified. |
| 7 | **H9** — buyer ≠ Ellis | Med (go-to-market) | Low–Med | Most likely *correct*; needs its own persona regardless. |

**Self-review honesty check:** rows H3 and H6 are rated *likely-wrong* and now
top the ranking; H4 is held open, not scored. This pass did not confirm the
persona — it surfaced where it is most exposed. Note what the 2026-07-08
reclassification does **not** do: stipulating H1/H2/H10 makes them safe to
build against *in-project*; it does not make them true. The one cited study
(Sinsky 2016) still points at documentation, not orientation, as the dominant
burden — that tension now lives in R12's real-world residual rather than in
this ranking.

---

## Section 2 — Latency budget **(STIPULATED — not validated)**

> **This entire section is STIPULATED.** The numbers are set to satisfy the
> Phase 0 requirement and to give Phase 3 a target to build against — they are
> **not** validated with a user. The 90-second window itself is hypothesis H10
> (low confidence). Treat every figure below as "the target we are choosing,"
> not "the tolerance we measured."

### 2.1 The targets (machine time only)

| Path | Metric | Target | Meaning if breached |
|---|---|---|---|
| **Pre-charted snapshot, first paint** | p95 | **≤ 3s** | Cache hit from the overnight/at-login pre-chart run. A miss means the pre-chart failed and the system fell back to a live read — **the UI must say so** (see §2.4). |
| **Cold / same-day-add snapshot** | p95 | **≤ 10s WITH progressive render** | No cache entry (walk-in, same-day add). Must paint progressively — partial content early, not a 10s blank. |
| **Each follow-up turn (UC2)** | p95 | **≤ 10s** | One grounded conversational turn: retrieve → data-trust → synthesis → LLM → ground. |

The design intent (`ARCHITECTURE.md` — "in the physician's ~90-second window"):
**the machine must consume as little of the window as possible and leave most
of it for human reading and walking in.**

### 2.2 Walk-through arithmetic — a realistic sequence

Sequence: **open snapshot → read → one follow-up → read → walk in.**
Machine time = worst-case p95; human time = illustrative reading estimates
(themselves unvalidated, shown only to prove headroom).

**Pre-charted (p95) path:**

| Step | Actor | Time |
|---|---|---|
| Open pre-charted snapshot, first paint | machine | 3s |
| Read the snapshot | human | ~25s |
| Ask one follow-up (UC2), answer painted | machine | 10s |
| Read the answer | human | ~12s |
| Walk in / final glance | human | ~5s |
| **Total** | | **~55s** |
| **Machine share** | | **13s (~14% of a 90s window)** |
| **Headroom under 90s** | | **~35s** |

Even adding a **second** follow-up turn (+10s machine, +12s human) → ~77s
total, still under 90s, machine share 23s (~26%). The pre-charted path holds
with comfortable headroom, and the machine never consumes more than ~a quarter
of the window at p95.

**Cold (p95) path:**

| Step | Actor | Time |
|---|---|---|
| Cold snapshot, progressive first paint | machine | 10s |
| Read the snapshot (progressive) | human | ~25s |
| One follow-up, answer painted | machine | 10s |
| Read the answer | human | ~12s |
| Walk in | human | ~5s |
| **Total** | | **~62s** |
| **Machine share** | | **20s (~22%)** |
| **Headroom under 90s** | | **~28s** |

The cold path still fits, but with less slack, and it depends on progressive
render actually delivering usable partial content early — not a 10s spinner.
This is why the cold path is a fallback with a visible UI signal (§2.4), not the
happy path.

### 2.3 What this does *not* prove

- It does not prove 90 seconds is the real tolerance (H10).
- It does not prove the human read-times above; they are illustrative.
- It does not prove p95 is the right percentile to govern — a p99 tail
  (a slow LLM turn, a cold DB) could still blow the window on the encounters
  that matter most.

### 2.4 Instrumentation Phase 3 must build to measure this for real

Per `ARCHITECTURE.md` §6 (observability), the following must exist **before**
any of the above can be claimed as measured rather than stipulated:

1. **Per-request step timing** — tool sequence, per-step latency (retrieve /
   data-trust / synthesis / LLM / ground), tool failures, token counts. Log the
   distribution, report **p95 and p99**, never the mean.
2. **Cache-hit/miss flag on every snapshot** — distinguishes the pre-charted
   (≤3s) path from the cold/live-fallback (≤10s) path, so a *silent* degradation
   from pre-charted to live is visible in metrics, not just felt by the user.
3. **First-paint vs full-render split** — progressive render means first-paint
   latency and full-content latency are different metrics; both must be timed.
4. **A user-facing fallback signal** — when the pre-chart missed and the system
   is reading live, the UI says so (per the ≤3s target's stated contract). Log
   the fallback event.
5. **A clock/tracing seam** — the detectors are already pure and clock-injected;
   the orchestration path needs the same discipline so latency is measurable
   deterministically in tests and observable in production. A dedicated
   LLM-tracing product is deferred (`ARCHITECTURE.md` §6); in-repo per-step
   logging is load-bearing and is not.

---

## Section 3 — Golden-chart seed **(CANDIDATES ONLY)**

> **Nothing in §3 is adjudicated. Nothing in §3 arms the accuracy gate.** Every
> item is a candidate for the clinical-governance owner's sign-off. §3a items
> are reference-grounded (or explicitly UNSOURCED); §3b items are
> PROVISIONAL — UNADJUDICATED and **may not gate the build** (stated again in
> §3b).

**Provenance tag scheme** (so Phase 2 CI can tell reference-grounded from
placeholder):

- `provenance: reference-grounded` — a named published reference is cited
  (source, edition/date, page/table). Eligible to become a gating label
  **only after human sign-off**.
- `provenance: unsourced` — no reference found this pass; **value left blank**.
  Must not gate anything until a citation is supplied.
- `provenance: provisional-unadjudicated` — judgment-based (§3b); may never gate.

**Independence note:** every §3a candidate was transcribed from the reference
*before* the detector DRAFT tables (`faeb658`) were read. The reconciliation in
§3a.5 compares the two; it does **not** derive labels from the code.

### 3a — Deterministic critical subset (reference-grounded candidates)

#### 3a.1 Panic labs — candidate cases

Transcribed from the **ARUP Laboratories Critical Values List, CORP-APPEND-0104A,
Rev. 46, April 2026, page 1 (Chemistry / Hematology)**. Adult ranges (ARUP age-
bands several analytes; the ">30 days to adult" / "≥7 days to adult" adult band
is used, consistent with v1's adult-outpatient scope).

> **Reference caveat (important, human decision):** critical-value thresholds
> are **institution-defined** — under CLIA each laboratory's medical director
> sets its own. ARUP (University of Utah) is *one* named, dated, authoritative
> reference lab, **not a universal standard.** For a real deployment the
> source of truth is the **deploying institution's** critical-values policy,
> which may differ at the edges. This is exactly a governance sign-off item.

Candidate cases (each: chart contains value → must be surfaced):

| # | Candidate case | Value | Unit | provenance |
|---|---|---|---|---|
| PL-1 | Potassium at/below low critical → surfaced | **< 3.0** | mmol/L | reference-grounded — ARUP Rev.46 p.1 |
| PL-2 | Potassium above high critical → surfaced | **> 6.1** | mmol/L | reference-grounded — ARUP Rev.46 p.1 |
| PL-3 | Sodium below low critical → surfaced | **< 120** | mmol/L | reference-grounded — ARUP Rev.46 p.1 |
| PL-4 | Sodium above high critical → surfaced | **> 160** | mmol/L | reference-grounded — ARUP Rev.46 p.1 |
| PL-5 | Glucose (adult) below low critical → surfaced | **< 55** | mg/dL | reference-grounded — ARUP Rev.46 p.1 |
| PL-6 | Glucose (adult) above high critical → surfaced | **> 450** | mg/dL | reference-grounded — ARUP Rev.46 p.1 |
| PL-7 | Hemoglobin (adult) below low critical → surfaced | **< 7.0** | g/dL | reference-grounded — ARUP Rev.46 p.1 |
| PL-8 | Platelets at/below low critical → surfaced | **≤ 20** | ×10³/µL | reference-grounded — ARUP Rev.46 p.1 |
| PL-9 | Platelets at/above high critical → surfaced | **≥ 1000** | ×10³/µL | reference-grounded — ARUP Rev.46 p.1 |

High-value adult analytes that are **on the ARUP list but NOT tracked by the
detector** — candidate *additional* cases the governance owner should consider
(all reference-grounded, ARUP Rev.46 p.1, listed here so the coverage gap is
visible, not to expand the table unilaterally):

| # | Analyte | ARUP critical (adult) | Why it matters to an internist |
|---|---|---|---|
| PL-10 | INR | ≥ 5.0 | Warfarin panel; directly ties to the warfarin DDIs in §3a.2 |
| PL-11 | Calcium, total | < 6.0 or > 13.0 mg/dL | Common, high-harm |
| PL-12 | Magnesium | < 1.0 or > 9.0 mg/dL | Common; drives QT/arrhythmia |
| PL-13 | hs-Troponin I | ≥ 200 ng/L | ACS |
| PL-14 | Digoxin | > 2.4 ng/mL (trough) | Narrow therapeutic index, outpatient |
| PL-15 | Lithium | ≥ 1.6 mmol/L (trough) | Narrow therapeutic index, outpatient |

#### 3a.2 Drug–drug interactions — candidate cases

| # | Candidate pair | Effect (as printed) | provenance |
|---|---|---|---|
| DD-1 | Warfarin + NSAID (e.g. ibuprofen) → surfaced | "NSAIDs increase gastric irritation and erosion … could result in gastrointestinal bleeding" (additive with warfarin's anticoagulation) | reference-grounded — Carpenter, Berry & Pelletier, *Clinically Relevant Drug-Drug Interactions in Primary Care*, Am Fam Physician 2019;99(9):558–564, body text |
| DD-2 | Warfarin + aspirin → surfaced | Combined antithrombotic bleeding risk; "limit … aspirin/dipyridamole to 100 mg per day or less to minimize bleeding risk" | reference-grounded — Carpenter et al. 2019, AFP 99(9):558–564, body text |
| DD-3 | Methotrexate + trimethoprim (or co-trimoxazole) → surfaced | "reported rarely to increase bone marrow suppression in patients receiving methotrexate … additive antifolate effect"; leads to pancytopenia | reference-grounded — FDA methotrexate Prescribing Information, Drug Interactions; corroborated by MedSafe NZ *Prescriber Update*, March 2022 ("Bone marrow suppression with methotrexate and trimethoprim or co-trimoxazole") |

High-value ambulatory DDIs **not** in the detector table — candidate additions
for the governance owner (reference-grounded):

| # | Pair | Effect | provenance |
|---|---|---|---|
| DD-4 | Simvastatin + strong CYP3A4 inhibitor (e.g. clarithromycin) | Rhabdomyolysis risk; HMG-CoA reductase inhibitor + CYP3A4 inhibitor | reference-grounded — Phansalkar et al. 2012, JAMIA 19(5):735–743, Table 2 (#25); Carpenter et al. 2019 Table 3 |
| DD-5 | Simvastatin + verapamil / diltiazem | Dose caps (simvastatin ≤10 mg/day) | reference-grounded — Carpenter et al. 2019, AFP Table 3 |
| DD-6 | QT-prolonging agent + QT-prolonging agent | Additive QT prolongation → torsades | reference-grounded — Phansalkar et al. 2012 Table 2 (#21); Carpenter et al. 2019 Tables 5–6 |
| DD-7 | SSRI + MAO inhibitor | Serotonin syndrome | reference-grounded — Phansalkar et al. 2012 Table 2 (#8) |
| DD-8 | Warfarin + TMP-SMX / metronidazole / azole antifungal | INR increase; "reduce [warfarin] dosage 25–40%" | reference-grounded — Carpenter et al. 2019, AFP Table 1 |

#### 3a.3 Drug–allergy conflicts — candidate cases

| # | Candidate case | Basis (as printed) | provenance |
|---|---|---|---|
| DA-1 | Penicillin allergy + amoxicillin/ampicillin prescription → surfaced | Amoxicillin "contraindicated in patients who have experienced a serious hypersensitivity reaction … to amoxicillin … or to other β-lactam antibacterial drugs (e.g., penicillins …)" | reference-grounded — Amoxicillin capsule label, NorthStar Rx LLC, rev. 02/2024, Contraindications §4 (DailyMed) |
| DA-2 | Penicillin allergy + **cephalosporin** prescription → surfaced | Amoxicillin label names "cephalosporins" among cross-reactive β-lactams; cephalexin label: "Cross-hypersensitivity among beta-lactam antibacterial drugs may occur in **up to 10%** of patients with a history of penicillin allergy" | reference-grounded — Amoxicillin label (as above) §4; Cephalexin capsule label, Lupin Pharmaceuticals, rev. 09/2024, Contraindications & Warnings (DailyMed) |
| DA-3 | Cephalosporin allergy + another cephalosporin → surfaced | Cephalexin "contraindicated in patients with known hypersensitivity to cephalexin or other members of the cephalosporin class" | reference-grounded — Cephalexin label, Lupin, rev. 09/2024 §4 |
| DA-4 | Sulfonamide-antibiotic allergy grouping (sulfamethoxazole ↔ sulfasalazine and beyond) | **No reference transcribed this pass.** Antibiotic-vs-non-antibiotic sulfonamide cross-reactivity is clinically contested; not authored here. | **UNSOURCED — value blank; needs a cited reference and adjudication** |

#### 3a.4 Open follow-ups — candidate cases

`OpenFollowUpDetector` is a **structural rule, not a clinical-threshold table**:
every open follow-up is surfaced; overdue if `due < today`; an undated open loop
is still surfaced. There is no numeric clinical value to transcribe. The
*rationale* is reference-grounded; the *design choice* (surface every open loop)
is a judgment call, noted in §3a.5.

| # | Candidate case | provenance |
|---|---|---|
| FU-1 | An open follow-up with a past due date → surfaced as overdue | reference-grounded (rationale) — Callen, Westbrook, Georgiou & Li, *Failure to Follow-Up Test Results for Ambulatory Patients: A Systematic Review*, J Gen Intern Med 2012;27(10):1334–1348 (6.8%–62% of lab tests, 1.0%–35.7% of radiology not followed up; linked to missed/delayed diagnoses) |
| FU-2 | An open follow-up with no due date recorded → still surfaced | reference-grounded (rationale) — Callen et al. 2012 (undated open loop is still an unclosed loop; AUDIT D0/D6) |

#### 3a.5 Reconciliation vs the detector DRAFT tables (`faeb658`) — **DISCREPANCIES**

> Reported as findings. **Neither side silently aligned.** A mismatch between a
> published reference and the threshold table is the single most valuable output
> of this pass. Detector semantics (from `PanicLabDetector`): a value is flagged
> only when **strictly outside** a bound (`value < low` or `value > high`); the
> bound value itself is **not** flagged. Unit strings are matched
> case-insensitively but **exactly**; a mismatched or absent unit routes the lab
> to **UNEVALUABLE**, not to a finding.

**Panic labs — `PanicThresholds::draftV1()` vs ARUP Rev.46:**

| Analyte | Detector low / high | ARUP critical (adult) | Discrepancy (direction matters — under-sensitive = a MISS, the R13 danger) |
|---|---|---|---|
| Potassium | 2.5 / 6.0 mmol/L | < 3.0 or > 6.1 | **LOW bound is a MISS**: detector misses K 2.5–2.99 that ARUP calls critical (hypokalemia). HIGH: detector over-flags 6.0–6.1 (flags >6.0; ARUP >6.1) — over-flag, not a miss. |
| Sodium | 120 / 160 mmol/L | < 120 or > 160 | **Aligned** — same values, same strict semantics. No discrepancy. |
| Glucose | 40 / 500 mg/dL | < 55 or > 450 | **BOTH bounds are MISSES**: detector misses hypoglycemia 40–54 and hyperglycemia 451–500 that ARUP calls critical. High-harm (hypoglycemia). |
| Hemoglobin | 6.5 / — g/dL | < 7.0 (adult) | **LOW bound is a MISS**: detector misses Hgb 6.5–6.99. (ARUP has no adult high-Hgb critical; detector's null high is consistent.) |
| Platelets | 20 / — (10*3/uL) | ≤ 20 or ≥ 1000 | **Two misses:** (1) boundary — ARUP flags **≤20** (inclusive); detector flags **<20** only, so it **misses platelets = exactly 20**. (2) **No high bound** — detector will **never** surface critical thrombocytosis (≥1000). |

Additional panic-lab findings:
- **Coverage gap:** the detector tracks **5 analytes**; ARUP lists ~13 chemistry
  + hematology criticals for adults. High-harm internist-relevant analytes
  entirely **absent** from the detector: **INR (≥5.0 — directly relevant to the
  warfarin DDIs), calcium, magnesium, hs-troponin, digoxin, lithium** (PL-10–15
  above). A "zero-miss on the critical subset" contract that omits INR and
  troponin has defined its subset narrowly — that scoping is a governance
  decision, not a code detail.
- **Unit-string brittleness:** detector platelet unit is `10*3/uL`; ARUP prints
  `×10³/µL`. A real lab feed reporting `K/uL`, `x10E3/uL`, or `10^3/uL` would be
  routed to **UNEVALUABLE** rather than flagged. "Unevaluable, not silent" is the
  correct safety posture, but a *critical* platelet silently becoming
  "unevaluable" instead of a finding is worth the governance owner's attention.
- **No age-banding:** ARUP age-bands glucose, Hgb, Hct, platelets, WBC, lead;
  the detector does not. Acceptable for v1's adult-outpatient scope; wrong if the
  panel ever includes neonatal/pediatric values.

**Drug–drug — `InteractionPairs::draftV1()` vs references:**
- Detector pairs: `warfarin–aspirin`, `warfarin–ibuprofen`,
  `methotrexate–trimethoprim`. All three are **clinically real and defensible**
  for an outpatient internist (DD-1/DD-2/DD-3 ground them).
- **Finding — zero overlap with the canonical EHR consensus list:** the most-
  cited published high-priority DDI set for EHRs, **Phansalkar et al. 2012
  (JAMIA 19(5), Table 2, 15 pairs)**, contains **none** of the detector's three
  pairs, and Phansalkar contains **no warfarin pair at all**. The detector table
  is founder-selected "classics," not a subset or superset of any single
  published list. That is not "wrong" — warfarin+NSAID is arguably *more* germane
  to Ellis's ambulatory panel than, say, atazanavir–PPI — but **which published
  list is the source of truth is an unresolved governance decision.**
- **Class-vs-member gap:** the hazards are drug *classes*; the table encodes
  single ingredients. `warfarin–ibuprofen` will **miss `warfarin–naproxen`,
  `–diclofenac`, `–ketorolac`**, etc. `methotrexate–trimethoprim` may miss the
  common real-world form **co-trimoxazole / TMP-SMX** depending on how
  `IngredientMatcher` tokenizes the combination name.
- **High-harm absences:** **statin + CYP3A4 inhibitor** (rhabdomyolysis, DD-4),
  **QT + QT** (DD-6), **SSRI + MAOI** (DD-7), **warfarin + TMP-SMX/metronidazole/
  azole** (DD-8) are all reference-grounded and **absent** from the detector.

**Drug–allergy — `AllergyClassMap::draftV1()` vs references:**
- Detector classes: `penicillins → {penicillin, amoxicillin, ampicillin}`;
  `sulfonamides → {sulfamethoxazole, sulfasalazine}`.
- **Finding — no cephalosporin cross-reactivity (a MISS):** both the amoxicillin
  and cephalexin labels explicitly name **penicillin↔cephalosporin** β-lactam
  cross-reactivity (cephalexin: "up to 10%"). The detector has **no cephalosporin
  class and no cross-link**, so a documented penicillin allergy + a cephalexin/
  cefdinir/ceftriaxone order is **not flagged** (DA-2/DA-3). This is a genuine
  omission in exactly the drug-allergy safety surface the detector exists to
  guard.
- **Member-coverage gap:** `penicillins` omits common members
  (dicloxacillin, nafcillin, oxacillin, piperacillin, penicillin VK) and the
  very common **amoxicillin-clavulanate ("Augmentin")** — which may be recorded
  under a brand/combination name the exact matcher won't catch.
- **Sulfonamide grouping is UNSOURCED (DA-4):** grouping `sulfamethoxazole` with
  `sulfasalazine` (and any broader "sulfa" cross-reactivity) is **clinically
  contested** and **no reference was transcribed** for it this pass. Flagged as
  UNSOURCED — not authored, not aligned. Additionally, a "sulfa"/"bactrim"/
  "septra" allergy recorded in free text (AUDIT D1) won't match the exact
  ingredient `sulfamethoxazole`.

**Open follow-ups — `OpenFollowUpDetector`:**
- No clinical-threshold table to reconcile. The one embedded judgment —
  **"every open loop is must-not-miss, surfaced regardless of due date"** — is a
  deliberately conservative recall-over-precision choice (Callen 2012 supports
  the *rationale*). It trades toward **alert fatigue (R7)**: a chart with many
  stale open loops surfaces all of them. Whether that threshold is right is a
  governance call, not a published number.

### 3b — Judgment-based items **(PROVISIONAL — UNADJUDICATED — MAY NOT GATE)**

> **These items may NOT gate the build.** They are subtle care gaps and trends
> that require clinical judgment, not deterministic rules. They are recorded as
> candidates for a real clinician to adjudicate and are tagged
> `provenance: provisional-unadjudicated`. Stated explicitly, per the task and
> `ARCHITECTURE.md` §6 (two-track model, decided 2026-07-09: judgment items are
> the only place tunable rates live — **provisional regression thresholds**,
> ratcheted "don't get worse" numbers, with the recall threshold named but
> UNSOURCED pending governance): **no §3b item arms the accuracy gate; the
> judgment track is dormant until a clinician (or the acting owner)
> adjudicates a set; a §3b miss is monitored, not a build failure.** The
> deterministic §3a tracks gate on hard zeros (any miss, any false flag, any
> incorrect stated fact) — invariants, never percentages, so no unsourced
> "floor" number gates them.

Candidate judgment-based items (illustrative, all UNADJUDICATED):
- **Trend, not a single value:** a rising creatinine trending toward CKD that is
  never individually "critical" but is a care gap across visits.
- **Overdue screening now a real gap:** e.g. a diabetic patient overdue for
  retinal / nephropathy screening (`USERS.md` §4.3 "an overdue screening that's
  now a real gap"). The *threshold* for "now a gap" is judgment.
- **Med reconciliation drift:** an outside-prescriber medication that plausibly
  conflicts with the plan but is not a hard drug–drug pair.
- **A subtle cross-source pattern (D9):** a med + a lab drift that together
  suggest an adverse effect (e.g. an ACE inhibitor + a slow potassium rise) that
  is below any single panic bound.

None of these have a transcribed threshold; each is exactly the kind of item
that needs a clinician, not a founder or a model, to adjudicate.

### 3c — Governance note: the DRAFT tables and the §3a labels are **one** decision

> **Recorded per the task.** The detector DRAFT clinical tables
> (`PanicThresholds` / `InteractionPairs` / `AllergyClassMap` `draftV1()`,
> `faeb658`) and the §3a critical-subset candidate labels are **one governance
> object with one sign-off, not two.** The tables define *what the detector will
> flag*; the §3a labels define *what the gate says must be flagged*. Approving
> one without the other is incoherent — if the reviewer sets the potassium low
> bound at 3.0 in the table, the golden-chart case "K 2.8 → must surface" must
> use the same 3.0.
>
> **Roster fix needed:** the `needs_review` roster currently lists these as
> **two** separate items — the Phase 2 card "PanicThresholds/InteractionPairs/
> AllergyClassMap `draftV1()` clinical tables are DRAFT pending human sign-off"
> and the Phase 0 card "Produce adjudicated seed labels for the golden-chart
> set." **Collapse them into a single clinical-governance decision with one
> owner and one sign-off.** (This is a documentation/process fix, not a code
> change; the Kanban is not edited by this pass — flagged for the human.)
>
> **Sign-off recorded (2026-07-08):** the human — acting as the
> **clinical-governance owner** (a founder standing in for a clinician; the
> stand-in itself is a named gap, §4) — signed off the **§3a
> reference-grounded labels only**, as **one decision** with the detector
> tables per the paragraph above: the tables were re-based on the cited
> references (potassium low 3.0, glucose 55/450, hemoglobin 7.0, platelets
> 20–1000 inclusive per ARUP Rev.46 p.1; the beta-lactam cross-link per the
> amoxicillin/cephalexin labels), the potassium HIGH bound deliberately kept
> at 6.0 (ARUP's 6.1 makes 6.0–6.1 a minor over-flag, not a miss — §3a.5),
> and the §3a labels became the gating fixture set
> (`tests/Tests/Isolated/Copilot/GoldenChart/adjudicated/`). The gate is
> **ARMED on that set** (§4). **§3b stays PROVISIONAL — UNADJUDICATED and
> never gates**; DA-4 (sulfa) stays UNSOURCED and ungated; the PL-10–15 and
> DD-4–8 candidate *additions* remain unadjudicated candidates — the
> coverage gap stays named (`ARCHITECTURE.md` §6). The roster collapse above
> is applied in `KANBAN.md`.

---

## Section 4 — Residual risk: **R12 stays open**

**R12 remains OPEN after Phase 0.** This pass was **founder-run and in-house**
(decided 2026-07-07; `ARCHITECTURE.md` §5 R12, §7 P0; `USERS.md` §2). Structured
self-review can **sharpen** a hypothesis, **rate** its confidence, and **rank**
it by exposure — but it **cannot validate** a hypothesis authored by the same
person, and a **model cannot validate it at all.** A founder adjudicating his own
persona, assisted by an AI that has no access to a real user, does not upgrade a
single hypothesis to knowledge.

**What IS established by this pass:**
- The assumptions are **enumerated, confidence-rated, and ranked by exposure**
  (§1), with an explicit break case for each and the top of the ranking
  populated by rows this pass judges **likely-wrong** — H1 (the 90-second
  moment), H2 (the agent shape), H3 (the S1-first roadmap), H6 (the four needs).
- A **latency target is set** (§2) — explicitly STIPULATED, with the walk-through
  arithmetic showing headroom under 90s and the Phase 3 instrumentation named.
- The **deterministic critical-subset candidates are reference-grounded** (§3a),
  every clinical value transcribed from a named published reference with a
  citation, or marked UNSOURCED with the value left blank.
- The **discrepancies between the published references and the detector DRAFT
  tables are reported** (§3a.5) — the most valuable output — with neither side
  silently aligned.

**What is NOT established (and cannot be, in-house):**
- That the **90-second between-patient moment is the real pain** (H1 — the one
  cited time-and-motion study points at documentation instead).
- That the **four orientation needs are correct and complete** (H6).
- **Eye contact as the loyalty driver** (H4) — stays FLAGGED, unresolved, zero
  evidence.
- The **true state mix** — S1 vs S2 vs S3 (H3) — which reprioritizes the entire
  roadmap.
- That the **conversational agent shape** beats a better chart view (H2).
- **Any adjudicated judgment label** (§3b) — none is signed off; none may gate.
- That the **90-second latency tolerance** is real (H10 — §2 is stipulated).

**R12 reduces only when one real outpatient internist reviews the persona and
the labels.** That is a **real-world** residual: with H1/H2/H10
program-stipulated (§1) and the §3a labels signed off by the acting
clinical-governance owner (§3c), **Phase 0 no longer gates anything
in-project** — what remains open is whether the persona and labels survive
contact with a real clinician, not whether the build may proceed. Until that
review:
- v1 stays **read-only and human-in-the-loop** — for *exactly* this reason: the
  physician remains the decision-maker, so an unvalidated persona and unadjudi-
  cated labels cannot cause an autonomous wrong action (`ARCHITECTURE.md` §1
  principle, §5 R10).
- The accuracy gate is **ARMED (2026-07-08) on the §3a reference-grounded set
  only** — signed off by the acting clinical-governance owner as one decision
  with the detector tables (§3c), carried as adjudicated fixtures with
  per-case provenance citations. **§3b judgment items and UNSOURCED items
  never arm or fail it**, and synthetic scaffolding still never gates. The
  adjudicator is a founder, not a clinician — the arming is real, the
  limitation is too.
- The **no-clinician limitation stays named**, everywhere it appears, rather
  than hidden. Real-clinician review of the persona and the golden-chart labels
  is the **highest-leverage upgrade** the moment one is available
  (`ARCHITECTURE.md` §9; `USERS.md` §11 #2).

---

## Appendix — References cited (transcription sources for §3a)

- **ARUP Laboratories.** *Critical Values List*, CORP-APPEND-0104A, **Rev. 46,
  April 2026**, pages 1–3 (Chemistry, Hematology, Coagulation, Toxicology).
  https://www.aruplab.com/files/resources/testing/ARUP_Critical_Values.pdf
  *(Institution-defined per CLIA; one reference lab, not a universal standard.)*
- **Phansalkar S, Desai AA, Bell DS, et al.** *High-priority drug–drug
  interactions for use in electronic health records.* **JAMIA
  2012;19(5):735–743**, **Table 2** (15 accepted pairs).
  https://pmc.ncbi.nlm.nih.gov/articles/PMC3422823/
- **Carpenter M, Berry H, Pelletier AL.** *Clinically Relevant Drug-Drug
  Interactions in Primary Care.* **Am Fam Physician 2019;99(9):558–564**,
  Tables 1, 3, 5, 6 and body text.
  https://www.aafp.org/pubs/afp/issues/2019/0501/p558.html
- **Amoxicillin capsule** label, **NorthStar Rx LLC, rev. 02/2024**,
  Contraindications §4 (DailyMed).
  https://dailymed.nlm.nih.gov/dailymed/drugInfo.cfm?setid=843f6053-63d9-47d2-81f1-b6aa78a7c20c
- **Cephalexin capsule** label, **Lupin Pharmaceuticals, rev. 09/2024**,
  Contraindications & Warnings (DailyMed).
  https://dailymed.nlm.nih.gov/dailymed/drugInfo.cfm?setid=1d3e6d33-5dc2-498e-8efa-e3c4fca15dff
- **Methotrexate** FDA Prescribing Information, Drug Interactions
  (trimethoprim/sulfamethoxazole → additive antifolate, bone-marrow
  suppression); corroborated by **MedSafe NZ Prescriber Update, March 2022**,
  *Bone marrow suppression with methotrexate and trimethoprim or co-trimoxazole.*
  https://www.medsafe.govt.nz/profs/PUArticles/March2022/Bone-marrow-suppression-methotrexate-and-trimethoprim-or-co-trimoxazole.html
- **Callen JL, Westbrook JI, Georgiou A, Li J.** *Failure to Follow-Up Test
  Results for Ambulatory Patients: A Systematic Review.* **J Gen Intern Med
  2012;27(10):1334–1348.** https://pmc.ncbi.nlm.nih.gov/articles/PMC3445672/

References cited in §1 (assumption ledger) for population-level phenomena:
- **Sinsky C, Colligan L, Li L, et al.** *Allocation of Physician Time in
  Ambulatory Practice: A Time and Motion Study in 4 Specialties.* **Ann Intern
  Med 2016;165(11):753–760.** https://pubmed.ncbi.nlm.nih.gov/28460382/
- **van der Sijs H, Aarts J, Vulto A, Berg M.** *Overriding of Drug Safety
  Alerts in Computerized Physician Order Entry.* **JAMIA 2006;13(2):138–147.**
  https://pmc.ncbi.nlm.nih.gov/articles/PMC1447540/
- **Goddard K, Roudsari A, Wyatt JC.** *Automation bias: a systematic review of
  frequency, effect mediators, and mitigators.* **JAMIA 2012;19(1):121–127.**
  https://pmc.ncbi.nlm.nih.gov/articles/PMC3240751/

---

*This document is a claim to review. Nothing in it is signed off by its author.
It sharpens hypotheses and grounds candidates in published references; the pass
itself did not validate the persona, adjudicate any label, or arm the accuracy
gate. (The gate was subsequently armed on the §3a set by the acting
clinical-governance owner's 2026-07-08 sign-off — see the preamble update, §3c,
and §4.) R12 stays open until a real outpatient internist reviews the persona
and the labels.*
