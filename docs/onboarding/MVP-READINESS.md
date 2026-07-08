# MVP-Readiness — Gauntlet AgentForge Week 1 (Tuesday 11:59 PM CT gate)

> Status of each MVP submission requirement as of **2026-07-07**. MVP =
> app audit + user doc + agent plan + deployed app URL + demo video
> (`Projectcompleteion  guidelines/Week_1_AgentForge.pdf`, Schedule + Stages
> 1–5). Statuses: **DONE** (verifiable in-repo), **NEEDS-HUMAN** (only the
> human can complete/decide), **GAP** (missing, no owner yet). Evidence is
> cited; nothing here is asserted without it.

| # | Requirement | Status | Evidence (one line) |
|---|---|---|---|
| 1 | Deployed app, publicly accessible | **DONE** | `https://openemr-production-4eba.up.railway.app` returns HTTP 302 → `interface/login/login.php?site=default` (checked 2026-07-07); deploy scaffolding tracked at `deploy/railway/`, `railway.json`. |
| 2 | Deployed URL surfaced in README/repo | **DONE** | Root `README.md` now opens with the fork banner linking the live URL + the three hard-gate docs. |
| 3 | Setup guide in repo | **DONE** | `README.md` quick start (docker compose, localhost:8300, admin/pass) pointing to `CONTRIBUTING.md` + `DOCKER_README.md`; matches the dev stack in `CLAUDE.md`. |
| 4 | `./AUDIT.md` — all findings, 5 parts, opens with ~500-word summary | **DONE** | Root `AUDIT.md`: one-page summary → Part 0 AI-impact prioritization → Parts 1–5 (security / performance / architecture / data quality / compliance & regulatory incl. HIPAA-specific pass), findings verbatim from the former onboarding audit docs (consolidated 2026-07-07; originals removed, content lives only in root `AUDIT.md`) with `file:line` + IDs intact. |
| 5 | `./USERS.md` — target user, workflow, use cases, each with explicit "why an agent" | **DONE** | Root `USERS.md` §5 defines UC1–UC5, each with a "Why an agent (not a dashboard/list/chart)" answer; workflow in §§3–4; epistemic-status banner preserved (persona explicitly unvalidated — no fabricated validation). |
| 6 | `./ARCHITECTURE.md` — agent plan, opens with ~500-word summary, traces to USERS.md | **DONE** | Root `ARCHITECTURE.md`: Executive Summary (~500 words) with the four key decisions + tradeoffs; §1 capability→UC trace table; §4 authorization boundaries; §5 failure modes/risks; §6 verification + evaluation gate + observability; §2 framework/model choices. |
| 7 | Demo video (3–5 min) showcasing work + key decisions | **NEEDS-HUMAN** | No video artifact exists in the repo or submission folder; must be recorded against the deployed app by the human. |
| 8 | AI interview readiness (required ≤24 h after submission) | **NEEDS-HUMAN** | Prep material exists and maps to the interview areas (`ARCHITECTURE.md` §8 "Decisions to defend"; `docs/onboarding/PRE_SEARCH.md` incl. "The five things a defender must not soft-pedal") — rehearsal is on the human. |

## Caveats and flags (not blockers for the doc gates)

- **Public EMR with default-ish admin credentials.** The Railway deployment is
  internet-reachable with an admin login; guidelines require demo data only
  (satisfied) but rotating the admin password / restricting access before
  submission is prudent. *(NEEDS-HUMAN; deployment work was explicitly out of
  scope for this pass.)*
- **`USER.md` vs `USERS.md`.** The guidelines' submission table once says
  `./USER.md`; Stage 4's hard gate and every other mention say `./USERS.md`.
  We ship `USERS.md` (root). If the grader's checklist is literal about
  `USER.md`, add a stub — human's call.
- **"Agent works in the live environment"** is an Early/Final requirement, not
  MVP ("MVP is not a working agent"). Nothing implemented yet, by design —
  `ARCHITECTURE.md` is explicit about that.
- **Eval dataset, cost analysis, observability dashboards, load tests,
  API collection, /health-/ready** are Early/Final-submission engineering
  requirements — planned in `ARCHITECTURE.md` §6 / `PRE_SEARCH.md` §§8–13 but
  not built; out of scope for the MVP gate.
- **Unverified:** whether the submission form itself has been filled (URL,
  repo link, video link) — not observable from the repo.
