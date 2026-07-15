[![Syntax Status](https://github.com/openemr/openemr/actions/workflows/syntax.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/syntax.yml)
[![Styling Status](https://github.com/openemr/openemr/actions/workflows/styling.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/styling.yml)
[![Testing Status](https://github.com/openemr/openemr/actions/workflows/test.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/test.yml)
[![JS Unit Testing Status](https://github.com/openemr/openemr/actions/workflows/js-test.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/js-test.yml)
[![PHPStan](https://github.com/openemr/openemr/actions/workflows/phpstan.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/phpstan.yml)
[![Rector](https://github.com/openemr/openemr/actions/workflows/rector.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/rector.yml)
[![ShellCheck](https://github.com/openemr/openemr/actions/workflows/shellcheck.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/shellcheck.yml)
[![Docker Compose Linting](https://github.com/openemr/openemr/actions/workflows/docker-compose-lint.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/docker-compose-lint.yml)
[![Dockerfile Linting](https://github.com/openemr/openemr/actions/workflows/docker-lint-hadolint.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/docker-lint-hadolint.yml)
[![Isolated Tests](https://github.com/openemr/openemr/actions/workflows/isolated-tests.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/isolated-tests.yml)
[![Inferno Certification Test](https://github.com/openemr/openemr/actions/workflows/inferno-test.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/inferno-test.yml)
[![Composer Checks](https://github.com/openemr/openemr/actions/workflows/composer.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/composer.yml)
[![Composer Require Checker](https://github.com/openemr/openemr/actions/workflows/composer-require-checker.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/composer-require-checker.yml)
[![API Docs Freshness Checks](https://github.com/openemr/openemr/actions/workflows/api-docs.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/api-docs.yml)
[![codecov](https://codecov.io/gh/openemr/openemr/graph/badge.svg?token=7Eu3U1Ozdq)](https://codecov.io/gh/openemr/openemr)

[![Backers on Open Collective](https://opencollective.com/openemr/backers/badge.svg)](#backers) [![Sponsors on Open Collective](https://opencollective.com/openemr/sponsors/badge.svg)](#sponsors)

# Clinical Co-Pilot — OpenEMR fork (Gauntlet AgentForge)

This repository is a fork of OpenEMR extended with a **Clinical Co-Pilot**: an
AI agent embedded in OpenEMR that orients a physician to the next patient in
the ~90 seconds between rooms. The work is split into two clearly separated
scopes — the **Week 1 baseline** (shipped) and the **Week 2 multimodal
evidence agent** (in progress) — described below.

- **Deployed app (live):** <https://openemr-production-4eba.up.railway.app>
  — hosted on Railway (two services: OpenEMR + MariaDB); deployment scaffolding
  in [`deploy/railway/`](deploy/railway/) and [`railway.json`](railway.json).
  Demo data only; no real PHI.
- **Setup guide:** [CONTRIBUTING.md](CONTRIBUTING.md) (full instructions) —
  quick start: `cd docker/development-easy && docker compose up --detach
  --wait`, then open <http://localhost:8300/> (login `admin` / `pass`). See
  also [DOCKER_README.md](DOCKER_README.md) and `CLAUDE.md`.

## 📋 Grader evidence — everything in one place

> Start here. Every artifact needed to evaluate this submission, with no repo
> spelunking. All patient data is synthetic; credentials are published
> deliberately for grading and will be rotated afterward.

### 1. Live deployment + credentials

- **App:** <https://openemr-production-4eba.up.railway.app> (Railway: OpenEMR + MariaDB)
- **Physician demo user (the persona the agent serves):** `dr.tran` / `Password123!`
- **Admin:** `admin` / `x3PzyDCLgEHWdhyE`

### 2. Exact demo workflow (~5 minutes)

1. Log in as **`dr.tran`** → open the in-EMR Co-Pilot tab:
   [`/interface/modules/custom_modules/oe-module-copilot/public/index.php`](https://openemr-production-4eba.up.railway.app/interface/modules/custom_modules/oe-module-copilot/public/index.php)
   (session-authenticated — no token, no UUID entry).
2. Select **Alma Reyes** from the Today's-patients dropdown → the snapshot
   shows the code-detected must-not-miss cards (panic potassium 6.8 +
   warfarin×aspirin interaction) with citation chips.
3. **Upload** the demo lab PDF —
   [`oe-module-copilot/demo/alma-reyes-bmp.pdf`](interface/modules/custom_modules/oe-module-copilot/demo/alma-reyes-bmp.pdf)
   (doc type `lab_pdf`) → status reaches `extracted`; the snapshot re-read
   lists the analytes under their extracted names (never "unknown" — TRO-56).
4. Ask a question with **"Include guideline evidence"** checked → the
   supervised evidence turn answers with grounded guideline citations.
5. **Click-to-source with the PDF bounding-box overlay** (required
   deliverable, TRO-44) lives in the token panel
   [`/interface/modules/custom_modules/oe-module-copilot/public/panel.html`](https://openemr-production-4eba.up.railway.app/interface/modules/custom_modules/oe-module-copilot/public/panel.html):
   paste base URL + a Bearer token + the patient UUID (all three printed by
   the token command below), run a turn on the uploaded document, then click
   an extracted-value citation → the source page renders (vendored PDF.js)
   with the field's box highlighted; guideline chips resolve to the real
   corpus chunk under their own "Guideline evidence" register.

**Get a Bearer token** (fully self-contained — the OAuth client below is
already registered + enabled on the deployment):

```sh
TOKEN=$(curl -s -X POST 'https://openemr-production-4eba.up.railway.app/oauth2/default/token' \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  --data-urlencode 'grant_type=password' \
  --data-urlencode 'client_id=O1vOqFmM6EhbaDwLu8DJJD77XXLEYtFlFf0hkz6vCqY' \
  --data-urlencode 'client_secret=2dGcJZ7aVj6H6lEPBGtB2rxRhrqltPtSS2SYnv-IKUixh66Aca5g83jr4-r0b-e__SrPa3lXvzcbfDju3zkzQQ' \
  --data-urlencode 'user_role=users' --data-urlencode 'username=admin' \
  --data-urlencode 'password=x3PzyDCLgEHWdhyE' \
  --data-urlencode 'scope=openid api:oemr api:fhir user/patient.read user/Patient.read user/health.read user/ready.read user/turn.write user/document.write user/source.write' \
  | python3 -c 'import json,sys; print(json.load(sys.stdin)["access_token"])')
```

**Patient UUID for the panel** (Alma Reyes):

```sh
curl -s -H "Authorization: Bearer $TOKEN" \
  'https://openemr-production-4eba.up.railway.app/apis/default/api/patient?fname=Alma' \
  | python3 -c 'import json,sys; print(json.load(sys.stdin)["data"][0]["uuid"])'
```

(The scripted alternative for both:
[`oe-module-copilot/demo/copilot-demo.sh`](interface/modules/custom_modules/oe-module-copilot/demo/copilot-demo.sh)
— `OE_PASS='x3PzyDCLgEHWdhyE' sh copilot-demo.sh token` from its directory.)

### 3. Health + readiness endpoints

Both are Bearer-token guarded (default-deny registrar — S5; token from the
command above):

```sh
curl -H "Authorization: Bearer $TOKEN" https://openemr-production-4eba.up.railway.app/apis/default/api/copilot/health
curl -H "Authorization: Bearer $TOKEN" https://openemr-production-4eba.up.railway.app/apis/default/api/copilot/ready
```

`/ready` returns **tri-state per-dependency statuses** (`ok | degraded |
failed` — db, trace_sink, llm, document-storage, vector-index, reranker); a
missing Cohere key reports `degraded` by name instead of failing readiness.

### 4. Observability dashboard

PHI-free trace dashboard (turn latency p50/p95, per-step counts, grounded vs
rejected claims, per-vendor + per-turn cost, per-turn supervisor routes):

```sh
railway ssh   # then, in the container:
php interface/modules/custom_modules/oe-module-copilot/bin/trace-dashboard.php
```

The trace file starts empty on each fresh deploy and fills as turns run
(fails loud, never invents metrics).

### 5. Alerts + runbook

- **SLOs + alarm conditions + circuit-breaker policy:**
  [`oe-module-copilot/docs/SLOS.md`](interface/modules/custom_modules/oe-module-copilot/docs/SLOS.md)
  — every number labeled MEASURED or PENDING MEASUREMENT.
- **Observability runbook (what is traced, where, and how to read it):**
  [`oe-module-copilot/docs/OBSERVABILITY.md`](interface/modules/custom_modules/oe-module-copilot/docs/OBSERVABILITY.md)

### 6. API surface

- **OpenAPI 3.0 spec (contract-tested against the live route table in both
  directions — drift fails CI):**
  [`oe-module-copilot/docs/openapi.yaml`](interface/modules/custom_modules/oe-module-copilot/docs/openapi.yaml)
- **Runnable Bruno collection (one documented request per route + env
  template):** [`oe-module-copilot/bruno/`](interface/modules/custom_modules/oe-module-copilot/bruno/)

### 7. Eval gate — command + latest results

50-case golden set, boolean rubrics, PR-blocking, vendor calls replayed from
committed input-keyed fixtures (unseen input fails loud), real MariaDB:

```sh
# in the dev container (see CLAUDE.md quick start):
vendor/bin/phpunit -c phpunit.xml tests/Tests/Services/Copilot
```

CI: [`clinical-accuracy-gate.yml`](.github/workflows/clinical-accuracy-gate.yml)
(runs ~1m35s, zero vendor network; includes a committed synthetic regression
plus a red-proof meta-test proving the gate can fail). **Latest results
(2026-07-15, `eval/goldenset/baseline.json` — all green):**
`citation_present 13/13 · critical_subset 14/14 · factually_consistent 39/39 ·
no_phi_in_logs 50/50 · safe_refusal 13/13 · schema_valid 15/15`.

### 8. Load tests — 10 and 50 users

Full report: [`oe-module-copilot/docs/LOAD_TEST.md`](interface/modules/custom_modules/oe-module-copilot/docs/LOAD_TEST.md)
(methodology, raw JSON, reproduction script). Headlines, run 2026-07-15
against this live deployment: **10 users — 100% success** on both the login
page and the authed API (API p95 246 ms). **50 users — login page 100%; API
path 78%**, failing fast on MariaDB `Too many connections` (single-container
DB ceiling; remediation named in the report).

### 9. Cost analysis

[`oe-module-copilot/docs/COST_MODEL.md`](interface/modules/custom_modules/oe-module-copilot/docs/COST_MODEL.md)
— the four vendor price models (vision/text/embed/rerank), per-document vs
per-question scaling separated, projection tiers (100/1K/10K/100K
encounters/month) each with an explicit Q/encounter assumption and named
architectural inflection. Cost is derivable **from traces alone**
(per-vendor + per-turn rollups on the dashboard); measured text-turn cost
~$0.019.

### 10. Known limitations (named, not hidden)

- **SMART patient-launch deferred (TRO-51):** the module runs on
  physician-wide `user/*` OAuth scopes + free-form patient UUID — a
  deliberate, documented v1 baseline; patient-bound launch is the epic'd fix.
- **Circuit breakers are contract-correct scaffolding, not deployment
  fail-fast:** per-request instances mean no cross-request state; persistent
  breaker storage is named follow-up (SLOS.md §3).
- **bbox overlay lives in the token panel**, not yet the in-EMR tab
  (port rides TRO-54's remainder).
- **50-user API ceiling** = MariaDB `max_connections` (LOAD_TEST.md).
- **Trace correlation is per-turn, not per-encounter** (root unification is
  a named follow-up); evidence and answer paths currently mint separate
  correlation roots.
- **Eval ground truth is founder-adjudicated** (clinician review is a named
  gap); judgment-rate items never hard-gate, the critical subset does.

---

## Week 1 — baseline: read-only orientation agent (shipped)

The Week 1 agent reads structured chart data and answers grounded questions —
it never writes to the record.

- **What it does:** in-EMR session panel (glanceable snapshot + multi-turn
  grounded Q&A), deterministic must-not-miss detectors (panic labs, drug–drug,
  drug–allergy, open follow-ups) that bypass the model entirely, claim
  verification with per-claim provenance, minimum-necessary LLM payloads with
  disclosure logging, PHI-free JSONL tracing with a correlation ID per turn,
  and a CI-armed clinical-accuracy gate.
- **Where it lives:** `interface/modules/custom_modules/oe-module-copilot/`
  (module + event subscriptions; no core edits). Tests:
  `tests/Tests/Isolated/Copilot/` (runs via `composer phpunit-isolated`).
- **Environment:** `ANTHROPIC_API_KEY` — the only AI-related variable. Without
  it, turns degrade honestly: deterministic findings intact, no prose answer.
- **Docs (Week 1 hard gates):** [`AUDIT.md`](AUDIT.md) — audit findings with
  one-page summary; [`USERS.md`](USERS.md) — Dr. Ellis Tran and use cases
  UC1–UC5; [`ARCHITECTURE.md`](ARCHITECTURE.md) — where the agent lives, data
  access, authorization boundaries, verification, risks, roadmap.

## Week 2 — multimodal evidence agent (in progress)

Week 2 adds the ability to **see clinical documents** and cite evidence:
ingestion of a lab PDF and an intake form with strict-schema VLM extraction,
hybrid RAG + rerank over a small clinical-guideline corpus, a supervisor +
two workers (intake-extractor, evidence-retriever) with logged handoffs, an
extended citation contract with click-to-source and PDF bounding-box overlay,
and a 50-case PR-blocking eval gate with boolean rubrics.

- **Write scope:** Week 1's never-write rule is amended by a **scoped,
  founder-approved carve-out (2026-07-13)**: the module may attach uploaded
  source documents and persist extracted facts as observations
  provenance-linked to their source — nothing else. Clinical write-back
  (notes/meds/orders) remains out of scope.
- **Plan (Week 2 architecture doc):** [`W2_ARCHITECTURE.md`](W2_ARCHITECTURE.md)
  — ingestion flow, worker graph, RAG design, eval gate, failure modes, data
  model, risks, and tradeoffs.
- **Status:** planning complete; implementation not started. As each stage
  lands, this section gains the exact run instructions (branch, environment
  variables — e.g. the Cohere key for embeddings/rerank — and any new
  services) so the Week 2 flow can be run without guessing.

## Additional evidence docs

- **Onboarding / evidence docs:** [`docs/onboarding/`](docs/onboarding/) —
  start at [`CURRENT_ARCHITECTURE.md`](docs/onboarding/CURRENT_ARCHITECTURE.md).

The upstream OpenEMR README follows.

---

# OpenEMR

[OpenEMR](https://open-emr.org) is a Free and Open Source electronic health records and medical practice management application. It features fully integrated electronic health records, practice management, scheduling, electronic billing, internationalization, free support, a vibrant community, and a whole lot more. It runs on Windows, Linux, Mac OS X, and many other platforms.

### Contributing

OpenEMR is a leader in healthcare open source software and comprises a large and diverse community of software developers, medical providers and educators with a very healthy mix of both volunteers and professionals. [Join us and learn how to start contributing today!](https://open-emr.org/wiki/index.php/FAQ#How_do_I_begin_to_volunteer_for_the_OpenEMR_project.3F)

> Already comfortable with git? Check out [CONTRIBUTING.md](CONTRIBUTING.md) for quick setup instructions and requirements for contributing to OpenEMR by resolving a bug or adding an awesome feature 😊.

### Support

Community and Professional support can be found [here](https://open-emr.org/wiki/index.php/OpenEMR_Support_Guide).

Extensive documentation and forums can be found on the [OpenEMR website](https://open-emr.org) that can help you to become more familiar about the project 📖.

### Reporting Issues and Bugs

Report these on the [Issue Tracker](https://github.com/openemr/openemr/issues). If you are unsure if it is an issue/bug, then always feel free to use the [Forum](https://community.open-emr.org/) and [Chat](https://www.open-emr.org/chat/) to discuss about the issue 🪲.

### Reporting Security Vulnerabilities

Check out [SECURITY.md](.github/SECURITY.md)

### API

Check out [API_README.md](API_README.md)

### Docker

Check out [DOCKER_README.md](DOCKER_README.md)

### FHIR

Check out [FHIR_README.md](FHIR_README.md)

### For Developers

If using OpenEMR directly from the code repository, then the following commands will build OpenEMR (Node.js version 24.* is required) :

```shell
composer install --no-dev
npm install
npm run build
composer dump-autoload -o
```

### Contributors

This project exists thanks to all the people who have contributed. [[Contribute]](CONTRIBUTING.md).
<a href="https://github.com/openemr/openemr/graphs/contributors"><img src="https://opencollective.com/openemr/contributors.svg?width=890" /></a>


### Sponsors

Thanks to our [ONC Certification Major Sponsors](https://www.open-emr.org/wiki/index.php/OpenEMR_Certification_Stage_III_Meaningful_Use#Major_sponsors)!


### License

[GNU GPL](LICENSE)
