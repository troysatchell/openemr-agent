# SEC-S9 · Env-gate the `/_routing_test` hook off the production path

| Field | Value |
|---|---|
| **Audit ref** | `S9` (AUDIT.md Audit 1 — Security) |
| **Severity** | Low |
| **HIPAA nexus** | information disclosure (§164.312(c) integrity context) |
| **State** | todo |
| **Wave** | 1 |
| **Depends on** | — |
| **Sign-off required** | no |
| **Suggested worktree** | `sec-s9` |
| **Files touched** | `apis/dispatch.php`, `src/BC/FallbackRouter.php` (+ test) |
| **Upstreamable?** | yes → good `openemr/openemr` PR candidate |

## Problem
`apis/dispatch.php:29` calls `FallbackRouter::handleRoutingTestIfRequested($request->getRequestUri(), 'apis')`
**unconditionally**. `src/BC/FallbackRouter.php:188-197` returns HTTP `418` with
`{"routed":"apis"}` for any URI ending `/_routing_test`. It leaks no PHI, but a
test affordance answering anonymous probers sits on the production dispatch path
and confirms the routing layer to an attacker. The same hook is wired into the
other entry points that call `handleRoutingTestIfRequested`.

## Acceptance criteria
- [ ] In a production environment, a request to `.../_routing_test` behaves as if
      the hook does not exist (falls through to normal 404/routing), returning no
      `418`/`{"routed":...}`.
- [ ] In non-production (dev/test/CI), the hook still returns `418` so the E2E
      routing tests that rely on it keep passing.
- [ ] The gate reads the environment the same way the rest of the app does
      (`OPENEMR__ENVIRONMENT`, via the existing environment accessor — do **not**
      read `$_ENV`/`getenv` raw if a helper exists; grep for how `display_errors`
      / `SiteSetupListener` already resolves environment).

## Implementation sketch
Guard inside `handleRoutingTestIfRequested()` (single choke point, keeps all
callers safe): early-return before emitting `418` when the resolved environment
is production. Prefer gating in the method body over each call site so no future
entry point re-introduces the exposure. Annotate:
```php
// S9 (AUDIT.md): the routing-test affordance is dev/test only; in production
// it must not confirm the routing layer to anonymous callers.
```

## Test plan
- Add an isolated test (`tests/Tests/Isolated/...`) that calls the method (or a
  thin extracted predicate) with environment=production and asserts no output /
  no exit, and environment=dev asserts the `418` path. If `exit`/`http_response_code`
  makes it hard to unit-test, extract the "should respond?" decision into a pure
  method and test that.
- Confirm existing E2E routing tests still pass (`openemr-cmd et` if the stack is up).

## Definition of done
- [ ] Acceptance criteria met · inline `// S9 (AUDIT.md):` comment present
- [ ] `openemr-cmd pit` (isolated) green · `openemr-cmd pst` clean · `openemr-cmd pr` clean
- [ ] Commit `fix(security): env-gate routing-test hook off production (S9)`
- [ ] Re-verified in main session

## Dispatch brief
```
Close AUDIT.md finding S9 in the OpenEMR fork. The /_routing_test affordance is a
test hook that must not run in production.

Files: apis/dispatch.php:29 calls FallbackRouter::handleRoutingTestIfRequested();
the method is src/BC/FallbackRouter.php:188-197 and returns HTTP 418
{"routed":...} for any URI ending /_routing_test.

Task: make the hook a no-op in a PRODUCTION environment while keeping it working
in dev/test (E2E routing tests depend on it). Gate it INSIDE the method body so
every caller is covered. Resolve "am I in production" the same way the codebase
already does (OPENEMR__ENVIRONMENT — grep interface/globals.php around
display_errors and src/.../SiteSetupListener for the existing accessor; do not
read getenv raw if a helper exists). Add an inline comment:
  // S9 (AUDIT.md): routing-test affordance is dev/test only.
Add an isolated PHPUnit test proving prod=no-op, dev=418 (extract a pure
predicate if exit/header calls block direct testing).
DoD: openemr-cmd pit + pst + pr all green in your worktree. Commit as
fix(security): env-gate routing-test hook off production (S9). Do not edit auth
or globals.php. Report what you changed and any test you could not run.
```
