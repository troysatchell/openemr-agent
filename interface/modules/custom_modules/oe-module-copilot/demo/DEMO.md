# Clinical Co-Pilot — Demo Runbook

**The demo path (2026-07-16): log in → open a patient's chart → click
"Co-Pilot" in the chart's left menu.** That one click runs the SMART
EHR-launch (patient-scoped token, no login ceremony, no token/uuid entry)
and lands in the panel, which renders the glanceable snapshot on arrival —
must-not-miss cards, new labs since last visit, meds, allergies — every
entry with click-to-source citations (a document-extracted lab opens its
source PDF with the bounding-box overlay).

The setup script below seeds the demo data; its hand-minted API tokens
still drive the non-clinical routes (`ping`/`health`/`ready`) and the seed
steps, but since the TRO-52 launch binding, the clinical routes
(`turn`/`snapshot`/`document`/`source`) refuse tokens that carry no
launch-context patient — clinical calls go through the launched panel.

## One-time prerequisites (OpenEMR UI, already done on production)

1. **Module**: Modules → Manage Modules → *Unregistered* →
   `oe-module-copilot` → Register → Install → Enable.
2. **Connectors** (Admin → Globals → Connectors): *Standard REST API* ON,
   *FHIR REST API* ON, *OAuth2 Password Grant* ON (demo instance,
   synthetic data — flip back off afterwards if you like).
3. `ANTHROPIC_API_KEY` set on the hosting environment (Railway service
   variables). Without it the demo still runs — every turn degrades
   honestly (findings intact, banner instead of prose).

## Run it

```bash
cd interface/modules/custom_modules/oe-module-copilot/demo
OE_PASS='<admin password>' sh copilot-demo.sh
```

(If the Railway CLI is installed and linked, `OE_PASS` is fetched
automatically.) The script: registers an OAuth client → **pauses for the
one manual click** (Admin → System → API Clients → Enable) → mints a token
→ seeds the demo patient (Alma Reyes: warfarin + aspirin + amoxicillin +
penicillin allergy) → runs a live smoke turn → prints the panel URL, token,
and patient UUID. State lives in `~/.copilot-demo/`, never in the repo;
reruns are idempotent. `sh copilot-demo.sh token` refreshes the 1-hour
token anytime.

## Physician demo (in-EMR panel)

The session-bound panel (T21) is a second UI on top of the same guarded
service layer: no API base, no bearer token, no manual patient uuid — it
authenticates as whichever OpenEMR user is logged into the EMR session and
drives its own module page (`public/index.php`) via a same-origin AJAX
endpoint (`public/ajax.php`), CSRF-protected like every other module page.

Seeding is **one-time**: appointments are booked for the next 14 days
(3/day; `DAYS=<n>` extends), so anyone with the `dr.tran` login — program
administrators included — just logs in and demos. No script needed after the
initial seed.

0. **Check your OAuth client's scopes first.** The seed steps need
   `user/user.read`, `user/appointment.write`, `user/facility.read`, and
   `user/encounter.read`/`user/encounter.write`. A client's scope grant is
   fixed at registration, so if `~/.copilot-demo/client.json` predates any
   of them (symptom: `provider` reports `rows_seen=0`, or clinical/schedule
   calls fail), re-register once:
   ```bash
   rm ~/.copilot-demo/client.json
   sh copilot-demo.sh register   # pauses: enable the NEW client in Admin → System → API Clients
   sh copilot-demo.sh token
   ```
1. **Seed everything** (one-time):
   ```bash
   cd interface/modules/custom_modules/oe-module-copilot/demo
   sh copilot-demo.sh provider   # resolves dr.tran; only pauses if she must be created
   sh copilot-demo.sh clinical   # per-patient meds/allergies/encounters (showcases below)
   sh copilot-demo.sh schedule   # 3 appointments/day for the next 14 days
   sh copilot-demo.sh labs       # synthetic lab results (direct SQL — see note below)
   ```
   `provider` first tries to resolve an existing `dr.tran` via `GET
   /api/user` — if she exists it records her id and never pauses (no
   `DR_PASS` needed). Only when she can't be found does it pause for you to
   create her (Administration → Users → New User: username `dr.tran`, a
   password you choose — export it as `DR_PASS='<pick a password>'`; name
   Ellis Tran; **Provider** and **Calendar** checked; Access Control
   **Physicians**). `labs` writes SQL directly because OpenEMR has no
   REST/FHIR write surface for lab results — it is setup tooling against
   the same tables the FHIR read path consumes (rows tagged
   `control_id='copilot-demo'`, rerun-safe); the module itself never writes.

   **Each patient showcases a different part of the system:**

   | Patient (slot) | What their chart demonstrates |
   |---|---|
   | **Alma Reyes** (09:00) | Panic-lab card (K 6.8 after her last visit) · drug–drug interaction (warfarin + aspirin) · "new labs since last visit" delta incl. the not-new exclusion (old sodium) · honest "Unknown" allergy (deliberately title-only) |
   | **Rafael Mendoza** (09:30) | Drug–allergy conflict (amoxicillin × CODED penicillin allergy) · honest unevaluable item (an undated lab that cannot be placed against his last visit) |
   | **June Park** (10:15) | Earned quiet: benign med, normal pre-visit lab, known last visit → the explicit "silence is a checked result" banner, never a blank |

   Asking a follow-up question on any of them then demonstrates the turn
   path: grounded claims with citations, re-checked (not re-shouted)
   critical findings, honest degradation if the model is unavailable.
2. **Log in to OpenEMR as `dr.tran`**.
3. Open a seeded patient's chart (Calendar → click the appointment, or
   Finder). In the chart's left menu (Dashboard / History / … / External
   Data) click **Co-Pilot** — the SMART EHR-launch runs silently (the
   deployment enables the core `skip_ehr_launch_authorization_flow` for
   this client: first-party-session verification, registered-scopes-only)
   and the panel opens already bound to that patient.
4. The **Patient snapshot** renders on arrival: must-not-miss cards,
   new labs since last visit, current meds/allergies, honest
   unevaluable/unknown-currency items — or the explicit earned-quiet
   banner. Click any citation chip: it resolves live in the Source preview;
   an extracted lab renders its source PDF page with the bounding-box
   overlay (TRO-44).
5. Type a follow-up question in the ask box (e.g. *"Is the anticoagulation
   current?"*) — grounded claims with citations, re-checked (not
   re-shouted) critical findings, honest degradation if the model is
   unavailable.

The session-bound tab surface (`public/index.php` + `ajax.php`) still
exists as a fallback but is no longer offered in any menu (2026-07-16):
the launched panel is the single physician entry. Bare visits to
`public/panel.html` still show the manual connection fields, but a
hand-minted token has no launch-context patient and the clinical routes
refuse it (403) — use the chart-menu launch.

## Suggested video flow (~3 minutes)

1. **One click from the chart** — open Alma's chart, click **Co-Pilot**
   in the left menu; the snapshot renders on arrival. Then ask *"Anything
   I should know before I walk in? Is the anticoagulation current?"*
2. Point at the **red must-not-miss card** (warfarin + aspirin DDI):
   detected by deterministic code, not the model — it renders even if the
   model is down.
3. Point at **grounded claims' citation chips** (each resolves to a chart
   row) vs. the honest handling of the uncoded allergy — the system says
   the allergen is undocumented rather than guessing.
4. **Honest degradation** (optional): clear `ANTHROPIC_API_KEY` in Railway,
   ask again → findings persist under an amber banner; restore the key.
5. **Observability**: `GET /apis/default/api/copilot/ready` (all probes
   true), then in the container
   `php interface/modules/custom_modules/oe-module-copilot/bin/trace-dashboard.php`
   — latency, tokens, cost, verification counts, honest N/A metrics.
   Mention the correlation id joining the PHI-free trace to the audit log.
6. **The gate**: the CI clinical-accuracy gate — hard zeros on the critical
   subset, isolated copilot test suite.

## Known quirks

- **Title-only allergies** surface in FHIR as substance "unknown"
  (OpenEMR emits a data-absent-reason code when the lists row has no coded
  diagnosis), so the drug-allergy detector only fires on **coded**
  allergies. The system reports the unknown substance honestly — narrate it
  as a feature (R11), or add a coded allergy via the UI to light up the
  second detector.
- **401 on copilot routes** → the OAuth client isn't Enabled, or the token
  predates the module's scope registration — mint a fresh one.
- **403 "Access token is not bound to the requested patient"** → the token
  has no launch-context patient (hand-minted, or a stale pre-launch
  session). Relaunch from the patient chart's Co-Pilot menu entry; the
  clinical routes only accept launch-bound tokens (TRO-52).
- **503 from `/ready`** → the response body names the failing probe
  (`db`, `trace_sink`, or `llm`).
