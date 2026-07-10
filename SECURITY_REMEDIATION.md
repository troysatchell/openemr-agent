# Security Remediation Report

Remediation of the security findings in [`AUDIT.md`](AUDIT.md) (Audit 1 — Security),
delivered on `deploy/railway` and pushed to both remotes. Every claim below maps to
a commit SHA, a test, and a verification you can re-run.

## Honest scope

This work touched findings **S4–S11**. S1/S2/S3 were closed in an earlier phase and
are **not** counted here. Of S4–S11:

- **5 production security fixes** — S6, S7, S8, S9, S10
- **2 regression / assurance nets** (tests, not vuln fixes) — S5, S11
- **1 documented enumeration** (fix deferred) — S4

Net change: **9 production files (+266/−21)**, **7 new test files (58 tests)**,
**0 regressions** across the 3926-test isolated suite, and the security diff is
**PHPStan L10 clean**.

## Remediation matrix

| Finding | CWE | Severity | Type | What shipped | Test evidence | Commit |
|---|---|---|---|---|---|---|
| **S6** Executable config in `background_services` | [CWE-94](https://cwe.mitre.org/data/definitions/94.html) code injection | **High** ⬆︎ *(audit: Med — this is an RCE-enabler: a table write becomes code exec)* | Fix | `BackgroundServiceCallableAllowlist` (14 shipped callables) + runner guard: a non-listed callable is skipped + logged, never invoked | `BackgroundServiceCallableAllowlistTest` (20) | `e21ac7f` |
| **S7** `display_errors=1` reachable in prod | [CWE-209](https://cwe.mitre.org/data/definitions/209.html) error-message exposure | Med | Fix | `ErrorDisplayPolicy` + `globals.php` clamp: force `display_errors=0` unless env is exactly `dev` | `ErrorDisplayPolicyTest` (6) | `7c581a8` |
| **S8** `AuthUtils` ctor writes to DB on every login | [CWE-284](https://cwe.mitre.org/data/definitions/284.html) improper access control | Med | Fix *(danger zone, human sign-off)* | Read-only constructor + Doctrine migration seeds the timing-defense hash; unauthenticated attempts can no longer trigger writes | `AuthUtilsConstructorReadOnlyTest` (5) + `AuthUtilsCharacterizationTest` (4) | `a924703` |
| **S10** Audit-control toggles changed silently | [CWE-778](https://cwe.mitre.org/data/definitions/778.html) insufficient logging (HIPAA §164.312(b)) | Med | Fix | Tamper-log all 10 granular `audit_events_*` toggles via the existing `auditSQLAuditTamper` path | `AuditSettingsChangeDetectorTest` (6) | `e33ec85` |
| **S9** `/_routing_test` hook answers anon on prod path | [CWE-200](https://cwe.mitre.org/data/definitions/200.html) information exposure | Low | Fix | Gate the hook to a `dev` environment; silent in production | `RoutingTestGateTest` (6) + `FrontControllerRoutingTest` (e2e) | `115e17c` |
| **S5** No default-deny route gate | [CWE-862](https://cwe.mitre.org/data/definitions/862.html) missing authorization | Med *(class)* | Net (CI gate) | Test asserts every REST route carries a recognized authz marker or is on a reviewed public allow-list — a new unguarded route fails CI | `RouteAuthorizationCoverageTest` (2) | `7d3b5fd` |
| **S11** Login core had no tests | [CWE-1120](https://cwe.mitre.org/data/definitions/1120.html) assurance gap | Systemic | Net (tests) | Characterization net on `confirmPassword` (correct/wrong/empty/unknown) in the no-mutation `otherAuth` mode | `AuthUtilsCharacterizationTest` (4) | `508304e` |
| **S4** Auth hinges on `$ignoreAuth` ambient global | [CWE-1188](https://cwe.mitre.org/data/definitions/1188.html) insecure default | **High** *(finding)* — **partial** | Docs | Enumerated + classified all 44 opt-out sites; flagged REVIEW items (webhook signatures, portal reset-token gates). **Runtime fix deferred.** | grep-reproducible allow-list | `ef1dcac` |

Severity column is *our* deployment-risk rating; only **S6** is re-rated above the
audit (from Med to High), with the stated RCE-enabler justification. Everything else
keeps the audit's rating.

## Method & rigor

- **Test-first (frozen-test TDD):** for every fix, a failing acceptance test was
  authored and committed *before* the implementation; the frozen test was then
  verified byte-unchanged after implementation.
- **Danger-zone discipline:** the only auth-code change (S8) was made **after
  explicit human sign-off** on the approach, under a source-scan test proving the
  constructor performs no DB writes, with the login timing-defense verified intact.
- **Static analysis:** the security diff is **PHPStan level 10 clean** — verified on
  all 9 changed production files. The S8 refactor *reduced* three anti-pattern
  baseline counts (empty/privStatement/error_log), which were decremented to match;
  the S10 loop was written type-narrowed (`is_scalar`) so it adds zero `mixed` errors
  and drops a deprecated `sqlQuery` for `QueryUtils::querySingleRow`.
- **No baseline growth, no fixture-gaming, no inflated severities.**

## Verification (re-runnable)

```bash
# All new security tests (48 isolated + 4 DB-backed + S8 constructor scan)
vendor/bin/phpunit -c phpunit-isolated.xml \
  tests/Tests/Isolated/BC/RoutingTestGateTest.php \
  tests/Tests/Isolated/Common/Environment/ErrorDisplayPolicyTest.php \
  tests/Tests/Isolated/Common/Logging/AuditSettingsChangeDetectorTest.php \
  tests/Tests/Isolated/Services/Background/BackgroundServiceCallableAllowlistTest.php \
  tests/Tests/Isolated/RestControllers/Config/RouteAuthorizationCoverageTest.php \
  tests/Tests/Isolated/Common/Auth/AuthUtilsConstructorReadOnlyTest.php
vendor/bin/phpunit tests/Tests/Common/Auth/AuthUtilsCharacterizationTest.php

# Full isolated suite (regression): OK — 3926 tests, 0 failures
vendor/bin/phpunit -c phpunit-isolated.xml

# PHPStan L10 on the security diff: clean
composer phpstan   # (or targeted on the 9 changed files)
```

**Live behavioral check** of the actual decision functions (executed in-container):

```
S9  routing-test gate  | prod(isDev=false)=false  dev(isDev=true)=true
S6  bg callable allow  | isAllowed('system')=false  isAllowed('phimail_check')=true
S7  display_errors off | production=true  dev=false
S10 audit-toggle change| disabling audit_events_patient-record -> [{"key":"audit_events_patient-record","new":"0"}]
```

## Open items (honest)

1. **S4 phase-2** — a log-only runtime `$ignoreAuth` assertion — deferred pending
   sign-off. The docs-only phase-1 is done.
2. **S4 REVIEW items** — human verification that the payment/FaxSMS webhooks
   validate signatures, portal reset/verify flows are token-gated, and the
   utility endpoints are intentionally anonymous (see
   `docs/security/ignoreauth-allowlist.md`).
3. **S11 deferred seams** — the stateful lockout/timing/LDAP branches — now
   testable since S8 exists.
4. **`Kernel::isDev()` hardening** — it reads `$_ENV` only, which is empty under
   this runtime's `variables_order=GPCS`; every env-gate here uses a `getenv()`
   fallback. A central fix would let those collapse into one place.

Full backlog + per-finding tickets: [`tickets/security/`](tickets/security/README.md).
