# SEC-S10 · Log a security event when audit settings change

| Field | Value |
|---|---|
| **Audit ref** | `S10` (AUDIT.md Audit 1 — Security) |
| **Severity** | Low (compliance) |
| **HIPAA nexus** | §164.312(b) audit controls |
| **State** | todo |
| **Wave** | 1 |
| **Depends on** | — |
| **Sign-off required** | no |
| **Suggested worktree** | `sec-s10` |
| **Files touched** | globals-save handler (`library/globals.inc.php` def + the save path that writes `globals` rows) |
| **Upstreamable?** | yes → strong `openemr/openemr` PR candidate |

## Problem
`library/globals.inc.php:2778` (`enable_auditlog`) and `:2785`
(`audit_events_patient-record`, plus `…_lab-results`, `…_security-administration`,
etc.) default to `'1'` (enabled — good). The gap: they are ordinary
admin-toggleable settings with no tamper-resistance. An administrator can silently
disable patient-record audit logging that §164.312(b) *requires*, and nothing
records that it happened. The control exists and defaults correctly, but its
disablement is invisible.

## Acceptance criteria
- [ ] Changing any audit-control global (`enable_auditlog`, `audit_events_*`,
      `gbl_force_log_breakglass`, and siblings) writes an `EventAuditLogger`
      security event capturing: who, when, which setting, old→new value.
- [ ] The event fires **especially** on a disable (1→0) — that is the compliance
      signal — but logging enable→disable and disable→enable both is fine and
      simpler.
- [ ] Uses the existing `security-administration` audit category, not a new sink.
- [ ] No behavior change to whether logging is on/off — this ticket only makes the
      *change to the toggle* observable.

## Implementation sketch
Find where the Globals admin form persists rows to the `globals` table (grep for
the save handler behind `interface/super/edit_globals.php` and the
`OEGlobalsBag`/`sqlStatement` write). At that write, diff the incoming audit-control
keys against their current values and, on change, call
`EventAuditLogger::instance()->newEvent(...)` under the `security-administration`
category. Annotate:
```php
// S10 (AUDIT.md): audit-control toggles are compliance-critical; record every
// change so disabling audit logging cannot happen invisibly.
```
Keep the key list in one place (a `const array` of audit-control global names) so
it is easy to review and extend.

## Test plan
- Service/unit test: given an old value map and a new value map differing on
  `enable_auditlog`, assert exactly one security event is emitted with the right
  before/after payload; given no change, assert none.
- Prefer testing an extracted pure differ (`array $old, array $new): array $changed`)
  plus a thin logging call, so the diff logic is unit-tested without a DB.

## Definition of done
- [ ] Acceptance criteria met · inline `// S10 (AUDIT.md):` comment present
- [ ] `openemr-cmd ut` (or `pit` for the differ) green · `pst` clean · `pr` clean
- [ ] Commit `feat(security): audit-log a security event on audit-setting changes (S10)`
- [ ] Re-verified in main session

## Dispatch brief
```
Close AUDIT.md finding S10 in the OpenEMR fork. Disabling audit logging must not
be invisible.

Context: library/globals.inc.php:2778 (enable_auditlog) and :2785
(audit_events_patient-record and siblings) are admin toggles defaulting to '1'.
There is no record when an admin flips them. HIPAA §164.312(b) requires audit
controls.

Task: at the point where the Globals admin form persists changes to the `globals`
table (grep for the save handler behind interface/super/edit_globals.php and the
sqlStatement/OEGlobalsBag write), detect changes to the audit-control keys and
emit an EventAuditLogger security event under the existing
'security-administration' category, capturing user, timestamp, setting name, and
old→new value. Keep the list of audit-control global names in one const array.
Do NOT change whether logging is on/off — only make the toggle change observable.
Add inline comment:
  // S10 (AUDIT.md): record every change to audit-control toggles.
Extract the diff into a pure helper and unit-test it (old map vs new map ->
changed keys); add a test asserting a security event is emitted on change and not
on no-op.
DoD: openemr-cmd ut/pit + pst + pr green. Commit feat(security): audit-log a
security event on audit-setting changes (S10). Report which save path you hooked.
```
