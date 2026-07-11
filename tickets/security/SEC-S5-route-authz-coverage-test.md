# SEC-S5 · Default-deny safety net — assert every REST route runs an authz check

| Field | Value |
|---|---|
| **Audit ref** | `S5` (AUDIT.md Audit 1 — Security) |
| **Severity** | Medium |
| **HIPAA nexus** | §164.312(a)(1) access control |
| **State** | todo |
| **Wave** | 1 |
| **Depends on** | — |
| **Sign-off required** | no |
| **Suggested worktree** | `sec-s5` |
| **Files touched** | new test file only (reads `src/RestControllers/Config/RestConfig.php`) |
| **Upstreamable?** | yes → the coverage test is a clean `openemr/openemr` PR |

## Problem
`src/RestControllers/Config/RestConfig.php:180` —
`request_authorization_check()` fails closed *when invoked*, but it is called by
hand inside each route closure with bare-string scopes. A new/edited route that
omits the call, or mistypes the scope pair, exposes PHI with **no compile-time,
DI, or test guard**. There is no default-deny gate every route passes through.

The proper prototype already exists in the copilot module —
`interface/modules/custom_modules/oe-module-copilot/src/Routes/GuardedRouteRegistrar.php`
and `AclRequirement.php` wrap each route so the check cannot be forgotten. This
ticket does **not** rewrite core dispatch (that's "a project, not a side quest"
per `CLAUDE.md`); it lands the cheap, high-value **safety net**: a test that fails
CI if any registered route lacks an authorization check.

## Acceptance criteria
- [ ] A test enumerates every route in `RestConfig::$ROUTE_MAP` (and the FHIR /
      portal route maps) and asserts each route's handler invokes an authorization
      check (`request_authorization_check`, the module's guarded registrar, or an
      explicit documented allow-listed public route).
- [ ] Any route missing a check **fails the test** with a message naming the route.
- [ ] Known-public routes (metadata/`.well-known`, capability statement, etc.) are
      captured in an explicit, reviewed allow-list constant in the test — so
      "public" is a deliberate, visible decision, not an omission.
- [ ] The test documents (in a docblock) that it is the interim guard until a
      real default-deny dispatch gate exists.

## Implementation sketch
Static-analysis-style test: reflect/parse the route map and, for each closure,
assert the presence of an authz call. Two viable strategies — pick the one that
holds up:
1. **Source scan** — get each closure's file+line via `ReflectionFunction`, read
   the closure body, assert it contains an authz call. Blunt but robust.
2. **Registry assertion** — if routes can be registered through a wrapper (like
   the copilot `GuardedRouteRegistrar`), assert every entry went through it.
Given core routes are raw closures today, (1) is the realistic interim net. Put
the allow-list of intentionally-public routes next to the assertion.

## Test plan
- New test under `tests/Tests/...` (default suite if it needs the route map
  bootstrapped; isolated if you can load `$ROUTE_MAP` without a DB).
- Prove it *fails* by temporarily adding a dummy route with no check, then remove
  the dummy — leave a comment noting you verified the negative.

## Definition of done
- [ ] Acceptance criteria met · test docblock cites `S5 (AUDIT.md)`
- [ ] Test green against current routes · `pst` clean · `pr` clean
- [ ] You verified the test actually fails on an unguarded route (negative check)
- [ ] Commit `test(security): assert every REST route runs an authz check (S5)`
- [ ] Re-verified in main session

## Dispatch brief
```
Close AUDIT.md finding S5 (the safety-net half) in the OpenEMR fork. Do NOT
rewrite core dispatch — add a TEST that fails if any REST route lacks an
authorization check.

Context: src/RestControllers/Config/RestConfig.php:180 defines
request_authorization_check(); it fails closed when called, but each route closure
must remember to call it — no gate enforces this. A working wrapper prototype is
interface/modules/custom_modules/oe-module-copilot/src/Routes/GuardedRouteRegistrar.php.

Task: write a PHPUnit test that enumerates every route in RestConfig::$ROUTE_MAP
(plus the FHIR and portal route maps) and asserts each route's handler performs an
authorization check. Missing check => test fails, naming the route. Maintain an
explicit, reviewed allow-list constant of intentionally-public routes (metadata,
.well-known, capability statement). Realistic approach: use ReflectionFunction to
locate each closure's source and assert it contains an authz call. Add a docblock
noting this is the interim guard until a real default-deny dispatch gate exists,
and cite S5 (AUDIT.md).
IMPORTANT: prove the test WORKS by temporarily adding an unguarded dummy route,
watch it fail, then remove the dummy; note that you did this.
DoD: test green on real routes, pst + pr clean. Commit test(security): assert
every REST route runs an authz check (S5). Do not edit route closures or auth
code. Report the route count covered and the allow-list you chose.
```
