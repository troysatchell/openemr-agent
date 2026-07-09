# Founder Actions — what only you can do

> Status as of 2026-07-09 (Wave 2 complete, `deploy/railway` @ `04e231e`,
> both remotes pushed). Everything below is human-owned: deployment
> secrets, module enablement clicks, governance sign-offs, and the demo.
> Agent-owned work (Wave 3 live smoke, load tests, API collection) is
> listed at the end only so the boundary is explicit.

---

## 1. Tonight — Early Submission (Thu 11:59 PM CT)

### 1.1 Set the LLM API key on Railway — REQUIRED
The adapter and the `/ready` probe both read `ANTHROPIC_API_KEY` from the
environment. The key must never enter the repo or the DB.

1. Railway → the **openemr** service → **Variables**.
2. Add `ANTHROPIC_API_KEY = sk-ant-...` (from console.anthropic.com; check
   the key's workspace has billing/limits headroom for the demo).
3. Redeploy the service (Railway usually redeploys on variable change; if
   not, trigger one — it should also pick up `deploy/railway` @ `04e231e`).

Without the key everything still works, but every turn degrades honestly:
detector findings render, model prose says "assistant unavailable." That is
itself demoable (R11), but you want at least one grounded answer on video.

### 1.2 Enable the copilot module in OpenEMR — REQUIRED
The module code ships with the repo but is not DB-registered until you (or
the Wave-3 agent) enable it. In the deployed OpenEMR:

1. Log in as admin → **Modules → Manage Modules**.
2. **Unregistered** tab → find `oe-module-copilot` → **Register**.
3. **Install**, then **Enable**.
4. Smoke it: `GET https://<your-railway-host>/apis/default/api/copilot/health`
   with a valid Bearer token (see 1.3) should return `{"status":"alive"}`,
   and `/api/copilot/ready` should show `db: true, trace_sink: true,
   llm: true` once 1.1 is done. A 503 from `/ready` names what's down.

### 1.3 Get a Bearer token for the panel — REQUIRED for the demo
The panel talks only to the guarded REST route, so it needs an OAuth2
access token. Follow `API_README.md` (the standard flow):

1. **Administration → Globals → Connectors**: enable the REST API (and, if
   you want the quickest path, the password grant + client registration
   toggles).
2. Register an API client against `/oauth2/default/registration`.
3. Obtain a token (password grant with your admin/physician user is fastest
   for a demo) with the standard `api:oemr` user scopes.
4. Keep the token handy — it goes in the panel's "Bearer token" field.

### 1.4 Grab a demo patient UUID
The panel asks for the FHIR Patient id. Fastest source: the FHIR API —
`GET /apis/default/fhir/Patient` with your token, or copy a uuid from any
patient you create in the UI. A good demo patient has: warfarin + aspirin
active meds, a potassium result of ~6.8, and a penicillin allergy with an
amoxicillin prescription — that lights up all three detector classes.

### 1.5 Record the demo video
Open `https://<railway-host>/interface/modules/custom_modules/oe-module-copilot/public/panel.html`,
fill base URL (`/apis/default`), token, patient uuid. A flow that shows the
thesis in under 3 minutes:

1. Ask "What should I know before I walk in?" → point at the **red
   must-not-miss findings** and say they are detected in code and would
   render even if the model were down.
2. Point at a **grounded claim's citation chips** vs a **rejected claim's
   "unverified" label** — model prose is never shown as fact without a
   resolvable chart citation.
3. (Optional but strong) Temporarily clear `ANTHROPIC_API_KEY` and ask
   again → the **degraded banner** with findings intact — honest failure.
4. Show the trace: in the container,
   `php interface/modules/custom_modules/oe-module-copilot/bin/trace-dashboard.php`
   → turns, latency, token cost, verification counts, and the honest N/A
   metrics; mention the correlation id joining trace ↔ audit log.
5. Show the eval gate: `KANBAN.md` gate line or the CI run — hard zeros on
   the critical subset, 316 isolated tests.

### 1.6 Submit
Early Submission requires: deployed agent (1.1–1.2), eval framework (the
armed two-track gate — already green), observability (trace + dashboard +
alerts doc — already shipped), demo video (1.5).

---

## 2. Governance sign-offs still open (yours, no deadline tonight)

- **`DraftPolicies::v1()` remaining field lists** — the `ref` field is
  signed off and shipped; the rest of each list is still DRAFT pending your
  minimum-necessary review (C5).
- **Judgment-rate numbers** — the two-track *shape* is locked; the concrete
  precision/recall thresholds stay unset until judgment fixtures exist,
  then get ratcheted from measured performance. Nothing to do yet; do not
  let anyone (including an agent) invent the numbers.
- **§3b judgment-item adjudication** — dormant track; needs you (or a real
  clinician later) to adjudicate a set before it can gate anything (R12
  stays a named limitation).
- **S2 acknowledgment** — the cookie hardening knowingly degraded legacy
  multi-window re-login restore; confirm you accept that trade for v1.
- **Alert thresholds** — the three alert definitions ship with UNSOURCED
  placeholder numbers; after the load-test baselines land (Sunday work),
  sign off the ratcheted values.

---

## 3. Before Sunday noon (final)

- Decide demo scope for the final: same panel flow plus whatever Wave-3 /
  graded items land (load-test numbers, cost analysis, API collection).
- Review the **"Graded deliverables not yet started"** swimlane in
  `KANBAN.md` — that list exists so nothing is discovered Saturday night.
- If you want the Anthropic **PHP SDK** instead of the raw-HTTP transport
  in `AnthropicLlmClient`, say so — it is a one-file swap behind the same
  port, but it adds a root composer dependency (your call, not mine).

---

## 4. Explicitly NOT yours (queued agent work — just so the line is clear)

- Wave 3 live-stack smoke: module registration via script, real FHIR field
  shapes through `OpenEmrFhirGateway`, production audit-sink rows, route
  dispatch through the S5 wrapper. (If you enable the module by hand in
  1.2, the smoke still validates the rest.)
- UC3 session-bound pre-chart + cache-hit trace flag; UC1 snapshot route.
- Load/stress tests (10/50 users), baselines, runnable API collection,
  strict tool I/O schemas, AI cost analysis at 100/1K/10K/100K users,
  ClaimVerifier contradiction-check audit.
