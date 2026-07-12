# Background-services execution policy (AUDIT.md S6)

The background-service runner invokes a PHP function named in a
`background_services` DB row. To prevent table-write access from becoming code
execution, the runner only invokes callables on the allow-list in
`src/Services/Background/BackgroundServiceCallableAllowlist.php`.

## Rules
- **Adding a background service** requires a reviewed code change adding its
  callable to `BackgroundServiceCallableAllowlist::ALLOWED_CALLABLES`. A DB row
  alone is not sufficient — an unlisted callable is skipped and logged.
- **Who may insert/modify `background_services` rows:** TODO — ratify the
  deployment policy (founder). Restrict `background_services` write access to
  trusted admins / migrations only.

## Dev/test seam (must stay unset in production)
The runner's gate is `isPermittedToRun()`, which equals the shipped allow-list
plus any callables named in the `OPENEMR_BACKGROUND_EXTRA_ALLOWED_CALLABLES`
environment variable (comma-separated). This exists only so integration tests
can exercise the runner with a probe callable without polluting the shipped
list. It **must be unset in production** — and does not widen the S6 threat
model, since the DB-write-only attacker S6 defends against cannot set the server
process environment (that requires shell/deploy access, which already implies
code execution). `ALLOWED_CALLABLES` remains the only production gate.

## Future hardening (not yet implemented)
Consider a structural guard: require that the invoked function be defined in the
file the row's `require_once` resolved to (via `ReflectionFunction::getFileName()`),
so the allow-list need not be maintained per module. Deferred; the allow-list is
the v1 control.
