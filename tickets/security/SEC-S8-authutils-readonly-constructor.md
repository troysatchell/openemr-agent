# SEC-S8 · Make `AuthUtils::__construct()` read-only (move bootstrap writes out)

| Field | Value |
|---|---|
| **Audit ref** | `S8` (AUDIT.md Audit 1 — Security) |
| **Severity** | Low–Medium |
| **HIPAA nexus** | availability / least-privilege |
| **State** | blocked |
| **Wave** | 2 |
| **Depends on** | **SEC-S11** merged (characterization test net) |
| **Sign-off required** | **YES** — danger zone (`AuthUtils`); `FOUNDER_ACTIONS.md` §2 |
| **Suggested worktree** | `sec-s8` |
| **Files touched** | `src/Common/Auth/AuthUtils.php` + a setup/migration path |
| **Upstreamable?** | yes → good `openemr/openemr` PR once green under S11's tests |

> 🛑 **Danger zone.** `AuthUtils` runs on every login. Do not start until
> **SEC-S11** has landed its characterization tests and they are green — the whole
> point of S11 is to let this refactor prove it changed nothing observable.

## Problem
`src/Common/Auth/AuthUtils.php:73` (constructor) → `:95-119` can `INSERT`/`UPDATE`
the `globals` table on **every** login attempt (authenticated or not): it lazily
creates/rehashes `hidden_auth_dummy_hash` (`:99,103,111`) and normalizes a blank
`password_expiration_days` to `0` (`:115-118`). Consequences:
- An unauthenticated attacker's login attempts trigger DB writes (side effect in a
  constructor; write amplification on the auth path).
- The auth path cannot run against a read-replica or a least-privilege (read-only)
  DB user, because construction itself may write.
Steady-state it is a single read (`:95`/`:115`), so impact is bounded — this is a
correctness/least-privilege cleanup, not an active vuln.

## Acceptance criteria
- [ ] `AuthUtils::__construct()` performs **no** `INSERT`/`UPDATE` — reads only.
- [ ] `hidden_auth_dummy_hash` creation/rehash and the `password_expiration_days`
      normalization move to a setup/migration/first-run path that runs once, not
      per-login. If the value is absent at login time, the constructor tolerates it
      (uses a safe in-memory default) without writing.
- [ ] The timing-attack dummy-hash defense still works: an unknown-user login still
      does the constant-time comparison against a valid dummy hash.
- [ ] **SEC-S11's characterization tests still pass unchanged** — behavior observable
      to a caller is identical (that is the safety proof).

## Implementation sketch
1. Confirm S11's tests are green on your branch first (they are your net).
2. Move the write-side bootstrap:
   - `hidden_auth_dummy_hash`: seed it via a Doctrine migration / setup step; at
     runtime, if the row is missing, compute an ephemeral dummy hash in memory for
     the timing defense **without** persisting.
   - `password_expiration_days` blank→0: normalize on read in memory; fix the stored
     blank via the same migration, not from the login path.
3. Annotate:
   ```php
   // S8 (AUDIT.md): constructor is read-only; the dummy-hash / expiry bootstrap
   // moved to setup so unauthenticated login attempts cannot trigger DB writes.
   ```

## Test plan
- Re-run SEC-S11's `AuthUtilsCharacterizationTest` — must stay green.
- Add a focused test: constructing `AuthUtils` issues **no write** (assert via a
  DB spy / query log, or by running against a read-only connection and asserting no
  exception/no write). Cover the "dummy-hash row missing" branch → timing defense
  still functions, still no write.
- Pick up any "Deferred seams" S11 handed off.

## Definition of done
- [ ] **Founder sign-off recorded** before edit · **SEC-S11 green on branch**
- [ ] Constructor write-free · timing defense intact · S11 tests still green
- [ ] New no-write test added · `openemr-cmd ut` green · `pst` clean · `pr` clean
- [ ] Migration/setup step added for the moved bootstrap
- [ ] Commit `refactor(security): make AuthUtils constructor read-only (S8)`
- [ ] Re-verified in main session

## Dispatch brief
```
Close AUDIT.md finding S8 in the OpenEMR fork. Make AuthUtils's constructor
read-only. DANGER ZONE (AuthUtils runs on every login) — founder sign-off required
before editing, and SEC-S11's characterization tests MUST be merged and green on
your branch first (they prove you changed nothing observable).

Context: src/Common/Auth/AuthUtils.php:73 (ctor) -> :95-119 can INSERT/UPDATE the
globals table on every login: it lazily creates/rehashes hidden_auth_dummy_hash
(:99,103,111) and normalizes blank password_expiration_days to 0 (:115-118). This
lets unauthenticated login attempts trigger DB writes and blocks running auth
against a read-only DB user.

Task: remove all INSERT/UPDATE from the constructor. Move the dummy-hash seed and
the password_expiration_days normalization to a Doctrine migration / setup step
that runs once. At runtime, if hidden_auth_dummy_hash is missing, compute an
ephemeral dummy hash IN MEMORY for the timing defense without persisting; normalize
blank expiry on read in memory. Keep the constant-time unknown-user timing defense
working. Annotate with // S8 (AUDIT.md):.
Verify SEC-S11's AuthUtilsCharacterizationTest still passes unchanged, and add a
test asserting the constructor performs no DB write (DB spy / read-only connection)
including the "dummy-hash row missing" branch.
DoD: founder sign-off recorded; S11 tests green; new no-write test green;
openemr-cmd ut + pst + pr green; migration added. Commit refactor(security): make
AuthUtils constructor read-only (S8). Report exactly what moved and where.
```
