# SEC-S11 · Characterization tests for the login core

| Field | Value |
|---|---|
| **Audit ref** | `S11` (AUDIT.md Audit 1 — Security) |
| **Severity** | Systemic (assurance gap) |
| **HIPAA nexus** | assurance — amplifies every other S-finding |
| **State** | todo |
| **Wave** | 1 |
| **Depends on** | — |
| **Blocks** | **SEC-S8** (the test net that makes the ctor refactor safe) |
| **Sign-off required** | no (adds tests only — does not modify auth code) |
| **Suggested worktree** | `sec-s11` |
| **Files touched** | new test files under `tests/` only |
| **Upstreamable?** | yes → very welcome `openemr/openemr` contribution |

## Problem
`src/Common/Auth/AuthUtils.php` + `library/auth.inc.php` (~1,400 lines: failed-login
lockout, timing-attack defense, LDAP, password expiry, session setup) have **no
direct unit tests** — only one E2E happy-path/wrong-password browser test exercises
them. Any regression in the most security-critical code in the app ships unguarded.
This is the enabling ticket: it lands a **characterization test net** so the
danger-zone refactors (S8, and later S4) can be made safely.

> **Bright line:** this ticket **adds tests only**. It must **not** modify
> `AuthUtils.php` or `auth.inc.php`. If a test is hard to write because the code
> is untestable, *document that seam* in the ticket notes — do not refactor here.

## Acceptance criteria
- [ ] Direct tests around `confirmUserPassword` covering the branches that matter:
      correct password, wrong password, unknown user (timing-defense path),
      IP-level lockout, per-user lockout, and the dummy-hash timing path.
- [ ] Tests pin the **observable current behavior** (characterization), not an
      idealized spec — the point is to catch *change*, so S8/S4 refactors are safe.
- [ ] Where `AuthUtils` reaches into globals/DB, use the project's existing test
      seams (fixtures / the DB-backed default suite) rather than editing the class.
      If a branch is untestable without a code change, list it under "Deferred
      seams" instead of forcing it.
- [ ] No production code changed.

## Implementation sketch
Study the existing E2E login test and any `tests/Tests/...Auth...` helpers for the
established fixture pattern. Prefer the DB-backed default suite (login touches
`users_secure`, `ip_tracking`, `globals`). Cover the lockout counters
(`AuthUtils.php:294-408` region) and the timing-defense dummy-hash path. Keep each
test one-branch-one-assertion so a future regression names itself.

## Test plan
- New `tests/Tests/.../Auth/AuthUtilsCharacterizationTest.php`.
- Run `openemr-cmd ut` (needs the stack). Note runtime — the login path is heavier
  than isolated tests.
- Record, in the ticket's PR description, any branch you could not reach and why
  (feeds S8's "make it testable" scope).

## Definition of done
- [ ] Acceptance criteria met · test docblocks cite `S11 (AUDIT.md)`
- [ ] New tests green · `pst` clean · `pr` clean · **zero production-code diff**
- [ ] "Deferred seams" list captured (hand off to SEC-S8)
- [ ] Commit `test(security): characterization tests for login core (S11)`
- [ ] Re-verified in main session → **unblocks SEC-S8**

## Dispatch brief
```
Close AUDIT.md finding S11 in the OpenEMR fork by adding a characterization test
net around the login core. ADD TESTS ONLY — do not modify AuthUtils.php or
auth.inc.php (danger zone).

Context: src/Common/Auth/AuthUtils.php and library/auth.inc.php (~1,400 lines:
lockout, timing-attack defense, LDAP, password expiry) have no direct unit tests.
This test net must land before the S8 constructor refactor so that refactor is
safe.

Task: write characterization tests (pin CURRENT behavior, not an ideal spec)
around AuthUtils::confirmUserPassword covering: correct password, wrong password,
unknown-user timing path, IP-level lockout, per-user lockout, dummy-hash timing
(see the lockout region ~AuthUtils.php:294-408). Follow the existing login-test
fixture pattern (grep tests/ for the E2E login test and any Auth test helpers);
use the DB-backed default suite since login touches users_secure/ip_tracking/
globals. One branch per test, clear assertion, docblock citing S11 (AUDIT.md).
If a branch is untestable without changing the class, DO NOT change the class —
list it under "Deferred seams" in your final report for the S8 ticket to pick up.
DoD: openemr-cmd ut green, pst + pr clean, and a git diff that touches ONLY files
under tests/. Commit test(security): characterization tests for login core (S11).
Report coverage achieved and the deferred-seams list.
```
