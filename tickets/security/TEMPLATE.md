# SEC-S# · <short title>

| Field | Value |
|---|---|
| **Audit ref** | `S#` (AUDIT.md Audit 1 — Security) |
| **Severity** | High / Med / Low / Systemic |
| **HIPAA nexus** | §164.312(...) |
| **State** | todo / blocked / wip / review / done |
| **Wave** | 1 / 2 |
| **Depends on** | — |
| **Sign-off required** | no / YES (reason) |
| **Suggested worktree** | `sec-s#` |
| **Files touched** | `path/one`, `path/two` |
| **Upstreamable?** | yes → PR to `openemr/openemr` / no → fork-local |

## Problem
<what's wrong, with exact `file:line` cites from current HEAD>

## Acceptance criteria
- [ ] <testable outcome>
- [ ] <testable outcome>

## Implementation sketch
<the intended change; reference the S1–S3 precedent pattern where relevant>

## Test plan
<new/changed tests; command to run>

## Definition of done
- [ ] Acceptance criteria met
- [ ] Inline `// S# (AUDIT.md):` comment marks the change
- [ ] `openemr-cmd ut` (or `pit`) green in the worktree
- [ ] `openemr-cmd pst` (PHPStan L10) clean — no new baseline entries
- [ ] `openemr-cmd pr` (phpcs) clean
- [ ] Conventional-commit message (`fix(security): ...` or `test(security): ...`)
- [ ] Re-verified in the main session (subagent "done" is a claim until re-run)

## Dispatch brief
> Copy the block below verbatim into the swarm agent's prompt.

```
<self-contained task: what to change, exact files+lines, acceptance criteria,
which tests to add/run, and the danger-zone/sign-off constraints>
```
