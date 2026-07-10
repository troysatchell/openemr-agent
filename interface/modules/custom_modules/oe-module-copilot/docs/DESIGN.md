# DESIGN.md — Co-Pilot design language

> The clinical persona (Dr. Ellis Tran, `USERS.md`) says what the product must
> *do*. This file owns how it must *feel and read*. Status: DRAFT —
> founder-validated in intent (requested 2026-07-09), persona unvalidated by a
> working clinical designer (same residual-risk class as R12).

## The design persona

**Priya Desai — staff product designer, clinical decision support.** Ten
years designing for EHR-adjacent tools; her formative scar is watching a
nurse ignore a real alert because the screen had trained her to ignore
everything. Priya reviews every UI change against one question:

> "Does this pixel earn trust it can back up — or borrow trust it can't?"

Her non-negotiables, each traceable to the product's own requirements:

1. **State is never ambiguous** (UC1's 90-second moment). At any instant the
   user can tell whether the system is idle, working (and on *what*),
   done, quiet, degraded, or refusing. A greyed-out control with no
   explanation is a defect, not a style.
2. **Silence is earned and labeled** (R5/R7). "Nothing to show" is always an
   explicit, computed statement — never an empty region the user must
   interpret.
3. **Provenance is part of the content** (R6/R10). A claim and its citation
   chips are one unit; a claim rendered without its chips is a different,
   weaker claim and must look like one (the rejected-claim treatment).
4. **Loudness is budgeted** (R7 alert fatigue). Exactly one visual register
   may shout: must-not-miss. Everything else speaks at conversational
   volume. A second shouting element devalues the first.
5. **Failure is honest and small** (R11). Degraded states say what is
   unavailable and what is still trustworthy, in one sentence, with no
   internals. They never cosplay as success — a failed read can never
   render as a quiet chart.
6. **Repetition is information** (R13). When the deterministic findings are
   re-checked and unchanged, say *that* — re-rendering identical cards
   teaches the user to stop reading them.

## Interaction-state vocabulary

Every async surface (schedule, snapshot, turn) is in exactly one state, and
each state has one canonical rendering. New UI copies these; it does not
invent new ones.

| State | Rendering | Copy pattern |
|---|---|---|
| idle | control enabled, neutral | — |
| **working** | control disabled + inline label of the verb in progress | "Reading the live chart…", "Asking — re-reading the chart…", "Loading today's schedule…" |
| result | content sections | per component |
| **quiet** | green `banner quiet` | "Nothing to surface … Silence here is a checked result, not an error." |
| **degraded** | amber `banner degraded`, replaces content it can't back | "Unable to X right now. Y below is unaffected." |
| denied / error | red `banner error` | what failed + the one next action |

Working states name the verb, not a spinner alone: the wait *is* the
re-grounding promise ("every turn re-reads the live chart"), so showing what
the system is doing is product truth, not decoration.

## Component rules

- **Must-not-miss cards** (`.crit-item`): red left bar + type chip; the ONLY
  red-register element. In turn output, findings identical to the ones the
  snapshot already shows collapse to one quiet line: "Critical findings
  re-checked this turn — unchanged (shown above)." A *new* finding renders
  full-size. Findings never disappear silently (R13).
- **Unevaluable / unknown-currency** (`.unev-item`): amber left bar; honest
  uncertainty is content, phrased as what to *do* ("check manually",
  "verify currency"). Same turn-dedupe rule as must-not-miss.
- **Citation chips** (`.ref`): monospace, attached under their claim; never
  reordered, never invented; a rejected claim gets the dashed treatment and
  text only.
- **Buttons**: primary navy; while working, disabled + label swapped to the
  working verb (never a bare `disabled` with unchanged label).
- **Dropdown rows**: non-selectable rows stay visible with honest labels
  ("(name not recorded)"), disabled not hidden (D1/D7).

## Voice

Plain clinical English, active voice, no exclamation marks, no apology
boilerplate. Numbers carry units. Every error names the one next action.
Codenames (ticket IDs, class names) never appear in the UI.

## Tokens

The palette in `public/index.php` / `panel.html` is the source of truth:
ink `#1a1f24`, muted `#5a6572`, line `#d9dee4`, navy `#20476b` (actions),
crit `#b3261e` (must-not-miss only), warn `#9a6a00` (uncertainty/degraded),
ok `#1b6e3c` (earned quiet), monospace for provenance. Semantic colors are
not decoration: crit/warn/ok appear only in their meanings above.
