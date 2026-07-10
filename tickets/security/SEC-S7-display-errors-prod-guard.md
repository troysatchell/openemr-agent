# SEC-S7 · Force `display_errors=0` in production

| Field | Value |
|---|---|
| **Audit ref** | `S7` (AUDIT.md Audit 1 — Security) |
| **Severity** | Medium |
| **HIPAA nexus** | §164.312(c) integrity / information disclosure |
| **State** | todo |
| **Wave** | 1 |
| **Depends on** | — |
| **Sign-off required** | no |
| **Suggested worktree** | `sec-s7` |
| **Files touched** | `interface/globals.php` |
| **Upstreamable?** | partial — the env-forced clamp is upstreamable |

> ⚠️ **Same file as SEC-S4** (`interface/globals.php`). These two must not run in
> the same wave. S7 is Wave 1; S4 rebases onto it in Wave 2.

## Problem
`interface/globals.php:813, 827, 832` each run `ini_set('display_errors', '1')`
driven by a DB global (`gv_debug_config` / error-level `case` block). If that
global is set on a production deployment, PHP errors — file paths, SQL fragments,
stack context — render into the page/response. It is admin-gated, but the option
*exists* and its effect depends on a DB row rather than the environment.

## Acceptance criteria
- [ ] When the environment is production (`OPENEMR__ENVIRONMENT` = production),
      `display_errors` is forced to `'0'` **regardless** of the DB global's value.
- [ ] In dev/test, the existing DB-driven behavior is unchanged (developers can
      still turn error display on).
- [ ] `error_reporting()` masks are left as-is — only the *display* of errors to
      the client is clamped in production (log-to-file paths untouched).

## Implementation sketch
After the `switch` block that ends at `interface/globals.php:838`, add an
unconditional production override:
```php
// S7 (AUDIT.md): never leak PHP errors to the client in production,
// regardless of the DB-driven debug global.
if (<environment is production>) {
    ini_set('display_errors', '0');
}
```
Resolve the environment via the same accessor the file already uses (grep this
file — the environment is available in the bootstrap; do not read `getenv` raw if
a typed helper exists). Placing the override *after* the switch guarantees it
wins over every `case`.

## Test plan
- This is bootstrap procedural code — prefer an isolated test on an **extracted
  pure helper** (e.g. `shouldForceDisplayErrorsOff(string $env): bool`) rather
  than trying to unit-test `globals.php` directly.
- Manual: with `OPENEMR__ENVIRONMENT=production`, set the debug global high, load
  a page that errors, confirm no error text in the response body (log still has it).

## Definition of done
- [ ] Acceptance criteria met · inline `// S7 (AUDIT.md):` comment present
- [ ] `openemr-cmd pit` green (helper test) · `pst` clean · `pr` clean
- [ ] Commit `fix(security): force display_errors off in production (S7)`
- [ ] Re-verified in main session

## Dispatch brief
```
Close AUDIT.md finding S7 in the OpenEMR fork. In production, PHP errors must
never render to the client even if the DB debug global asks for it.

File: interface/globals.php:813,827,832 call ini_set('display_errors','1') inside
a switch driven by a DB global; the switch ends at line ~838.

Task: AFTER that switch block, add an unconditional override that sets
ini_set('display_errors','0') when OPENEMR__ENVIRONMENT is production. Leave
error_reporting() masks and dev/test behavior untouched. Resolve the environment
the way this file already does (grep it; use the existing accessor, not raw
getenv). Add inline comment:
  // S7 (AUDIT.md): never leak PHP errors to the client in production.
Extract the decision into a pure helper (e.g. shouldForceDisplayErrorsOff(string
$env): bool) and add an isolated PHPUnit test for it.
CONSTRAINT: touch ONLY interface/globals.php (+ the new test). Do NOT touch the
$ignoreAuth logic in this same file — that is a separate ticket (S4). Do not edit
auth.inc.php.
DoD: openemr-cmd pit + pst + pr green. Commit fix(security): force display_errors
off in production (S7). Report the exact lines added.
```
