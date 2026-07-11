# SEC-S6 · Constrain `background_services` callables to an allow-list

| Field | Value |
|---|---|
| **Audit ref** | `S6` (AUDIT.md Audit 1 — Security) |
| **Severity** | Medium |
| **HIPAA nexus** | §164.308 integrity / §164.312(c) |
| **State** | todo |
| **Wave** | 1 |
| **Depends on** | — |
| **Sign-off required** | governance-doc ratification (deployment policy, not code) |
| **Suggested worktree** | `sec-s6` |
| **Files touched** | `src/Services/Background/BackgroundServiceRunner.php` + new registry class + test + governance-doc stub |
| **Upstreamable?** | maybe — discuss shape with upstream before PR |

## Problem
`src/Services/Background/BackgroundServiceRunner.php:515-530` dispatches a job by
`require_once`-ing a path (well-guarded by `SafeIncludeResolver`) and then calling
a **global function whose name comes from a `background_services` DB row**
(`$function = $service['function']; if (!function_exists($function)) …; $function(...)`).
The include path is safe, but the callable name is not constrained — write access
to that table (rogue admin, a migration, or SQL injection *elsewhere* in the app)
converts directly into arbitrary code execution. This is executable configuration.

## Acceptance criteria
- [ ] The runner refuses to invoke a `function` name that is not present in an
      explicit, code-defined allow-list/registry of known background-service
      callables.
- [ ] A row naming an unknown callable is skipped with a logged security-relevant
      warning (service name + rejected callable), not executed.
- [ ] Every legitimately-shipped background service (reminders, Direct messaging,
      MedEx, etc. — enumerate from the seed `background_services` rows) is present
      in the allow-list, so no existing job breaks.
- [ ] The `SafeIncludeResolver` path guard is preserved (do not weaken it).
- [ ] A governance-doc stub is produced stating who may insert/modify
      `background_services` rows and that schema writes to it are gated.

## Implementation sketch
Introduce a registry — a `const array` or small class mapping allowed callable
names (optionally name → expected require_once path) — and check membership before
the dynamic call at line ~530:
```php
// S6 (AUDIT.md): background-service callables are DB-driven; only invoke names on
// the explicit allow-list so table-write access can't become code execution.
if (!BackgroundServiceRegistry::isAllowed($function)) {
    $this->logger->warning('Rejected non-allow-listed background service callable', [
        'service' => $service['name'], 'callable' => $function,
    ]);
    return; // or continue to next service
}
```
Seed the allow-list from the shipped `background_services` rows (grep
`sql/database.sql` and any module registrations for `INSERT INTO background_services`).

## Test plan
- Unit test the registry/predicate: allowed name → true; unknown name → false.
- Test the runner path (with the DB call mocked or the decision extracted) that an
  unknown callable is skipped + logged and never invoked; a known one proceeds.

## Definition of done
- [ ] Acceptance criteria met · inline `// S6 (AUDIT.md):` comment present
- [ ] Governance-doc stub added (e.g. `docs/security/background-services-policy.md`)
- [ ] `openemr-cmd ut` green · `pst` clean (registry fully typed) · `pr` clean
- [ ] Commit `fix(security): allow-list background_services callables (S6)`
- [ ] Re-verified in main session; **founder ratifies the governance doc**

## Dispatch brief
```
Close AUDIT.md finding S6 in the OpenEMR fork. Background jobs invoke a global
function named in a DB row; constrain that to an allow-list so a table write can't
become code execution.

File: src/Services/Background/BackgroundServiceRunner.php:515-530 — it require_once's
a SafeIncludeResolver-validated path (keep that), then calls $function() where
$function = $service['function'] from the background_services table.

Task: add a code-defined registry/allow-list of known background-service callable
names. Before the dynamic call (~line 530), reject any name not on the list: skip
the service and log a warning with the service name + rejected callable; never
invoke it. Seed the allow-list from the background services actually shipped (grep
sql/database.sql and module bootstraps for 'INSERT INTO background_services' so no
real job breaks). Fully type the registry (PHPStan L10). Add inline comment:
  // S6 (AUDIT.md): only invoke allow-listed background-service callables.
Also add a short governance-doc stub at docs/security/background-services-policy.md
stating who may insert/modify rows (leave the policy specifics as TODO for founder
ratification).
Unit-test the registry (allowed=true / unknown=false) and the runner's skip+log
path for an unknown callable.
DoD: openemr-cmd ut + pst + pr green. Commit fix(security): allow-list
background_services callables (S6). Do NOT weaken SafeIncludeResolver. Report the
callables you allow-listed and where you sourced them.
```
