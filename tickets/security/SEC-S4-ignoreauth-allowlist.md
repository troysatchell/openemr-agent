# SEC-S4 · Enumerate & document the `$ignoreAuth` opt-out allow-list

| Field | Value |
|---|---|
| **Audit ref** | `S4` (AUDIT.md Audit 1 — Security) |
| **Severity** | High |
| **HIPAA nexus** | §164.312(a) access control |
| **State** | blocked |
| **Wave** | 2 |
| **Depends on** | **SEC-S7** merged (same file: `interface/globals.php`) |
| **Sign-off required** | **YES** — danger zone (`$ignoreAuth`/`globals.php`); `FOUNDER_ACTIONS.md` §2 |
| **Suggested worktree** | `sec-s4` |
| **Files touched** | new `docs/security/ignoreauth-allowlist.md`; (phase 2, optional) a runtime assertion in `interface/globals.php` |
| **Upstreamable?** | the allow-list doc is fork-specific; a runtime guard could be upstreamed |

> 🛑 **Danger zone.** `CLAUDE.md` names `interface/globals.php` + anything reading
> `$ignoreAuth` as load-bearing and untested. **Do not** rewrite the auth-enforcement
> model in this ticket. Scope is deliberately conservative: *make the opt-outs
> visible and guarded*, not *redesign the gate*.

## Problem
`interface/globals.php:170-176` default `$ignoreAuth`/`$ignoreAuth_onsite_portal`
to `false`, and `:725` includes `auth.inc.php` only `if (!$ignoreAuth)`. But
`:721-722` **auto-sets `$ignoreAuth = true`** when `portal_onsite_two_enable` is
on, and every legacy page can set the global before including `globals.php`. There
is no central registry of which entry points opt out, no type safety, and the
value is a mutable global. A page that forgets the pattern, or an unexpected code
path that sets it true, silently serves **without authentication** — the
largest-blast-radius pattern in the legacy tier.

## Acceptance criteria (this ticket — conservative scope)
- [ ] A committed allow-list doc enumerating **every** file/entry point that sets
      `$ignoreAuth = true` or `$ignoreAuth_onsite_portal = true`, each with a
      one-line justification (why it is legitimately unauthenticated).
- [ ] The enumeration is produced by an actual repo grep (method recorded in the
      doc), not guessed — so it can be re-run to detect drift.
- [ ] Any opt-out that is **not** obviously justified is flagged in the doc as
      "REVIEW" for founder decision (do not remove it silently).
- [ ] *(Optional, founder-approved phase 2 only)* a low-risk runtime assertion
      that logs a security event if `$ignoreAuth` is true for a request URI **not**
      on the allow-list — log-and-continue first, never fail-closed without sign-off.

## Implementation sketch
Phase 1 (this ticket, no code-path change):
```bash
grep -rn 'ignoreAuth\s*=\s*true\|ignoreAuth_onsite_portal\s*=\s*true' \
  --include='*.php' interface library apis modules
```
Write `docs/security/ignoreauth-allowlist.md`: table of `file:line` → purpose →
verdict (`allow` / `REVIEW`). Cite `S4 (AUDIT.md)`.

Phase 2 (only if founder signs off, and only after S7 has merged this file):
add near `interface/globals.php:725` a comparison of the request URI against the
allow-list; on a miss with `$ignoreAuth === true`, emit an `EventAuditLogger`
security event. **Log-only** — do not block. Guard with `// S4 (AUDIT.md):`.

## Test plan
- Phase 1 is documentation — verify the grep is reproducible and complete.
- Phase 2 (if taken): isolated test of the "is this URI on the allow-list?"
  predicate. Do **not** attempt to unit-test `globals.php` bootstrap directly.

## Definition of done
- [ ] **Founder sign-off recorded** before any code edit
- [ ] Allow-list doc committed, grep method reproducible
- [ ] SEC-S7 already merged (rebased onto it — no `globals.php` conflict)
- [ ] If phase 2 taken: `pit` green, `pst`/`pr` clean, inline `// S4 (AUDIT.md):`
- [ ] Commit `docs(security): enumerate $ignoreAuth opt-out allow-list (S4)`
      (+ `fix(security): log unlisted $ignoreAuth opt-outs (S4)` if phase 2)
- [ ] Re-verified in main session

## Dispatch brief
```
Work AUDIT.md finding S4 in the OpenEMR fork — CONSERVATIVE SCOPE ONLY. This is a
DANGER ZONE (interface/globals.php + $ignoreAuth). Do NOT redesign the auth gate.
Founder sign-off is required before ANY code edit, and SEC-S7 must already be
merged (it edits the same file).

Context: interface/globals.php:170-176 default $ignoreAuth false; :721-722 auto-set
it true when portal_onsite_two_enable is on; :725 includes auth.inc.php only if
!$ignoreAuth. Any page setting $ignoreAuth true serves unauthenticated. No registry
of opt-outs exists.

Phase 1 (do this now): grep the repo for every site that sets $ignoreAuth=true or
$ignoreAuth_onsite_portal=true (grep -rn over interface library apis modules) and
write docs/security/ignoreauth-allowlist.md — a table of file:line -> purpose ->
verdict (allow / REVIEW). Record the exact grep so it's re-runnable. Flag anything
not obviously justified as REVIEW; remove nothing. Cite S4 (AUDIT.md). This phase
is DOCS ONLY — zero code changes.

Phase 2 (ONLY if the founder explicitly approves it in this dispatch): add a
log-only runtime assertion near globals.php:725 that emits an EventAuditLogger
security event when $ignoreAuth is true for a URI not on the allow-list. Log and
continue — never fail-closed. Guard with // S4 (AUDIT.md):.

DoD: founder sign-off recorded; doc committed; if phase 2, pit/pst/pr green.
Commit docs(security): enumerate $ignoreAuth opt-out allow-list (S4). Report the
full opt-out list and any REVIEW items you found.
```
