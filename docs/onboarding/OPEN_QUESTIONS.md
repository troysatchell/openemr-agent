# Open Questions (as-found baseline)

> A punch list of things that **could not be determined from code, tests, or
> git history alone** while mapping OpenEMR as we found it (baseline commit
> `859d6d3`, 2026-07-06). These need a human/team conversation. Each item cites
> the concrete observation that raised it, so nothing here is a guess about
> intent — the uncertainty itself is the point. Companion to
> [`CURRENT_ARCHITECTURE.md`](CURRENT_ARCHITECTURE.md) and
> [`GLOSSARY.md`](GLOSSARY.md).

Legend: **[arch]** architecture/pattern · **[biz]** business rationale ·
**[test]** test/quality · **[own]** ownership/process · **[repo]** this repo's
local state vs. upstream.

---

## Repo-local vs. upstream

1. **[repo] What is this repo relative to upstream OpenEMR, and what have "we"
   already changed?** The three most recent commits (`859d6d3` "ignore codacy",
   plus the GitLab SAST/`.gitlab-ci.yml` additions) look like local scaffolding
   layered on top of canonical `openemr/openemr`. Everything else matches
   upstream. → Which parts of this tree are ours to change vs. tracking
   upstream? Is there an upstream sync cadence we must not break?

2. **[repo] Are GitLab CI and GitHub Actions both live, or is one aspirational?**
   `.gitlab-ci.yml` exists (SAST + Secret Detection only) alongside ~55 GitHub
   Actions workflows. → Which is the source of truth for gating merges here?

---

## Architecture / pattern deviations

3. **[arch] Is the DI container (`config/*.php`, `firehed/container`) meant to
   grow, freeze, or be removed?** `config/README.md` says it is "not yet fully
   integrated," `bootstrap.php` calls itself "experimental," and the REST route
   closures `new` up controllers directly instead of resolving them. The code
   has multiple `@adunsulag` TODOs wishing for "a true service container." →
   Is there an active migration plan/owner, or is this stalled?

4. **[arch] Why do controllers/services return PSR-7 while the request is
   Symfony HttpFoundation, requiring a bridge on every response?** Intentional
   stepping-stone toward full PSR-7, or historical accident? → What's the
   intended end state?

5. **[arch] Is per-route, string-based authorization
   (`request_authorization_check($request,"patients","demo")`) the accepted
   pattern, or debt?** It duplicates authz across every route closure and
   contradicts `CLAUDE.md`'s "type the principal / encode scope in the type."
   → Should new API routes follow the existing pattern for consistency, or the
   documented ideal?

6. **[arch] Is `dispatch.php` echoing `$e->getMessage()` in the JSON error body
   a known issue?** (`apis/dispatch.php` last-resort `catch`.) It directly
   violates `CLAUDE.md`'s "never expose `$e->getMessage()` in user-facing
   output." The comment says "should never reach here." → Accepted risk, or a
   bug to fix?

7. **[arch] Is `FallbackRouter::handleRoutingTestIfRequested` in the production
   dispatch path safe/intended?** A test hook sits inline in
   `apis/dispatch.php`. → What guards it in production?

8. **[arch/biz] Why does `AuthUtils.__construct()` perform database writes?**
   It lazily creates/updates a `hidden_auth_dummy_hash` global (timing-attack
   defense) and normalizes `password_expiration_days`. This contradicts the
   project's own "no side effects during construction" guidance. → Deliberate
   (self-healing config on first login), or should it move to setup/migration?

9. **[arch] Why does the login form post to `interface/main/main_screen.php`,
   which both authenticates and renders the app frame?** Is there a reason
   there's no dedicated auth endpoint, or is this purely historical?

10. **[arch] Background services are dispatched by `(require_once path, global
    function name)` stored in a DB row and invoked dynamically.** `BackgroundServiceRunner`
    + `SafeIncludeResolver`. → Is this data-driven dispatch model expected to
    persist, and who governs what rows are allowed in `background_services`?
    (Security-sensitive: arbitrary file include + function call from DB config.)

---

## Business logic / behavior rationale

11. **[biz] What is the intended production trigger for background services?**
    The header of `execute_background_services.php` says the AJAX ("piggyback")
    path only runs services while users are logged in. `OPENEMR__NO_BACKGROUND_TASKS`
    can disable it. → In this deployment, is cron the real driver, is piggyback
    relied upon, or both? What breaks if nobody logs in over a weekend?

12. **[biz] What are the real values/units behind the background-service lease
    constants?** `MIN_LEASE_MINUTES=60`, `MAX_LEASE_MINUTES=1440`,
    `LEASE_GRACE_SECONDS=60` (`BackgroundServiceRunner`). Code references GH
    issues #11661/#11794/#11827. → Are these tuned for specific slow services,
    and which ones?

13. **[biz] The dual `pid` (int) + `uuid` identity for patients — what's the
    migration story?** The API returns both (`PatientApiTest`). → Is `pid`
    being phased out, or permanently load-bearing (foreign keys, external
    integrations)? Which one is canonical for new code?

14. **[biz] Which FHIR profile version is authoritative here?** The route file
    is named `_rest_routes_fhir_r4_us_core_3_1_0.inc.php` (US Core 3.1.0), but
    the Certification suite has `SinglePatient700APITest` and
    `SinglePatient311APITest`. → Which US Core version(s) must this deployment
    certify against?

15. **[biz] What is the `apps` list feature on the login screen for?** `login.php`
    reads a `list_options` `apps` list to offer alternate entry screens
    ("mdsupport - Add 'App' functionality"). → Is this used in practice, and by
    whom?

---

## Testing / quality (see the full testing review for detail)

16. **[test] `FhirLocationServiceIntegrationTest` is entirely skipped** — all
    three methods `markTestSkipped("...until we can ensure test isolation and
    cleanup.")` on their first line, above ~200 lines of dead test logic. →
    Is Group-export Location filtering actually verified anywhere? Who owns
    reviving this?

17. **[test] A certification test is disabled with `"Skipping until we can
    figure out why inferno validator is failing on this"`.** → Does this mask a
    real FHIR conformance gap? Is there a tracking issue?

18. **[test] `AuthUtils` — the security-critical login core — has no direct unit
    test.** Failed-login lockout, timing-attack defense, LDAP, and expiry logic
    are exercised by nothing (only one E2e happy-path/wrong-password browser
    test). → Is this an accepted gap, and is it in scope to add coverage?

19. **[test] The E2e suite is a stateful ordered chain** (filename prefixes
    `Aa`/`Bb`/… + `#[Depends]`) whose Add-traits `markTestSkipped` when an entity
    "already exists." → On a non-reset DB, setup tests silently skip and
    dependents may run against stale state — how is the E2e DB guaranteed clean
    per run, and does a "green" run actually exercise the chain?

20. **[test] Whole `src/` subsystems have zero test references:** `Reports`,
    `Reminder`, `Pharmacy`, `Pdf`, `MedicalDevice`. → Untested because low-risk,
    externally covered, or just gaps? Which matter for this deployment?

21. **[test] `library/`/`interface/` are in the coverage denominator but nearly
    uncovered** (coverage `<include>` is `.`, excludes don't list them). → Is
    the low headline coverage number understood to be dominated by legacy code
    nobody intends to unit-test, or is raising legacy coverage a goal?

---

## Ownership / process

22. **[own] Who owns the "modern rewrite frontier"?** The genuinely modern,
    well-tested islands (`Services/Background`, OAuth2/SMART, crypto, routing)
    carry `OpenCoreEMR`/`Discover and Change` copyrights and 2025–2026 dates,
    distinct from the older core authors. → Is there a team/individual driving
    the modernization, and a documented target architecture to align new work
    with?

23. **[own] What is the `CLAUDE.md` `openemr-cmd` + git-worktree workflow's
    status?** It's richly documented but is a local dev harness. → Is it
    required for contributing here, or optional tooling one team uses?

24. **[own] Node services (`ccdaservice`, `ccr`) — how are they deployed and
    versioned relative to the PHP app?** They have their own Jest tests and run
    as separate processes. → Same release train, or independent?

25. **[arch] Does the existing OAuth2/SMART server support a confidential client
    with per-physician read-only `offline_access` and scope re-derived at token
    mint?** `CURRENT_ARCHITECTURE.md` §4 lists `league/oauth2-server` + OpenID
    Connect, and the repo carries SMART/OAuth2 certification tests — but whether a
    long-lived offline grant can be issued to a *confidential* client **and** have
    its scope re-derived from the physician's current ACL on each short-lived token
    mint (so an offboarding or permission change takes effect immediately) cannot be
    determined without reading the grant/scope code. → This is the enabling
    capability for the deferred unattended-batch design (`ARCHITECTURE.md` §4/§9);
    confirm it exists before committing to that path.

---

### How to use this list
Walk it with whoever owns the codebase. Items 1–2 and 16–19 are the highest
value to resolve early: they change what is safe to touch and what "passing
tests" actually guarantees. Answers should flow back into
`CURRENT_ARCHITECTURE.md` (facts) or a new `TARGET_ARCHITECTURE.md` (intent) —
not into this file, which is meant to stay a record of the initial unknowns.
