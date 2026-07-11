# Security Remediation Board — swarm-ready tickets

Operational backlog for the outstanding OpenEMR security findings in
[`AUDIT.md`](../../AUDIT.md) (Audit 1 — Security). Each finding `S#` is a
self-contained ticket file in this directory, written so a **cold-start agent**
can pick it up with no other context (per `CLAUDE.md`: subagents start with no
parent context — the ticket *is* the dispatch brief).

> **Scope note.** This is the **OpenEMR-hardening track**, distinct from the
> `oe-module-copilot` build. It edits core files, which the copilot bright line
> forbids — but the fork already established this pattern when it closed
> **S1/S2/S3**: security fixes edit core and annotate the change with a
> `// S# (AUDIT.md):` comment (see `apis/dispatch.php:42`,
> `src/Common/Session/SessionConfigurationBuilder.php:83`). Every ticket here
> follows that precedent. Where a fix is genuinely upstreamable, prefer a PR to
> `openemr/openemr` over carrying it in the fork (noted per-ticket).

## Status legend

| State | Meaning |
|---|---|
| `todo` | Not started, unblocked |
| `blocked` | Waiting on a dependency or a founder sign-off |
| `wip` | An agent/worktree owns it |
| `review` | Code done, quality gates green, awaiting merge review |
| `done` | Merged to `deploy/railway` |

## The board

**Wave 1 + 2 landed (2026-07-10)** — all 8 tickets green on `deploy/railway`,
pushed to both remotes. Wave 1 (S9/S7/S10/S6/S5/S11) via the sequential TDD relay
(Sonnet 5 implementers, orchestrator-frozen tests). Wave 2: S4 phase-1 (docs-only
`$ignoreAuth` allow-list) + S8 (AuthUtils read-only constructor, founder-approved
Doctrine-migration approach) done in the main session under sign-off. Only S4
phase-2 (a log-only runtime `$ignoreAuth` assertion) remains deferred.

| ID | Finding | Sev | Depends on | Sign-off? | Wave | State |
|----|---------|-----|-----------|-----------|------|-------|
| [SEC-S9](SEC-S9-routing-test-env-guard.md)  | Routing-test hook on prod path | High | — | no | 1 | ✅ `done` `115e17c` |
| [SEC-S7](SEC-S7-display-errors-prod-guard.md) | `display_errors=1` in prod | Med | — | no | 1 | ✅ `done` `7c581a8` |
| [SEC-S10](SEC-S10-audit-toggle-security-event.md) | Audit logging silently disableable | Low(compliance) | — | no | 1 | ✅ `done` `e33ec85` |
| [SEC-S5](SEC-S5-route-authz-coverage-test.md) | No default-deny route gate | Med | — | no | 1 | ✅ `done` `7d3b5fd` |
| [SEC-S6](SEC-S6-background-callable-allowlist.md) | Executable config in `background_services` | Med | — | governance doc | 1 | ✅ `done` `e21ac7f` |
| [SEC-S11](SEC-S11-login-core-tests.md) | Login core has no unit tests | Systemic | — | no | 1 | ✅ `done` `508304e` |
| [SEC-S4](SEC-S4-ignoreauth-allowlist.md) | Auth hinges on `$ignoreAuth` global | High | — | signed off | 2 | ✅ `done` `ef1dcac` (phase-1 docs; phase-2 deferred) |
| [SEC-S8](SEC-S8-authutils-readonly-constructor.md) | `AuthUtils` ctor writes to DB | Low–Med | — | signed off | 2 | ✅ `done` `a924703` |
| [SEC-101](SEC-101-signalwire-webhook-signature.md) | SignalWire fax webhook has no signature check | Med | — | given (proceed) | — | 🔵 `review` — implemented `93faf62`; pending live confirmation + merge |

**Net-new (post-S4-review):** [SEC-101](SEC-101-signalwire-webhook-signature.md)
was surfaced 2026-07-11 while verifying the S4 REVIEW entries in
[`ignoreauth-allowlist.md`](../../docs/security/ignoreauth-allowlist.md) (bucket
D). Nine of the ten REVIEW entries were confirmed adequately controlled; this
was the one gap — an inbound, state-changing webhook with no authenticity
verification. It edits the FaxSMS module (not core), but adds an authentication
control, so it carries a founder sign-off gate like S4/S8.

## Swarm plan (parallelization)

Two waves. Within a wave, every ticket is **file-disjoint** from the others, so
they run concurrently in separate worktrees with zero merge conflict.

```
WAVE 1  — 6-way parallel, no sign-off, no cross-deps
  ├─ SEC-S9   dispatch.php + FallbackRouter.php
  ├─ SEC-S7   interface/globals.php
  ├─ SEC-S10  library/globals.inc.php
  ├─ SEC-S5   tests/ (new file)
  ├─ SEC-S6   BackgroundServiceRunner.php + registry
  └─ SEC-S11  tests/ (new files)     ── unblocks S8

WAVE 2  — 2-way parallel, AFTER Wave 1 merges + founder sign-off
  ├─ SEC-S4   interface/globals.php   (needs S7 merged first — same file)
  └─ SEC-S8   AuthUtils.php           (needs S11 merged first — test net)
```

### Conflict matrix (why the waves are shaped this way)

| Shared file | Tickets | Rule |
|---|---|---|
| `interface/globals.php` | **S7, S4** | Never same wave. S7 (Wave 1) merges → S4 (Wave 2) rebases onto it. |
| `src/Common/Auth/AuthUtils.php` | S11 (adds tests), S8 (refactors) | S11 lands the test net first, *then* S8 refactors under it. Dependency, not a file clash — S11 only adds files under `tests/`. |

Everything else is file-disjoint → safe to fan out.

## How to run a swarm

Each ticket carries a **"Dispatch brief"** section — copy it verbatim into an
agent prompt. One worktree + one agent per ticket:

```bash
# 1. Spin up an isolated stack per ticket (branch off the current work,
#    NOT canonical master, so the fix lands on deploy/railway).
openemr-cmd worktree add sec-s9 -b --base deploy/railway --start
openemr-cmd worktree add sec-s7 -b --base deploy/railway --start
# ... one per Wave-1 ticket ...

# 2. Dispatch one agent per worktree with that ticket's "Dispatch brief".
#    (In Claude Code: the copilot-engineer subagent, or general-purpose.)

# 3. Each agent's Definition of Done requires green gates IN ITS worktree:
openemr-cmd worktree exec sec-s9 ut     # unit tests
openemr-cmd worktree exec sec-s9 pst    # PHPStan L10
openemr-cmd worktree exec sec-s9 pr     # phpcs

# 4. Re-run the gate in the MAIN session before accepting "done"
#    (CLAUDE.md: a subagent's "done" is a claim until re-verified).
```

**Worktree base matters:** `-b` alone branches off canonical `openemr/openemr`
master, which lacks this repo's Phase-1 fixes and these ticket files. Always pass
`--base deploy/railway` (or `--base HEAD`) so the swarm builds on current work.

## Governance gates (founder-owned — do not let an agent self-approve)

- **SEC-S4, SEC-S8** touch the `AuthUtils` / `globals.php` / auth danger zone
  (`CLAUDE.md` "Danger zones"; `FOUNDER_ACTIONS.md` §2). Sign-off required
  before Wave 2 dispatch, and **SEC-S11 must be green first** so the refactors
  land under a test net.
- **SEC-S6** ships a code allow-list, but "who may insert `background_services`
  rows" is a deployment policy call — the ticket produces a governance-doc stub
  for you to ratify.

## Adding tickets

Copy [`TEMPLATE.md`](TEMPLATE.md). ID scheme: `SEC-S#` maps 1:1 to an audit
finding; use `SEC-1xx` for net-new security items not in `AUDIT.md`. Keep the
board table and the conflict matrix in sync when you add one.

## Submission hygiene

`KANBAN.md` and other internal PM docs were dropped from the submission in
`8653d5a`. This `tickets/` tree is internal ops — decide whether to keep it on
the working branch only, `.gitignore` it, or (recommended) keep it, since a
concrete, operationalized security remediation plan is a *strength* to show.
