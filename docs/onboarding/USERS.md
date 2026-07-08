# User Profile — Dr. Ellis Tran *(working hypothesis — unvalidated)*

> ## ⚠ Epistemic status — read first
>
> **No physician has been interviewed for this.** "Dr. Ellis Tran" is a
> **founder-authored archetype**, not an observed person. Everything below
> except §1 is a **confident hypothesis, not a fact** — plausible, internally
> coherent, and entirely unverified. The lines that read most like fact are the
> guesses: *"he wants eye contact,"* *"one wrong fact ends his trust,"* *"he
> trusts silence."* Excellent guesses. Still guesses.
>
> **The highest-leverage next move is not refining this archetype — it is putting
> it in front of one real design-partner internist.** Whether §§3–8 are knowledge
> or assumption depends entirely on that one fork. Until it happens, we risk
> building something perfectly optimized for a person who does not exist. Treat
> every section below as an **assumption to test**, and use the **"→ Test by"**
> prompts as a starting interview guide.

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

Before more archetype refinement, run **one conversation with a real
outpatient internist** (ideally a design partner we can return to). The goal is
not to confirm Ellis — it's to find which hypotheses *break*. Highest-value to
test first, because the most is built on top of them:

- Is the **90-second between-patient moment** actually the felt pain, or is the
  real bottleneck elsewhere (documentation, inbox, prior auth)?
- Of the **four orientation needs** (§4), which does he reach for first — and are
  any missing?
- The **"states"** question (§3): how much of his week is *not* his own
  established patients?
- The **churn triggers** (§8): would one wrong fact really end trust, or is he
  more forgiving / less forgiving than we assume?

If any of these break, §§3–8 change materially. Design should not get ahead of
this conversation.

---

## 3. Situational states — Ellis is not always the through-line *(hypothesis)*

**The most important correction to the original framing.** The core-job
hypothesis (§4) quietly assumes **continuity** — that Ellis is the doctor who saw
this patient last time and carries the thread ("what's changed since I last saw
them," "the thread from last time"). Two common realities break that, and they
may be where the tool is *most* valuable.

Any encounter sits on **two independent axes**:

- **Complexity** — stable vs. complex/changed (the axis in §6).
- **Familiarity** — is Ellis the longitudinal through-line for this patient, or a
  stranger to the chart?

The familiarity axis has three states:

| State | Situation | The 90-second job becomes | Memory fallback if tool fails? |
|---|---|---|---|
| **S1 · Continuity** | His own patient; he has a "last time" | *What changed + the thread from last time* | **Yes** — his own recall |
| **S2 · Covering** | A partner's patient (coverage, call, cross-cover); no "last time" *for him* | ***Orient me to a stranger*** | **No** — full dependence |
| **S3 · New patient** | New to him, maybe to the practice; little/no internal history | *Establish a baseline* (not detect change) | **No** |

Two consequences the continuity framing missed:

1. **S2 is a distinct, high-value state — not a flavor of the "complex 5" (§6).**
   Orienting to a stranger under time pressure is arguably *harder and higher
   value* than the continuity case the persona optimized for. The
   highest-value **and** highest-risk quadrant is **complex × unfamiliar**: a sick
   patient he has never met.
2. **It sharpens the deskilling risk (§7).** On his own patients he has memory to
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

## 5. Who he is — drivers and adoption psychology *(hypothesis)*

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

## 6. Panel and complexity variability *(hypothesis)*

We suspect "22/day" hides large variance: on a given day maybe ~5 are
complex/changed and the rest largely stable, so the 90-second need is
**concentrated in the few**, and a tool that treats all 22 alike reads as noise
on most. Note this **complexity** axis is orthogonal to the **familiarity** axis
in §3 — a patient can be stable-but-a-stranger or complex-and-familiar.

→ **Test by:** ask him to think about yesterday's schedule — how many patients
actually needed real prep, and what made those different.

---

## 7. Trust, dependence, and degraded mode *(hypothesis)*

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

## 8. Adoption and churn — what to instrument *(hypothesis)*

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

## 9. Secondary stakeholders *(brief)*

- **Rooming MA / nurse** — preps the patient first; possible adjacent user and
  source of the reason-for-visit.
- **The patient** — not a user, but the party every salience decision acts on.
- **The buyer** — likely **not Ellis** (admin, medical director/CMIO, or owner),
  with different priorities (throughput, liability, quality, compliance). **Needs
  its own profile** — designing for Ellis's love does not produce the buyer's yes.

---

## 10. Open questions

1. **Is Ellis the buyer, or do we need a separate buyer persona?** (§9)
2. **Do we have — or can we get — a real design-partner internist to validate
   against?** This is the fork the whole document hangs on (§2).
3. **Practice context:** solo/small-group vs. employed-in-a-system — changes his
   autonomy, liability, and adoption say.
4. **State mix (§3):** what share of his encounters are S1 vs. S2 vs. S3? The
   answer reprioritizes the entire core job.

---

*Companion to the onboarding docs and [`AUDIT.md`](AUDIT.md). This profile
describes a **hypothesized** user; nothing in it should be treated as validated
until a real internist has reviewed it. Product scope, agent behavior, and
safety/compliance constraints are deliberately out of scope here.*
