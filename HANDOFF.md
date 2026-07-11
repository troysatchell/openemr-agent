# Handoff — Security Hardening + Copilot Eval-Readiness

**Date:** 2026-07-11 · **Branch:** `deploy/railway` @ `ad4a180` ·
**Remotes:** pushed to `origin` (GitLab) + `github` (mirror), in sync ·
**Scope of this session:** 21 commits, 41 files, +2,329 / −50.

---

## TL;DR

Two bodies of work landed, both green and pushed:

1. **OpenEMR security hardening** — closed audit findings **S4–S11** (S1–S3 were
   already done) plus the shared `Kernel::isDev()` env-detection root cause.
   6 production fixes, 3 CI/test nets, 1 enumerated allow-list. The whole diff is
   **PHPStan L10 clean** and there's a CWE-mapped evidence report.
2. **Copilot module eval-readiness** — assessed `oe-module-copilot` and closed its
   one blocking gap: **11 PHPStan L10 errors → 0**, with the **388-test suite still
   green**.

Nothing is broken. The full isolated suite is **3,926 tests, 0 failures**.

---

## What was accomplished

### A. Security remediation (AUDIT.md Audit 1)

Delivered via a **sequential frozen-test TDD relay** (orchestrator freezes a red
test → Sonnet 5 agent implements → orchestrator re-verifies → commit). The only
auth-code change (S8) was made **after explicit founder sign-off**.

| # | Finding | Severity | Fix | Commit |
|---|---|---|---|---|
| **S6** | Executable config in `background_services` (RCE-enabler) | High ⬆︎ *(audit: Med)* | callable allow-list + runner guard | `e21ac7f` |
| **S7** | `display_errors=1` reachable in prod | Med | prod clamp via `ErrorDisplayPolicy` | `7c581a8` |
| **S8** | `AuthUtils` ctor writes to DB on every login | Med *(danger zone)* | read-only ctor + Doctrine migration | `a924703` |
| **S9** | `/_routing_test` hook answers anon on prod path | Low | dev-gate the hook | `115e17c` |
| **S10** | Audit-control toggles changed silently | Med (HIPAA §164.312(b)) | tamper-log the 10 granular toggles | `e33ec85` |
| **S5** | No default-deny route gate | Med | CI coverage test (regression net) | `7d3b5fd` |
| **S11** | Login core had no tests | Systemic | characterization net | `508304e` |
| **S4** | Auth hinges on `$ignoreAuth` ambient global | High *(partial)* | allow-list doc + **CI gate** | `ef1dcac`, `6484a2f` |
| **Env** | `Kernel::isDev()` root cause of S9/S7 | Low | `getenv()` fallback (works under `variables_order=GPCS`) | `1c319b4` |

**Eval-hardening pass:** verified the whole diff PHPStan L10 clean (`f4712b9` —
caught + fixed a real by-reference bug in the S8 migration; decremented 3 stale
`AuthUtils` baseline counts; type-narrowed the S10 loop), and produced
[`SECURITY_REMEDIATION.md`](SECURITY_REMEDIATION.md) — the CWE→severity→fix→test→SHA→verification matrix.

### B. Copilot module eval-readiness

- **Assessment:** 388 tests / 1,316 assertions (0.16s), 85/91 classes referenced
  by tests (the 6 unreferenced are DTOs / null-object / `Bootstrap` / the live
  FHIR gateway), accuracy gate **armed with real teeth** (14 adjudicated golden
  fixtures; `HardZeroGateTest` proves one spurious flag fails the gate), CI wired
  (`clinical-accuracy-gate.yml`, `isolated-tests.yml`, `phpstan.yml`), docs present.
- **Blocking gap closed (`ad4a180`):** the module carried **11 unbaselined PHPStan
  L10 errors** (mostly in the newest T21 panel code). All fixed — annotation /
  narrowing only, **no behavior change**, 388 tests still green. Details in the
  commit body.

---

## Current state (verify anytime)

```bash
# Full isolated suite — 3,926 tests, 0 failures
openemr-cmd pit         # or: docker compose ... exec -T openemr sh -c '... phpunit -c phpunit-isolated.xml'

# Copilot module suite — 388 tests
vendor/bin/phpunit -c phpunit-isolated.xml tests/Tests/Isolated/Copilot

# Security DB-backed tests
vendor/bin/phpunit tests/Tests/Common/Auth/AuthUtilsCharacterizationTest.php

# PHPStan L10 (full run OOMs locally — run TARGETED on changed files):
vendor/bin/phpstan analyze -c phpstan.neon.dist --memory-limit=4G --no-progress \
  --error-format=raw <changed files>    # security diff AND copilot module = 0 errors
```

- **Tests:** all green. **PHPStan L10:** clean on the security diff and the copilot module.
- **CI gates:** clinical-accuracy-gate (armed), isolated-tests, phpstan.
- **Untracked:** only `FOUNDER_ACTIONS.md` (deliberately left out; see below).

## Key artifacts (where to look)

| Artifact | Purpose |
|---|---|
| [`SECURITY_REMEDIATION.md`](SECURITY_REMEDIATION.md) | Evidence report: finding → CWE → fix → test → SHA → verification |
| [`tickets/security/`](tickets/security/README.md) | Per-finding tickets + the swarm/TDD-relay method + conflict matrix |
| [`docs/security/ignoreauth-allowlist.md`](docs/security/ignoreauth-allowlist.md) | The 45-file `$ignoreAuth` opt-out allow-list (enforced by CI test) |
| [`docs/security/background-services-policy.md`](docs/security/background-services-policy.md) | S6 governance stub (who may insert `background_services` rows) |
| [`AUDIT.md`](AUDIT.md) | The original audit (evidence base, finding IDs) |

---

## Open items / next steps (ranked)

**Copilot (the eval target):**
1. `OpenEmrFhirGateway` has no isolated test (needs a live stack) — add a thin
   contract test or an explicit "covered by live smoke only" note.
2. Golden fixtures are **founder-adjudicated, not clinician-adjudicated** — a
   documented *validation* limitation, not a defect. Have the honest framing ready.

**Security (a strong place to pause):**
3. **S4 full middleware** — the opt-*out* front-controller gate (auth on by
   default) is a separate **danger-zone project**; the CI gate prevents *new*
   opt-outs meanwhile.
4. **S4 REVIEW items** — human verification that the payment/FaxSMS webhooks
   validate signatures, portal reset/verify flows are token-gated, and the utility
   endpoints are intentionally anonymous (listed in the allow-list doc).
5. **S11 deferred seams** — the stateful lockout/timing/LDAP branches — now
   testable since S8 exists.

**Housekeeping:**
6. **`FOUNDER_ACTIONS.md`** is untracked — decide whether to commit, `.gitignore`,
   or leave local (submission-hygiene call). `tickets/` is committed.

---

## Gotchas the next session needs

- **PHPStan full-codebase run OOMs** in this container. Run **targeted** per-file
  (`--error-format=raw <files>`); CI runs the full pass. The baseline is split
  under `.phpstan/baseline/*.php` by error type with **count-based** entries —
  reducing an anti-pattern in a file makes a count mismatch (decrement it); adding
  a deprecated call grows one (prefer the modern API instead).
- **`Kernel::isDev()` / env detection:** this runtime's `variables_order` is `GPCS`
  (no `E`), so `$_ENV` is empty. `isDev()` now has a `getenv()` fallback; any new
  env-gate must too. The dev container has `OPENEMR__ENVIRONMENT` **empty** (so
  `isDev()` is false there — that's correct).
- **The copilot module is CI-PHPStan-analyzed and NOT baselined** → module code
  must be written L10-clean. The relay's "php -l + phpcs locally, PHPStan in CI"
  convention let 11 L10 errors accumulate; keep new module code clean.
- **Custom `ForbiddenCatchTypeRule`:** `catch (\Throwable)` **and** `catch (\Exception)`
  are both forbidden (they catch `\Error`/`\ErrorException`) **unless** the catch
  body ends in an unconditional `throw;`. To swallow only dependency failures,
  catch `\Throwable $e` and re-throw when `$e instanceof \Error || $e instanceof \ErrorException`.
- **Worktrees:** use `openemr-cmd worktree`, never raw `git worktree` (see CLAUDE.md).
  This session ran a *sequential* relay on the single shared tree/container — true
  parallel swarms aren't available here (`openemr-cmd` isn't on the host).

## How to pick up

The natural next move is **copilot open item #1** (the `OpenEmrFhirGateway` test)
or, if pivoting back, the **S4 REVIEW items** (webhook signature verification). Both
are self-contained. The tickets and the remediation report have the full context;
`CLAUDE.md` has the prime directives and bright lines.
