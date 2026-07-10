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

## Future hardening (not yet implemented)
Consider a structural guard: require that the invoked function be defined in the
file the row's `require_once` resolved to (via `ReflectionFunction::getFileName()`),
so the allow-list need not be maintained per module. Deferred; the allow-list is
the v1 control.
