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

## For graders — the 90-second live walkthrough

Credentials for the deployed app are delivered with the submission (never
committed). Then:

1. **Log in** at <https://openemr-production-4eba.up.railway.app> → the
   Calendar shows today's seeded patients.
2. **Click a patient** (Alma Reyes showcases the most) → in the chart's left
   menu (Dashboard / History / … / External Data) click **Co-Pilot**. That
   one click runs a real SMART EHR-launch (OAuth2 + PKCE, confidential
   client, patient-scoped token) and lands in the panel — no token or uuid
   entry. The **patient snapshot renders on arrival**: code-detected panic-lab
   and drug-interaction cards, new labs, meds, allergies.
3. **Upload** her lab PDF (Upload a document → Lab PDF) → the status line
   reports `extraction_status: extracted` — the scoped two-write amendment
   (attach + persist extracted facts with per-field lineage).
4. **Ask** *"Anything new on her labs?"* → grounded claims cite the values
   just extracted. **Click an extracted lab's citation chip** → the source
   PDF renders with a bounding-box overlay on the region the value was read
   from. A seeded lab's chip resolves to a plain chart pointer — the
   provenance contract at work.
5. **Observability, live** (Railway CLI):
   ```bash
   railway ssh --service openemr -- php /var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-copilot/bin/trace-dashboard.php
   railway ssh --service openemr -- php /var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-copilot/bin/alert-check.php --window=0
   ```
   Per-step latency, tokens, cost, grounded/rejected counts, and the three
   wired alerts — all from the PHI-free trace your clicks just generated.

**Important:** hand-minted API tokens are refused (403) on the clinical
routes by design — the launch-bound token is the only clinical path
(TRO-52). Use the chart-menu launch above, not manual tokens. Full runbook
incl. seeding and known quirks:
[`demo/DEMO.md`](interface/modules/custom_modules/oe-module-copilot/demo/DEMO.md).

**Key documents:**

| What | Where |
|---|---|
| Week 2 architecture (ingestion, worker graph, RAG, eval gate, risks) | [`W2_ARCHITECTURE.md`](W2_ARCHITECTURE.md) |
| Week 1 architecture / audit / user & use cases | [`ARCHITECTURE.md`](ARCHITECTURE.md) · [`AUDIT.md`](AUDIT.md) · [`USERS.md`](USERS.md) |
| Measured E2E latency + cost (production, 2026-07-16) | [`docs/W2_LATENCY_COST.md`](docs/W2_LATENCY_COST.md) |
| SLOs, measured baseline, circuit breakers, **RPO/RTO (as-built)** | [`docs/SLOS.md`](interface/modules/custom_modules/oe-module-copilot/docs/SLOS.md) |
| Observability: logs/trace/alerts + the wired alert checker | [`docs/OBSERVABILITY.md`](interface/modules/custom_modules/oe-module-copilot/docs/OBSERVABILITY.md) |
| API: OpenAPI 3.0 spec + Bruno collection | [`docs/openapi.yaml`](interface/modules/custom_modules/oe-module-copilot/docs/openapi.yaml) · [`bruno/`](interface/modules/custom_modules/oe-module-copilot/bruno/) |
| Eval gate (50-case golden set, PR-blocking, red-proof) | `tests/Tests/Isolated/Copilot/Eval/` + committed baseline |

**Run the suites** (in the dev container): `composer phpunit-isolated`
(includes the whole copilot suite + the eval gate; no DB or API keys needed —
vendor calls are fixture-replayed) and `vendor/bin/phpunit --testsuite
services --filter Copilot` for the DB-backed integration tests.

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
- **Architecture doc:** [`W2_ARCHITECTURE.md`](W2_ARCHITECTURE.md)
  — ingestion flow, worker graph, RAG design, eval gate, failure modes, data
  model, risks, and tradeoffs.
- **Status: SHIPPED and live** on the deployed app (see the grader
  walkthrough above). Highlights beyond the plan: patient-scoped SMART
  EHR-launch from the chart menu (one click, no token entry; silent launch
  via core's `skip_ehr_launch_authorization_flow` for this first-party
  client), on-arrival glanceable snapshot in the launched panel, the wired
  alert checker (`bin/alert-check.php`), and production-measured latency and
  cost. Environment variables: `ANTHROPIC_API_KEY` (answers + VLM
  extraction; absent = honest degradation), `COHERE_API_KEY`
  (embeddings/rerank; absent = evidence retrieval degrades, panel stays up),
  `COPILOT_SMART_CLIENT_ID`/`COPILOT_SMART_CLIENT_SECRET` (the SMART
  launch's confidential client — registration steps in
  [`docs/TRO51_SMART_LAUNCH_DESIGN.md`](docs/TRO51_SMART_LAUNCH_DESIGN.md)).

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
