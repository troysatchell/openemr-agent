# Clinical Co-Pilot — Demo Runbook

One script sets up everything against the deployed app and prints what to
paste into the panel. Verified working against production on 2026-07-09
(full grounded turn in ~7s, DDI must-not-miss firing live).

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

0. **Check your OAuth client's scopes first.** The provider/schedule steps
   need `user/user.read`, `user/appointment.write`, and `user/facility.read`.
   A client's scope grant is fixed at registration, so if
   `~/.copilot-demo/client.json` was registered before those scopes were
   added to this script (symptom: `provider` reports `rows_seen=0`, or
   `schedule` fails creating appointments), re-register once:
   ```bash
   rm ~/.copilot-demo/client.json
   sh copilot-demo.sh register   # pauses: enable the NEW client in Admin → System → API Clients
   sh copilot-demo.sh token
   ```
1. **Seed the physician and today's schedule** (one-time):
   ```bash
   cd interface/modules/custom_modules/oe-module-copilot/demo
   sh copilot-demo.sh provider
   sh copilot-demo.sh schedule
   ```
   `provider` first tries to resolve an existing `dr.tran` via
   `GET /api/user` — if she already exists it records her numeric id and
   never pauses (and `DR_PASS` is not needed). Only when she can't be found
   does it pause for you to create her (Administration → Users → New User:
   username `dr.tran`, a password you choose — export it as `DR_PASS='<pick
   a password>'` so the pause branch runs; name Ellis Tran; **Provider** and
   **Calendar** checked; Access Control **Physicians**), with a manual-id
   fallback if resolution still fails. `schedule` seeds two more synthetic
   patients (Rafael Mendoza, June Park) alongside the existing Alma Reyes
   and books all three onto `dr.tran`'s calendar for today at 09:00, 09:30,
   and 10:15.
2. **Log in to OpenEMR as `dr.tran`**.
3. Open the **Co-Pilot** tab. Placement depends on the user's ACLs: admin
   sees it right after **Modules**; a Physicians-group user like dr.tran has
   no Modules/Admin menus, so for her it sits between **Fees** and
   **Procedures** (behind the hamburger on narrow windows).
4. Confirm the **Today's patients** dropdown lists the three seeded
   appointments; pick one — the glanceable snapshot (must-not-miss cards,
   unevaluable items, current meds/allergies with citation chips) renders
   above the ask box.
5. Type a follow-up question in the ask box (e.g. *"Is the anticoagulation
   current?"*) — the answer renders with the same grounded/rejected
   citation styling as the token-based panel.

The token-based `public/panel.html` path (bearer token + manual patient
uuid) is unchanged and remains the API-consumer smoke path described above —
useful for scripted checks and for demoing the raw API surface independent
of an EMR login session.

## Suggested video flow (~3 minutes)

1. **Panel** — paste token + patient UUID, ask *"Anything I should know
   before I walk in? Is the anticoagulation current?"*
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
6. **The gate**: KANBAN.md gate line / CI — hard zeros on the critical
   subset, 316 isolated copilot tests.

## Known quirks

- **Title-only allergies** surface in FHIR as substance "unknown"
  (OpenEMR emits a data-absent-reason code when the lists row has no coded
  diagnosis), so the drug-allergy detector only fires on **coded**
  allergies. The system reports the unknown substance honestly — narrate it
  as a feature (R11), or add a coded allergy via the UI to light up the
  second detector.
- **401 on copilot routes** → the OAuth client isn't Enabled, or the token
  predates the module's scope registration — mint a fresh one.
- **503 from `/ready`** → the response body names the failing probe
  (`db`, `trace_sink`, or `llm`).
