# USERS.md — Dr. Ellis Tran *(working hypothesis — unvalidated)*

> Hard-gate deliverable: the target user, his workflow, and the specific use
> cases the agent addresses — each with an explicit answer to *why an agent is
> the right solution here*. This document is the source of truth
> [`ARCHITECTURE.md`](ARCHITECTURE.md) and
> [`W2_ARCHITECTURE.md`](W2_ARCHITECTURE.md) trace back to (use-case IDs
> **UC1–UC7**, §5; **UC6–UC7** are the Week 2 additions). System and data
> constraints cited here are grounded in [`AUDIT.md`](AUDIT.md).
>
> ## ⚠ Epistemic status — read first
>
> **No physician has been interviewed for this.** "Dr. Ellis Tran" is a
> **founder-authored archetype**, not an observed person. Everything below
> except §1 is a **confident hypothesis, not a fact** — plausible, internally
> coherent, and entirely unverified. The lines that read most like fact are the
> guesses: *"he wants eye contact,"* *"one wrong fact ends his trust,"* *"he
> trusts silence."* Excellent guesses. Still guesses.
>
> **Decided 2026-07-07: there is no external design-partner internist, and we are
> not recruiting one this sprint — the design-partner function is created
> in-house** (a founder-run validation pass using the **"→ Test by"** prompts
> below as the protocol). That keeps the build moving, but it does not upgrade
> any hypothesis to knowledge: until a real internist reviews this, we still risk
> building something perfectly optimized for a person who does not exist. Treat
> every section below as an **assumption to test**; the prompts double as the
> interview guide for whenever a real clinician becomes available.
>
> The **Week 2 additions** (the document-shaped week and **UC6–UC7**, added
> 2026-07-13) carry exactly the same status: founder-authored hypothesis, not
> observation. They were written to ground new Week 2 capabilities in *this*
> user's perspective — which is the right discipline — but grounding in a
> hypothesis does not make the capability validated.

---

## 1. What we actually know (the small factual base)

Honestly little about Ellis — and naming that is the point:

- The **product concept** is real: a clinical co-pilot on this OpenEMR repo, an
  orientation aid, human-in-the-loop.
- **No design-partner physician has been interviewed.** The persona is a
  founder-authored composite.
- The **OpenEMR data and system realities are facts** (see [`AUDIT.md`](AUDIT.md)) —
  they constrain what *any* physician could be offered, regardless of who Ellis
  turns out to be.
- A few named phenomena are **documented in the clinical-informatics literature
  generally** — after-hours "pajama time" documentation burden, EHR alert
  fatigue, automation bias in decision support. Real *as phenomena*; whether and
  how they manifest in *our* user is unverified.
- The §2 snapshot numbers are **stipulated archetype parameters** — assumptions
  we chose, lower-stakes but still assumptions, not observations.

Everything after §2 is hypothesis.

---

## 2. The validation imperative

The validation pass is run **in-house** (decided 2026-07-07 — no external design
partner; the founder runs the "→ Test by" protocol and adjudicates the answers),
with a standing intent to re-run it with **one real outpatient internist** when
one is available. Either way the goal is not to confirm Ellis — it's to find
which hypotheses *break*. Highest-value to test first, because the most is built
on top of them:

- Is the **90-second between-patient moment** actually the felt pain, or is the
  real bottleneck elsewhere (documentation, inbox, prior auth)?
- Of the **four orientation needs** (§4), which does he reach for first — and are
  any missing?
- The **"states"** question (§3): how much of his week is *not* his own
  established patients?
- Would he actually **choose the conversational shape** (§5) over a better chart
  view — or does "agent" read as one more thing to babysit?
- The **churn triggers** (§9): would one wrong fact really end trust, or is he
  more forgiving / less forgiving than we assume?
- *(Week 2)* The **buried-document pain** (§5 UC6): does information trapped in
  scans and intake forms actually rank alongside the four needs — and would he
  trust an extracted value that cites its exact source region, or re-read the
  scan anyway (making extraction decoration)?

If any of these break, §§3–9 change materially. Design should not get ahead of
this conversation.

---

## 3. Situational states — Ellis is not always the through-line *(hypothesis)*

**The most important correction to the original framing.** The core-job
hypothesis (§4) quietly assumes **continuity** — that Ellis is the doctor who saw
this patient last time and carries the thread ("what's changed since I last saw
them," "the thread from last time"). Two common realities break that, and they
may be where the tool is *most* valuable.

Any encounter sits on **two independent axes**:

- **Complexity** — stable vs. complex/changed (the axis in §7).
- **Familiarity** — is Ellis the longitudinal through-line for this patient, or a
  stranger to the chart?

The familiarity axis has three states:

| State | Situation | The 90-second job becomes | Memory fallback if tool fails? |
|---|---|---|---|
| **S1 · Continuity** | His own patient; he has a "last time" | *What changed + the thread from last time* | **Yes** — his own recall |
| **S2 · Covering** | A partner's patient (coverage, call, cross-cover); no "last time" *for him* | ***Orient me to a stranger*** | **No** — full dependence |
| **S3 · New patient** | New to him, maybe to the practice; little/no internal history | *Establish a baseline* (not detect change) | **No** |

Two consequences the continuity framing missed:

1. **S2 is a distinct, high-value state — not a flavor of the "complex 5" (§7).**
   Orienting to a stranger under time pressure is arguably *harder and higher
   value* than the continuity case the persona optimized for. The
   highest-value **and** highest-risk quadrant is **complex × unfamiliar**: a sick
   patient he has never met.
2. **It sharpens the deskilling risk (§8).** On his own patients he has memory to
   fall back on when the tool is down or wrong. On a colleague's chart he has
   none — so **degraded-mode failure bites hardest exactly in S2**, the case the
   original framing looked away from.

→ **Test by:** ask a real internist what fraction of a typical week is covering
others' panels or net-new patients, and whether those moments feel harder than
seeing their own established patients.

---

## 4. The core job — the 90-second moment *(hypothesis)*

We hypothesize that in ~90 seconds between rooms he reconstructs **four things**
— and that in **S1** the frame is *change*, while in **S2/S3** it shifts to
*cold orientation*:

1. **Who is this and why are they here today** — one-line re-identification + the
   reason for visit. *(Holds across all states.)*
2. **What's changed since last time** — new diagnoses, meds from other
   prescribers, an ED visit/hospitalization he wasn't looped in on, newly
   abnormal labs. *(S1-specific; undefined in S2/S3.)*
3. **What he must not miss** — a critical result, a dangerous interaction, an
   overdue screening that's now a real gap. *(Holds across all states.)*
4. **The thread from last time** — the "we said we'd recheck your thyroid in
   three months." *(S1-specific.)*

**The unlisted fifth, hypothesized to outrank the rest for loyalty:** he'd
rather **look the patient in the eye than read a screen at them**, and a tool
that buys back eye contact wins him more than one that saves thirty seconds.
*This is our boldest single assumption and the one to test hardest.*

→ **Test by:** ask what he does in the first ten seconds of walking into a room,
and what he wishes he'd known before opening the door.

---

## 5. Use cases — and why an agent, not a dashboard *(hypothesis)*

The specific moments the co-pilot enters Ellis's day, derived from §§3–4. Each
carries the burden the case study demands: **why is a conversational agent the
right shape — not a dashboard, not a sorted list, not a better chart view?**
Every agent capability in [`ARCHITECTURE.md`](ARCHITECTURE.md) must trace to a
UC id here; a capability that can't is out of scope. Like everything after §2,
these are hypotheses until the Phase 0 conversation — including the shape
question itself (§2).

### UC1 — The 90-second re-orientation *(v1 core; state S1)*

- **Moment:** the ~90 seconds between rooms. Thirty seconds before opening the
  door he needs the four §4 items for *this* patient, glanceable, nothing else.
- **What he does with the output:** walks in already oriented — ideally without
  re-opening the chart in the room (§4's eye-contact hypothesis).
- **Why an agent:** the four needs are a **salience problem, not a display
  problem**. What matters today differs per patient — a creatinine trend here, an
  ED visit there, a dangerous med–lab combination that only exists *across*
  sources (`AUDIT.md` D9) — so a dashboard's fixed fields either miss it or show
  everything (which is the dense chart he already has). And the snapshot is only
  the *opening turn*: the moment reliably produces follow-up questions no
  precomputed view can anticipate — which is UC2. A better chart view shows him
  where to look; the agent answers what he actually asked.

### UC2 — Grounded follow-up questions *(v1 core; the multi-turn case)*

- **Moment:** immediately after the UC1 snapshot, or mid-visit: "when did we
  start the statin?", "show me the potassium trend," "was she on lisinopril when
  that creatinine came back?"
- **What he does with the output:** decides — order, adjust, reassure, refer.
  The agent orients; he owns the call.
- **Why an agent (and specifically why multi-turn):** the space of follow-ups is
  open-ended, and the second question builds on the first answer — "show me the
  trend" → "since the dose change?" is one thought, not two searches. A search
  bar returns documents; he needs **answers with provenance** (every claim
  traceable to its chart source). This is the use case that justifies multi-turn
  conversation per the case-study bar — without it, we would not build
  multi-turn. Constraint carried into the design: prior turns inform *intent*,
  never *facts* — every turn re-grounds against the live chart
  (`ARCHITECTURE.md` §3.5).

### UC3 — Pre-charting the day *(v1; session-bound)*

- **Moment:** the evening before (the pajama-time window the product aims to
  shrink) or at morning login: prepare the UC1 snapshot for each of tomorrow's
  booked encounters, so the between-patient moment starts warm.
- **What he does with the output:** a 10-minute skim of the day, flagging the
  ~5 complex/changed patients (§7) that need real prep.
- **Why an agent:** each item *is* UC1's synthesis, produced by chaining tools —
  schedule read → per-patient FHIR reads → data-trust filtering (dedupe D8,
  activity filters D10) → one-pass synthesis — and its product is the primed
  conversation of UC1/UC2, not a static artifact: the pre-chart is turn zero.
  **Honest caveat:** of the five use cases this is the one closest to defensible
  as a plain report; it earns its place because it is the *same capability run
  ahead of time*, not a separate feature to maintain.

### UC4 — The must-not-miss guarantee *(v1; always-on within UC1/UC3)*

- **Moment:** always-on, inside every snapshot and pre-chart: a panic lab, a
  drug–drug contraindication, a drug–allergy conflict, an open follow-up that
  was never closed.
- **What he does with the output:** acts on it first — this is the "what he must
  not miss" need (§4.3), the one with patient-harm stakes.
- **Why an agent (with a deterministic core):** the detection is deliberately
  **not** model judgment — these items are found by unit-tested rules in the
  data-trust/synthesis layer (`ARCHITECTURE.md` §6), the case study's
  domain-constraint enforcement. What the *agent* adds is delivery: folding the
  flags into the patient's narrative at the moment of use, visually distinct,
  with provenance, and able to answer *"why is this flagged?"* A raw alert
  list is precisely the alert-fatigue channel he has learned to tune out (§9's
  churn trigger); a conversational surface that can justify each flag is the
  hypothesis for surviving that reflex.

### UC5 — Cold orientation to a stranger's chart *(fast-follow, NOT v1; states S2/S3)*

- **Moment:** covering a partner's patient or seeing a net-new one — no "last
  time," no memory fallback (§3).
- **What he does with the output:** builds a working model of an unfamiliar
  patient in the same 90 seconds.
- **Why an agent:** this is the purest conversational case — he must
  *interrogate* an unfamiliar chart under time pressure, and his questions
  cannot be predicted well enough to pre-render a view for them. It is also the
  highest-risk state: no memory fallback means degraded-mode failure bites
  hardest here (§8), which is exactly why it is deferred to Phase 4
  (`ARCHITECTURE.md` §7) rather than attempted first.

→ **Test by:** for each use case, ask him to narrate the last time that moment
went badly — and whether he would have *asked a question* or *wanted a better
screen*. If the answer is consistently "a better screen," the agent shape is
wrong and we should know before building it.

### Week 2 additions — the document-shaped week *(added 2026-07-13; hypothesis, same epistemic status as everything after §2)*

**The premise the Week 1 use cases quietly carried:** that the answers live in
the chart's *structured* data. Real practices also run on paper's descendants —
outside labs arriving as faxed or scanned PDFs, the intake form the front desk
scans in, discharge summaries from a hospitalization he wasn't looped in on.
The *phenomenon* is literature-real (healthcare's persistent fax/scan
interchange is documented the same way pajama time is, §1); **what share of
Ellis's clinically material information arrives that way is a stipulated
parameter, unverified.**

The Week 2 hypothesis sharpening §4: the dangerous facts on a follow-up day
live **disproportionately in those documents**, precisely because structured
data is what the EMR already surfaces well. Needs 2 and 3 — *what changed* and
*what he must not miss* — are exactly where a scanned potassium or a
handwritten med list hides. And the familiarity axis (§3) compounds it: in S2
covering, an unread scan in a stranger's chart has **no** memory fallback at
all. The eye-contact hypothesis (§4's fifth need) also binds here: a
click-to-source that takes one motion preserves it; anything that sends him
scrolling through a 6-page scan in the room destroys it — §9's
no-added-click-without-payback rule applies to verification too.

### UC6 — The buried document *(Week 2 core; all states)*

- **Moment:** prepping a follow-up (the UC3 evening pass or the 90-second
  window): the chart's structured data is current, but the information that
  actually matters today is a scanned lab PDF from an outside facility and the
  intake form the front desk uploaded this morning. Today's options are
  scroll-through-scans or miss it.
- **What he does with the output:** treats extracted facts as chart facts —
  they appear in the UC1 snapshot and flow into the UC4 must-not-miss rules
  (a panic value in a scan is still a panic value), each carrying a citation
  into the source page; one click shows the exact region of the document the
  value came from.
- **Why an agent:** the facts are **trapped in pixels — no dashboard can
  display what was never structured**, so extraction is the enabling step,
  not the feature. The *use* is conversational: "is that potassium from the
  outside lab or ours?", "when was this actually drawn?" are follow-ups
  against the document — UC2's shape extended to unstructured sources. And
  because extraction mints a **new way to be confidently wrong** (a misread
  digit is precisely §9's one-strike wrong-fact trigger), the
  claim-to-pixels affordance is the trust mechanism: he verifies against the
  source in one motion, in the room, without breaking the visit.

### UC7 — "What supports that?" — guideline-grounded recommendation support *(Week 2 core; extends UC2)*

- **Moment:** mid-visit or during prep, the third of the follow-up questions:
  *what changed, what should I pay attention to — and what evidence supports
  the recommendation?* He wants the **practice's own agreed guidance** (the
  protocols the office actually follows — HTN, T2DM, lipids, anticoagulation),
  not model memory, not a live literature search.
- **What he does with the output:** decides with the citation in view — and
  overrides it freely; a guideline is the practice's default, not an order.
  The agent orients, he owns the call (§5 principle, unchanged).
- **Why an agent:** recommendation-support is **follow-up-shaped and
  patient-anchored** — "should I intensify?" only means anything against
  *this* patient's meds, values, and the guideline together, which is a
  synthesis question no protocol binder or static link-out answers. The
  binding design constraint is **two-source honesty**: patient-record facts
  and guideline evidence stay visibly separate, each cited to its own source
  — because blurring *what is true of the patient* into *what the guideline
  recommends* is exactly how automation bias (§8) gets teeth.

→ **Test by:** ask him to recall the last result that arrived as a fax/scan
and how he found out about it; whether he would trust an extracted value that
shows its source region on click, or re-read the scan regardless; and whether
"what does our protocol say here?" is a question he actually asks mid-visit —
and of whom.

---

## 6. Who he is — drivers and adoption psychology *(hypothesis)*

Believed, unverified:

- **Values presence over efficiency** — eye contact is the emotional center.
- **Resents after-hours documentation** ("pajama time") as lost family time — an
  emotional nerve, not just a workflow one.
- **Epic-fluent and resentful** — competent with heavy software, conditioned to
  expect it to cost him.
- **Burned before** — tools that "saved time" and added clicks have calibrated
  him to skepticism; default is disproven-until-proven, *"prove it fast, don't
  make me babysit you."*
- **Risk-averse because he stays liable** — a cautious adopter by temperament;
  trust must be earned against real stakes.

→ **Test by:** ask about the last tool that disappointed him and why, and what
would have to be true in week one for him to keep using something new.

---

## 7. Panel and complexity variability *(hypothesis)*

We suspect "22/day" hides large variance: on a given day maybe ~5 are
complex/changed and the rest largely stable, so the 90-second need is
**concentrated in the few**, and a tool that treats all 22 alike reads as noise
on most. Note this **complexity** axis is orthogonal to the **familiarity** axis
in §3 — a patient can be stable-but-a-stranger or complex-and-familiar.

→ **Test by:** ask him to think about yesterday's schedule — how many patients
actually needed real prep, and what made those different.

---

## 8. Trust, dependence, and degraded mode *(hypothesis)*

We hypothesize his stance moves along a curve, and the middle is the dangerous
part: **skeptical → provisional → over-reliant.** Once he trusts the summary he
stops reading the source — exactly when a wrong/incomplete summary does the most
damage. Two believed consequences:

- **Deskilling.** As he offloads chart-scanning, his own speed at it atrophies;
  a tool that is **down, slow, or unsure at 9:40 a.m.** becomes *worse* than
  never having had it — and per §3 this bites hardest in **S2 (covering)**, where
  he has no memory fallback.
- The product may need to **actively preserve appropriate distrust** — the
  opposite of what most tools optimize for.

→ **Test by:** ask what he'd do if the tool were down for a morning, and whether
he'd trust it more or less on a patient he's never met.

---

## 9. Adoption and churn — what to instrument *(hypothesis)*

We believe self-reported satisfaction and "time saved" are poor signals (he
won't perceive 30 seconds). Behavioral signals we'd watch instead — **to be
confirmed as the right ones:**

- Walks into the room **without the laptop**.
- **Pajama time shrinks** (fewer notes closed after hours).
- **Stops opening** the chart tabs he used to open by reflex.
- **Returns to it unprompted** the next day — including on *stable* patients.
- Starts **trusting its silence** (stops double-checking when it says nothing
  changed).

Hypothesized **churn triggers** (largely one-strike, because the stakes are
patients): one confidently wrong clinical fact (a med he isn't on, a transposed
lab, a dropped allergy, cross-patient bleed); an alert-fatigue storm; any added
click without payback; being made to babysit it.

→ **Test by:** ask what a new tool would have to get wrong, once, for him to stop
trusting it — and what would make him recommend it to a partner.

---

## 10. Secondary stakeholders *(brief)*

- **Rooming MA / nurse** — preps the patient first; possible adjacent user and
  source of the reason-for-visit.
- **Front desk / scanning MA *(Week 2)*** — the person who scans and uploads
  the intake form and outside results. With document ingestion (UC6) they
  become the module's **first non-physician user surface** (upload +
  associate-to-patient) — a workflow user, never a reader of the agent's
  clinical output. Their unglamorous constraint shapes UC6 more than Ellis's
  does: if upload is fussier than the paper inbox, the documents never enter
  the system and UC6 starves.
- **The patient** — not a user, but the party every salience decision acts on.
- **The buyer** — likely **not Ellis** (admin, medical director/CMIO, or owner),
  with different priorities (throughput, liability, quality, compliance). **Needs
  its own profile** — designing for Ellis's love does not produce the buyer's yes.

---

## 11. Open questions

1. **Is Ellis the buyer, or do we need a separate buyer persona?** (§10)
2. ~~Do we have — or can we get — a real design-partner internist?~~ **Decided
   2026-07-07: no — validation is run in-house (§2), the design-partner function
   self-created.** The open question is now *when* a real internist reviews the
   persona; every hypothesis here carries the founder-adjudication caveat until
   then.
3. **Practice context:** solo/small-group vs. employed-in-a-system — changes his
   autonomy, liability, and adoption say.
4. **State mix (§3):** what share of his encounters are S1 vs. S2 vs. S3? The
   answer reprioritizes the entire core job.
5. **Document mix (Week 2, §5 UC6):** what fraction of clinically material
   information arrives as scans/PDFs/faxes rather than structured data — who
   uploads it today, how long it sits unread, and whether the practice *has*
   agreed protocols in writing for UC7 to retrieve (if not, the "practice's
   own guidance" corpus is a fiction and UC7 changes shape).

---

*Companion to the onboarding docs (`docs/onboarding/`) and [`AUDIT.md`](AUDIT.md).
This profile describes a **hypothesized** user; nothing in it should be treated
as validated until a real internist has reviewed it. Product scope, agent
behavior, and safety/compliance constraints live in
[`ARCHITECTURE.md`](ARCHITECTURE.md) (Week 1),
[`W2_ARCHITECTURE.md`](W2_ARCHITECTURE.md) (Week 2), and
`docs/onboarding/PRD.md`, which trace back to the use cases in §5.*
