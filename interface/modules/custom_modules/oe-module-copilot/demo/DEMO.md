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
