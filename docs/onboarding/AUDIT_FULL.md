# Audit Log (as-found baseline)

> A running record of focused audits of OpenEMR **as we found it**
> (baseline commit `859d6d3`, 2026-07-06). Each finding is grounded in a
> concrete `file:line` observation, not intent. Companion to
> [`CURRENT_ARCHITECTURE.md`](CURRENT_ARCHITECTURE.md),
> [`GLOSSARY.md`](GLOSSARY.md), and [`OPEN_QUESTIONS.md`](OPEN_QUESTIONS.md).
>
> **Scope note.** This is a design/architecture audit derived by reading entry
> points, wiring, and the security-critical `src/Common` tier — not a
> penetration test and not an exhaustive line-by-line SAST sweep of the ~20-year
> legacy surface (`library/`, `interface/`). Findings are things a reader can
> verify in the code today. Severity is *our* rating of deployment risk, to be
> confirmed with whoever owns the deployment.

## The audit series

| # | Audit | Status |
|---|---|---|
| 1 | **Security** (HIPAA gaps, data exposure, authn/authz) | ✅ Complete |
| 2 | **Performance** (bottlenecks, data structure, latency constraints) | ✅ Complete |
| 3 | **Architecture** (organization, data location, layer interaction, integration paths) | ✅ Complete |
| 4 | **Data quality** (completeness, consistency, duplicates, staleness) | ✅ Complete |
| 5 | **Compliance & regulatory** (audit logging, retention, breach notification, BAAs, PHI→LLM) | ✅ Complete — this pass |

---

# Audit 1 — Security

**Focus:** HIPAA-related gaps, data-exposure vectors, authentication and
authorization risk.

**Headline:** The modern auth *core* is better than its reputation — the login
path uses parameterized queries, IP + per-user failed-login lockout, a
timing-attack defense, `BINARY` username matching, HMAC CSRF tokens, and
`sodium_memzero` of plaintext passwords (see [Positives](#positive-controls-observed)).
The risk concentrates instead in **(a)** how the app leaks internal detail on
the error path, **(b)** session-cookie hardening that is *off by default* and
opt-in per entry point, and **(c)** an authentication/authorization model that
depends on ambient globals and hand-repeated per-route checks — i.e. failures of
*omission* rather than a broken primitive.

## Severity summary

| # | Finding | Severity | HIPAA nexus |
|---|---|---|---|
| S1 | API error body leaks `$e->getMessage()` to the client | **High** | §164.312(a)(1), (c) |
| S2 | Core session cookie is **not** `HttpOnly` | **High** | §164.312(a)(2)(i), (e) |
| S3 | `cookie_secure` defaults to `false` (core/portal sessions) | **High** | §164.312(e)(1) transmission security |
| S4 | Auth enforcement hinges on the `$ignoreAuth` ambient global | **High** | §164.312(a) access control |
| S5 | Per-route string authz is omission-prone (no central gate) | **Medium** | §164.312(a)(1) |
| S6 | Executable config in `background_services` (path + function from DB) | **Medium** | §164.308 integrity / §164.312(c) |
| S7 | Configurable `display_errors=1` can surface PHP errors to users | **Medium** | §164.312(c) |
| S8 | `AuthUtils::__construct()` performs DB writes on every login attempt | **Low–Med** | availability / least-privilege |
| S9 | Unauthenticated routing test hook on the production dispatch path | **Low** | information disclosure |
| S10 | Audit logging is fully admin-disableable, not tamper-resistant | **Low (compliance)** | §164.312(b) audit controls |
| S11 | Security-critical login core has no direct unit tests | **Systemic** | assurance gap |

---

## Findings (detail)

### S1 — API error responses leak `$e->getMessage()` — **High**
`apis/dispatch.php:41-44` — the last-resort `catch (\Throwable $e)` returns:
```php
die(json_encode([
    'error' => 'An error occurred while processing the request.',
    'message' => $e->getMessage(),   // <-- internal detail to the client
]));
```
The message can carry SQL fragments, file paths, class names, or driver text to
an unauthenticated REST/FHIR caller. This directly violates `CLAUDE.md`
("Never expose `$e->getMessage()` in user-facing output"). The comment says
"should never reach here," but it is reachable — any uncaught throwable in the
kernel bootstrap or a listener lands here.
Cross-ref: `OPEN_QUESTIONS.md` #6.
**Recommendation:** Log the exception (already done via `error_log`) and return
only the generic `error` string; drop the `message` key. Confirm no other
handler re-adds it.

### S2 — Core session cookie is not `HttpOnly` — **High**
`src/Common/Session/SessionConfigurationBuilder.php:88` — `forCore()` explicitly
calls `->setCookieHttpOnly(false)`, overriding the class default of `true`
(line 27). The **primary authenticated web-session cookie** is therefore
readable by JavaScript, so any stored/reflected XSS in the legacy UI escalates
to full session theft and PHI access. (The `forApi`/`forOAuth`/`forPortal`
presets keep `httponly` at the `true` default — only the main app session is
weakened.)
**Recommendation:** Determine why core needs JS-readable cookie access (likely a
specific legacy script reading the session id) and remove that dependency so
`httponly` can be `true`. If truly unavoidable, isolate the readable value into
a separate non-session cookie.

### S3 — `cookie_secure` defaults to `false` — **High**
`SessionConfigurationBuilder.php:26` sets `'cookie_secure' => false` as the base
default; only `forOAuth()` and `forApi()` flip it to `true` (lines 100, 110).
The **core** and **portal** session cookies inherit `false`, so on any
deployment that terminates TLS imperfectly (mixed content, an internal HTTP hop,
a misconfigured proxy) the session cookie can transit in plaintext. HIPAA
§164.312(e)(1) requires transmission security for ePHI.
**Recommendation:** Default `cookie_secure` to `true` and provide an explicit
opt-out for local/dev HTTP, rather than the reverse. At minimum force it `true`
for `forPortal()` (patient-facing).

### S4 — Auth enforcement hinges on `$ignoreAuth` — **High**
`interface/globals.php:170-176, 721-728` — whether `auth.inc.php` (the
authentication gate) is included is decided by the ambient globals `$ignoreAuth`
/ `$ignoreAuth_onsite_portal`, which each legacy page sets *before* including
`globals.php`:
```php
if (!$ignoreAuth) {
    require_once("$srcdir/auth.inc.php");
}
```
There is no central registry of which entry points opt out, no type safety, and
the value is a mutable global. A new page that forgets the pattern, or any code
path that sets `$ignoreAuth = true` (line 722 does so automatically when
`portal_onsite_two_enable` is on), silently serves without authentication. This
is the single largest-blast-radius pattern in the legacy tier
(`START_HERE.md`, `CURRENT_ARCHITECTURE.md` §5).
**Recommendation:** Treat every `$ignoreAuth = true` site as a security-review
checkpoint; enumerate them (grep) and document the allow-list. Longer term, move
auth to a front-controller/middleware that is on by default and opts *out*
explicitly, rather than opts *in* to enforcement.

### S5 — Per-route string authz is omission-prone — **Medium**
`src/RestControllers/Config/RestConfig.php:180-194` — `request_authorization_check`
*does* fail closed when invoked (throws `AccessDeniedHttpException` on a failed
`AclMain::aclCheckCore`). The weakness is structural: the check is **called by
hand inside each route closure** with bare-string scopes
(e.g. `request_authorization_check($request,"patients","demo")`), so a new or
edited route that omits the call, or mistypes the scope pair, exposes data with
no compile-time, DI, or test guard to catch it. There is no default-deny gate
that every route passes through.
Cross-ref: `OPEN_QUESTIONS.md` #5.
**Recommendation:** Add a default-deny check in the dispatch layer (a route is
denied unless it declares a scope), or a test that asserts every registered
route in `RestConfig::$ROUTE_MAP` invokes an authorization check. Until then,
authz on new routes is a manual review item.

### S6 — Executable config in `background_services` — **Medium**
`CURRENT_ARCHITECTURE.md` §6 / `OPEN_QUESTIONS.md` #10 — background jobs are
dispatched by a `(require_once path, global function name)` pair stored in a
`background_services` **DB row** and invoked dynamically by
`BackgroundServiceRunner`. The include path is well-guarded:
`src/Common/Filesystem/SafeIncludeResolver.php` rejects `..`/`.`, NUL bytes,
`://` schemes, and symlink escapes, and confirms the resolved path stays under
the base dir (verified — this is a genuinely careful guard). **But** the
function name is still called dynamically by name, so write access to that table
(a rogue admin, a migration, or SQL injection *elsewhere* in the app) converts
directly into code execution. This is executable configuration.
**Recommendation:** Constrain the callable to an allow-list/registry of known
service classes rather than arbitrary global function names from a row; document
who is permitted to insert `background_services` rows and gate schema writes.

### S7 — Configurable `display_errors=1` — **Medium**
`interface/globals.php:800-838` — a global setting can set `ini_set('display_errors','1')`
at several levels. If enabled in production, PHP errors (file paths, SQL,
stack context) render into the page/response. It is admin-gated, but the *option
exists* and the default depends on the `globals` row rather than the
environment.
**Recommendation:** Force `display_errors=0` whenever `OPENEMR__ENVIRONMENT` is
production regardless of the DB setting; keep the toggle only for dev.

### S8 — `AuthUtils::__construct()` writes to the DB — **Low–Medium**
`src/Common/Auth/AuthUtils.php:95-120` — constructing `AuthUtils` (which happens
on **every** login attempt, authenticated or not) may `INSERT`/`UPDATE` the
`globals` table to lazily create/rehash `hidden_auth_dummy_hash` and normalize
`password_expiration_days`. Beyond violating "no side effects in constructors,"
this means an unauthenticated attacker's login attempts can trigger writes, and
it prevents running the auth path against a read-replica or least-privilege DB
user. Steady-state it's a single read (line 106), so impact is bounded.
Cross-ref: `OPEN_QUESTIONS.md` #8.
**Recommendation:** Move the dummy-hash/expiry bootstrap to setup/migration; make
the constructor read-only.

### S9 — Unauthenticated routing test hook in production — **Low**
`apis/dispatch.php:28` calls `FallbackRouter::handleRoutingTestIfRequested(...)`
unconditionally; `src/BC/FallbackRouter.php:188-197` returns HTTP `418` with
`{"routed":"apis"}` for any URI ending `/_routing_test`. It exposes no PHI, but
it is a test affordance sitting on the production path and confirms the app /
routing layer to an anonymous prober.
Cross-ref: `OPEN_QUESTIONS.md` #7.
**Recommendation:** Guard behind a non-production environment check.

### S10 — Audit logging is fully admin-disableable — **Low (compliance)**
`library/globals.inc.php:2778-2845` — `enable_auditlog` and the granular
`audit_events_patient-record`, `…_lab-results`, `…_security-administration`,
etc. all **default to `'1'`** (enabled) — good. The compliance gap is that they
are ordinary admin-toggleable settings with no tamper-resistance or separation
of duties: an administrator can silently disable patient-record audit logging,
which HIPAA §164.312(b) requires. The control exists and defaults correctly, but
is not enforced.
**Recommendation:** Log a security event whenever audit settings are changed, and
document (policy) that disabling them is prohibited. Consider preventing disable
in production builds.

### S11 — Login core has no direct unit tests — **Systemic**
`OPEN_QUESTIONS.md` #18 — `AuthUtils` / `library/auth.inc.php` (~1,400 lines:
failed-login lockout, timing defense, LDAP, password expiry, session setup) have
**no direct unit tests**; only one E2e happy-path/wrong-password browser test
exercises them. Not a vulnerability itself, but it means any regression in the
most security-critical code in the app ships unguarded. This amplifies the risk
of every finding above.
**Recommendation:** Prioritize characterization/unit tests around
`confirmUserPassword` lockout branches and the timing-attack path before
refactoring any of it.

---

## Positive controls observed

Recorded so the audit is balanced — these are verified in code and should *not*
be weakened by any remediation of the above:

- **Parameterized queries throughout the auth path** — `AuthUtils` uses
  `privQuery`/`privStatement` with `?` placeholders (e.g. lines 99, 118, 165,
  330, 381); no string interpolation of credentials into SQL was found in this tier.
- **CSRF** — `src/Common/Csrf/CsrfUtils.php`: HMAC-SHA256 tokens derived from a
  per-session 32-byte `random_bytes` secret that never leaves the server, compared
  with `hash_equals` (constant-time). Separate `'api'` vs `'default'` subjects.
- **Password handling** — verification via `AuthHash`, `passwordNeedsRehash`
  rehash-on-login, and plaintext zeroed with `sodium_memzero`
  (`auth.inc.php:68`, `AuthUtils` `clearFromMemory`).
- **Brute-force defense** — IP-level and per-user failed-login counters with
  lockout, optional email notification, and manual IP block
  (`AuthUtils.php:294-408`); plus a timing-attack dummy hash so unknown-user and
  known-user paths take similar time.
- **Case-exact auth** — `BINARY` comparison on `username` / `portal_login_username`
  prevents case-folding authentication bypass (`AuthUtils.php:329, 380, 164`).
- **Path-traversal guard** — `SafeIncludeResolver` rejects traversal, NUL,
  `://` schemes, and symlink escape, and re-verifies containment after `realpath`.
- **Session hardening primitives present** — `SessionConfigurationBuilder` sets
  `use_strict_mode`, `use_only_cookies`, `SameSite` (Strict for core, None only
  where SMART-on-FHIR requires it), and larger session-id entropy on PHP < 8.4.

---

## Suggested remediation order

1. **S1** (drop `message` from the API error body) — one-line, high value, no risk.
2. **S3 / S2** (default `cookie_secure` true; restore `httponly` on core) — config
   changes; verify no legacy JS depends on the current behavior first.
3. **S9 / S7** (env-gate the test hook and `display_errors`) — small, prod-only guards.
4. **S5 / S4** (default-deny authz gate; enumerate `$ignoreAuth` opt-outs) — larger,
   design-level; pair with **S11** (add auth tests) so refactors are safe.
5. **S6 / S8 / S10** — hardening + compliance; coordinate with deployment owner.

*Findings that overlap `OPEN_QUESTIONS.md` are cross-referenced; resolving those
questions with the team may reclassify a few of these (e.g. S6, S8) from "risk"
to "accepted design."*

---

# Audit 2 — Performance

**Focus:** when the system is slow, where the bottlenecks are, how the data is
structured, and what constraints will affect latency.

**Method caveat (read this).** This is **static/structural** analysis — derived
by reading the hot paths (bootstrap, data-access, session, audit, schema),
*not* by profiling a running instance under load. So findings identify *where
latency is structurally paid* and *where to instrument*, not measured
millisecond costs. Every finding names the code so it can be profiled to
confirm. Treat the "where to measure first" list as the deliverable's action.

**Headline:** The **modern `src/` tier is performance-competent** — the FHIR/API
search path pushes `LIMIT` to SQL with a hard cap, the schema is uniformly
InnoDB with good composite indexes on the hot clinical tables, and the core web
session is read-only-by-default so concurrent requests from one user don't
serialize on a session lock. The cost concentrates in the **legacy request
lifecycle**: every legacy page pays a fixed, uncached bootstrap tax (full
`globals` table load + an O(n×m) settings merge), and the frameset UI multiplies
that tax by firing many such requests per perceived page. Data-structure
constraints (polymorphic wide tables, dual pid/uuid identity, per-site DBs) shape
the rest.

## Severity summary

| # | Finding | Severity | Scope |
|---|---|---|---|
| P1 | Every legacy request loads the whole `globals` table + O(n×m) merge, uncached | **High** | All legacy pages |
| P2 | Frameset/iframe UI multiplies the P1 bootstrap tax per perceived page | **High** | Legacy web UI |
| P3 | Fresh DB connection per request (pooling off by default) | **Medium** | All requests |
| P4 | `BaseService::search()` is unbounded (no LIMIT) on the direct/legacy path | **Medium** | Non-FHIR service calls |
| P5 | Synchronous audit INSERT on the request hot path (+ per-event menu query on portal) | **Medium** | Audited actions |
| P6 | `lists` table has no composite `(pid, type)` index | **Low–Med** | Per-patient clinical reads |
| P7 | Background jobs coupled to user activity ("Ajax piggyback") | **Medium** | Throughput/latency isolation |

---

## Findings (detail)

### P1 — Per-request full `globals` load + O(n×m) merge — **High**
`interface/globals.php:450-528` — every legacy page request runs:
```php
$glres = sqlStatementNoLog("SELECT gl_name, gl_index, gl_value FROM globals ORDER BY gl_name, gl_index");
while ($glrow = sqlFetchArray($glres)) {          // ~500+ setting rows
    if (!empty($gl_user)) {
        foreach ($gl_user as $setting) { ... }     // nested loop per row
    }
    ...
}
```
The entire settings table is read row-by-row, and for **each** row the code
linearly scans the per-user override array (`$gl_user`), i.e. O(globals ×
user_overrides) pure-PHP work. No caching layer wraps this — a grep for
Redis/APCu/memoization around the globals load found **nothing**
(`src/Common/Session/*`, `library/`), even though the app has caching primitives
elsewhere (`CacheUtils`, `TranslationCache`). This is fixed overhead paid before
any page-specific logic on every legacy request.
**Recommendation:** Cache the assembled globals array (per site, per user)
in APCu/Redis with invalidation on `globals`/`user_settings` write; or at least
replace the nested scan with a keyed lookup (`$gl_user` as an associative array).
This is the highest-leverage single optimization for legacy TTFB.

### P2 — Frameset UI multiplies the bootstrap tax — **High**
The legacy UI (`interface/main/tabs/main.php` and the tab iframes) is a
multi-iframe frameset; **each iframe/tab is its own PHP entry point** that
re-includes `globals.php` (P1) and re-runs `auth.inc.php`. One perceived "page"
is therefore N backend requests, each paying the full ~860-line `globals.php`
bootstrap + session read + globals load + config merge. The per-request tax from
P1 is multiplied by the frameset fan-out. This is structural to the legacy UI,
not a single hotspot.
**Recommendation:** Measure requests-per-perceived-page first (P1's cache makes
each cheaper regardless). Longer term this is the front-controller migration the
team is already circling (`OPEN_QUESTIONS.md` #3–4).

### P3 — Fresh DB connection per request by default — **Medium**
`src/BC/DatabaseConnectionFactory.php:156-173` —
`detectConnectionPersistenceFromGlobalState()` returns `false` unless
`enable_database_connection_pooling` is explicitly set (globals or session).
Default = a new MySQL connection (handshake + auth) established on every request.
Cheap over a local unix socket; a real cost over TCP/TLS to a remote/managed DB
(RDS, Cloud SQL). It's a tuning lever that ships off.
**Recommendation:** Document the trade-off; enable persistent connections
(`p:host`, line 113) for remote-DB deployments and size the pool against
`max_connections`.

### P4 — Unbounded `BaseService::search()` — **Medium**
`src/Services/BaseService.php:487-521` builds `SELECT <fields> FROM <table>
WHERE <fragment>` with **no LIMIT** and materializes every matching row into
result objects in a PHP loop (511-514). The **modern FHIR/API path is fine** — it
routes through `SearchQueryConfig` → `SearchConfigClauseBuilder.php:58`, which
appends `LIMIT <offset>, <count+1>` capped at `QueryPagination::MAX_LIMIT = 200`.
But any caller using the raw `search()` (or the many legacy report queries) has
no upper bound: a broad predicate on `patient_data` / `lists` / `forms` loads the
whole result set into memory. Latency and memory scale with data volume with no
ceiling.
**Recommendation:** Give `search()` a default cap (with explicit opt-out for
callers that truly need all rows), and audit non-FHIR callers for missing limits.

### P5 — Synchronous audit write on the hot path — **Medium**
`src/Common/Logging/EventAuditLogger.php` — every audited action writes inline:
`newEvent()` → `recordLogItem()` → `sqlInsertClean_audit()` INSERT into
`log`/`extended_log` (lines 570-625). InnoDB + `KEY patient_id` (good), but it's
per-action write amplification on the request path. The **patient-portal** path
is worse: `newEvent()` runs `SELECT * FROM patient_portal_menu` on **every**
portal audit event (lines 205-211) just to map a menu name → id — a per-event
query that should be cached or joined.
**Important:** audit logging is a HIPAA requirement (see Security S10) — the fix
is to make it **cheaper/asynchronous**, not to disable it. **Recommendation:**
Cache the portal-menu lookup; consider a batched/async audit sink (queue,
buffered writes) for high-throughput clinical actions.

### P6 — `lists` lacks a composite `(pid, type)` index — **Low–Medium**
`sql/database.sql` `lists` table — the polymorphic problems/allergies/medications
store, one of the highest-row-count clinical tables — has separate single-column
`KEY pid` and `KEY type`, but **no composite**. The dominant query is
`WHERE pid = ? AND type = 'medication'` (per-patient, per-list-type); MySQL can
use only one of the two indexes and filters the rest. A composite `(pid, type)`
turns it into a direct range lookup. (For contrast, `patient_data`, `forms`, and
`form_encounter` *do* have the right composites — `idx_patient_name`,
`pid_encounter`, `pid_encounter`/`encounter_date` — so this is a specific gap,
not a systemic one.)
**Recommendation:** Add `KEY (pid, type)` to `lists` via a Doctrine migration;
measure against the slow-query log for per-patient chart loads.

### P7 — Background jobs coupled to user activity — **Medium**
`CURRENT_ARCHITECTURE.md` §6 + `OPEN_QUESTIONS.md` #11 — unless cron is
configured, background services (reminders, Direct messaging, MedEx) run on the
back of AJAX polls from logged-in users ("piggyback"). Performance consequences:
(a) the job executes **inside** a user-facing request, adding its latency to
whichever poll triggers it; (b) if nobody logs in (nights/weekends), due work
queues and then **stampedes** on the next login; (c) the poll itself does DB work
and resets session expiration per logged-in client on an interval. This couples
background throughput to interactive load — the opposite of isolation.
**Recommendation:** Confirm cron is the production driver (an open question for
the team); if piggyback is relied upon, bound per-poll work and monitor the
post-idle stampede.

---

## Positive controls observed

- **All-InnoDB schema** — 255 `CREATE TABLE`s, all InnoDB (`grep ENGINE`): row-level
  locking and transactions, **no MyISAM table-lock serialization** (a classic
  legacy-PHP-app bottleneck this app has already escaped).
- **Modern FHIR/API search is properly paginated** — `LIMIT offset, count+1`,
  `MAX_LIMIT = 200`, `hasMore` detection (`SearchConfigClauseBuilder.php:58`,
  `QueryPagination.php`). No offset-scan blow-up, bounded memory per response.
- **Core web session is read-only by default** — `forCore(readOnly = true)`
  (`SessionConfigurationBuilder.php:83`) uses PHP `read_and_close`, so most page
  loads release the session lock immediately and concurrent requests from one
  user are **not serialized** on it (`SessionUtil.php:44-45`); writes reopen via
  `WriteThroughSession`.
- **Hot clinical tables are well-indexed** — `patient_data(idx_patient_name,
  idx_patient_dob, uuid, pid)`, `forms(pid_encounter)`,
  `form_encounter(pid_encounter, encounter_date, uuid)`.
- **Caching primitives exist and are used** — `CryptoGen`, `TranslationCache`,
  `CacheUtils`, `FormLocator`; Redis available for sessions/cache. (Just not yet
  applied to the globals load — P1.)

---

## Data-structure constraints that shape latency

- **Dual identity (pid int + uuid binary(16))** — every API row converts binary
  uuid → string in PHP (`BaseService::createResultRecordFromDatabaseResult`,
  lines 528-542): O(rows × uuidFields) CPU per response. Bounded by the 200-row
  page cap at the API tier, so acceptable there; unbounded via P4 elsewhere.
- **Polymorphic wide tables** — `lists.type`, `list_options.list_id`, `globals`,
  `user_settings`, `patient_settings` are shared key/typed-row tables filtered by
  a discriminator column. Index quality **on the discriminator** dominates read
  cost (this is exactly why P6 matters), and these tables are read on nearly
  every chart/config load.
- **Multi-site (`site_id`)** — per-site DB config, resolved early in every
  bootstrap; no cross-site connection sharing. Not a bottleneck itself but caps
  pooling/caching to per-site scope.
- **Two HTTP object models on the API path** — request is HttpFoundation,
  controllers return PSR-7, bridged back per response (`CURRENT_ARCHITECTURE.md`
  §6). Object allocation/copy is a small fixed per-request CPU tax on every API
  call.

---

## Where to measure first (highest leverage)

1. **Time inside `globals.php`** per request (P1) — instrument the globals load +
   merge; hypothesis: it dominates legacy TTFB. Confirm before optimizing.
2. **Requests-per-perceived-page** (P2) — count backend requests for one user
   action in the frameset UI; P1's cost is multiplied by this.
3. **Slow-query log** filtered for `lists` scans (P6) and unbounded `search()`
   callers (P4).
4. **Audit-write latency** under simulated clinical throughput (P5), including the
   portal per-event menu query.
5. **DB connect time** with pooling off vs on for the target DB topology (P3).

*This audit is code-derived, not profiled. Its purpose is to point the profiler
at the right places — validate P1/P2 with real timings before investing in the
globals-cache work, which is the biggest single win if the hypothesis holds.*

---

# Audit 3 — Architecture

**Focus:** how the system is organized, where the data lives, how the layers
interact, and the integration paths for adding new capabilities.

**Relationship to the other docs.** `CURRENT_ARCHITECTURE.md` §2–6 already
describes the *layers* and the golden-path *flows* — this audit does **not**
repeat that table. Instead it answers the two questions a doc reader actually
acts on: **"where does the data live?"** and **"if I want to add a capability,
where do I plug in?"** The extension seams below are the deliverable; each is
cited to real code so it can be followed, not guessed.

---

## 1. Organizing principle (the one thing to internalize)

OpenEMR is organized along **three orthogonal axes**, and every capability lives
at the intersection of them:

1. **Era** — modern `src/` (`OpenEMR\`, PSR-4, services) vs. legacy
   `library/`+`interface/` (procedural, globals). *(`CURRENT_ARCHITECTURE.md` §5,
   `START_HERE.md`.)*
2. **Entry door** — legacy pages bootstrap via `interface/globals.php`; the API
   bootstraps via `bootstrap.php` + `SiteSetupListener`. Two parallel init paths.
3. **Extension mechanism** — the codebase is extended **without editing core**
   through **(a)** the Symfony **event system** (80 event classes) and **(b)** the
   **module system** (`interface/modules/custom_modules/`). This is the axis this
   audit adds, because it's the one the architecture doc under-documents and the
   one most relevant to "adding new capabilities."

If you only remember one thing: **new capability = a module that subscribes to
events + adds routes/services**, *not* an edit to `globals.php` or the route
tables.

---

## 2. Where the data lives

| Data | Location | Notes |
|---|---|---|
| **Clinical + practice data** | MySQL/MariaDB, one schema per **site** | `sql/database.sql` (255 InnoDB tables). Patient core in `patient_data`; visits in `form_encounter`; clinical form data in per-form tables + the polymorphic `lists` (problems/allergies/meds) and `forms` linkage table. |
| **Configuration (settings)** | `globals` **DB table** (+ `user_settings`, `patient_settings` overrides) | Loaded into `$GLOBALS`/`OEGlobalsBag` per request (see Perf P1). *Not* `.env`. |
| **DB connection config** | `sites/<site_id>/sqlconf.php` (per site, on disk) | The real DB credentials for the legacy app. `.env` only holds `OPENEMR__ENVIRONMENT` + `OPENEMR__NO_BACKGROUND_TASKS`. |
| **Service wiring** | `config/*.php` (PSR-11 `firehed/container`) | **Aspirational** — "not yet fully integrated" (`config/README.md`). See §5 constraint. |
| **Sessions** | PHP session store (files or Redis/predis-sentinel) | Four cookie scopes: core / api / oauth / portal (`SessionConfigurationBuilder`). |
| **Audit trail** | `log` / `extended_log` tables | Written inline by `EventAuditLogger` (Sec S10, Perf P5). |
| **Registered modules** | `modules` DB table | `mod_active`, `mod_directory`, `type` (1 = Laminas/Zend, ≠1 = custom). Drives what loads at bootstrap. |
| **Background jobs** | `background_services` DB table | `(name, function, require_once path, execute_interval, …)` — executable config (Sec S6). |
| **Schema changes** | Doctrine Migrations — `src/Core/Migrations`, `db/Migrations` | New schema goes here; legacy per-form `table.sql` still exists for forms. |
| **Identity** | dual: `pid` (int PK) **and** `uuid` (binary(16)) on most resources | Know which a layer expects (`START_HERE.md` #3, Perf constraints). |

**Multi-tenancy is by site:** each `sites/<id>/` has its own DB config and the
`site_id` is resolved early in *both* bootstraps. There is no cross-site data or
connection sharing.

---

## 3. How the layers interact (the two spines)

Both request spines converge on the same service + data tiers — they differ only
in bootstrap and dispatch:

```
LEGACY WEB SPINE                          MODERN API SPINE
root *.php / interface/**                  apis/dispatch.php
  └─ require interface/globals.php           └─ HttpRestRequest::createFromGlobals()
       (site, DB, globals, $ignoreAuth)          └─ ApiApplication → OEHttpKernel
  └─ auth.inc.php (unless $ignoreAuth)              └─ subscriber chain (SiteSetup,
  └─ page logic renders HTML                            OAuth2, Authorization, Routes…)
       │                                              └─ RouteFinder → route closure
       │                                                   │  request_authorization_check()
       ▼                                                   ▼
  ┌──────────────────────────────────────────────────────────────┐
  │ Cross-cutting: Symfony EventDispatcher  (the shared nervous    │
  │ system — both spines dispatch/subscribe here)                  │
  └───────────────┬──────────────────────────────────────────────┘
                  ▼
        src/Services/** (BaseService)  →  QueryUtils / Doctrine DBAL  →  MySQL
```

The **EventDispatcher is the integration backbone**: it is the one seam both the
legacy and modern spines share, and the one modules use to inject behavior
without touching either spine's code.

---

## 4. Integration paths — "I want to add X"

The core recipes, each verified against real code. **The golden rule: extend via
a module + events; do not edit core route tables or `globals.php`.**

### 4a. A new custom module (the umbrella for most capabilities)
1. Create `interface/modules/custom_modules/oe-module-<name>/` with an
   `openemr.bootstrap.php`.
2. That file receives `$classLoader` (`ModulesClassLoader`) and `$eventDispatcher`
   **in scope** (injected by the loader). Pattern (from the telehealth module,
   `oe-module-comlink-telehealth/openemr.bootstrap.php`):
   ```php
   $classLoader->registerNamespaceIfNotExists('Vendor\\Module\\', __DIR__ . '/src');
   $bootstrap = new Bootstrap($eventDispatcher, OEGlobalsBag::getInstance()->getKernel());
   $bootstrap->subscribeToEvents();
   ```
3. Register the module in the `modules` table (via **Modules installer** UI). At
   bootstrap, `ModulesApplication::bootstrapCustomModules()` selects
   `WHERE mod_active = 1 AND type != 1` and `include`s each bootstrap
   (`src/Core/ModulesApplication.php:132-164`); a missing bootstrap auto-disables
   the module. `ModuleLoadEvents::MODULES_LOADED` fires when done.

### 4b. Hook into an existing flow → **subscribe to an event**
The primary "change behavior without forking core" seam. **80 event classes**
across ~30 domains under `src/Events/**` (Patient, Encounter, Appointments,
Billing, User, PatientPortal, UserInterface, Main/Tabs, RestApiExtend, …). In
your module's `subscribeToEvents()`, add listeners via the injected
`$eventDispatcher`. Find the right event by domain folder name first.

### 4c. A new REST / FHIR / Portal API endpoint → **`RestApiCreateEvent`**
Do **not** edit `_rest_routes*.inc.php` (that's core). `StandardRouteFinder`
dispatches `RestApiCreateEvent` (`restConfig.route_map.create`) with the current
route maps (`src/RestControllers/Finder/StandardRouteFinder.php:36-37`). Subscribe
and call:
```php
$event->addToRouteMap("GET /api/vendor/thing/:id", $closure);   // standard
$event->addToFHIRRouteMap("GET /fhir/VendorResource/:id", $closure);
$event->addToPortalRouteMap(...);
```
(`src/Events/RestApiExtend/RestApiCreateEvent.php`.) **Caveat:** the closure must
still call `RestConfig::request_authorization_check(...)` itself — authz is
per-route and manual (Sec S5). Add scopes via the sibling `RestApiScopeEvent`.

### 4d. A new service (business logic + persistence)
Extend `BaseService` with a `TABLE_NAME`, use `QueryUtils` for queries, return a
`ProcessingResult`. This is the *standard* tier and where the test guardrails
live (`CLAUDE.md` "Service Layer Pattern"; `BaseService::search()` etc.). Prefer
the modern search/pagination path (`SearchQueryConfig`) over raw `search()`
(Perf P4).

### 4e. A new clinical form → **the pluggable-forms contract**
Forms are directories under `interface/forms/<name>/` with a **fixed file
contract** (from `interface/forms/vitals/`): `new.php`, `view.php`, `save.php`,
`report.php`, `table.sql` (its own table), `C_Form<Name>.class.php`, `info.txt`,
`templates/`. Register the form so it attaches to encounters (`registry` table /
Forms admin). Rigid contract — follow an existing form exactly.

### 4f. A schema change / new table → **Doctrine Migrations**
New schema goes in `src/Core/Migrations` / `db/Migrations` as a Doctrine
migration (`CLAUDE.md`: "New schema changes use Doctrine Migrations"). Legacy
per-form tables still ship a `table.sql`, but new work should not. Don't hand-add
`CREATE TABLE` to `sql/database.sql` for a feature.

### 4g. A new background job → **`background_services` row**
Insert a row: `(name, function, require_once path, execute_interval, …)`.
`BackgroundServiceRunner` resolves the include via `SafeIncludeResolver` and calls
the named global function (`CURRENT_ARCHITECTURE.md` §6). **Security-sensitive**
seam (Sec S6) — the callable is data-driven; govern who can insert rows.

### 4h. A new global setting → `library/globals.inc.php`
Add a definition to the settings array (`library/globals.inc.php`, e.g. the
`enable_auditlog` block at :2778). It becomes an admin-editable `globals` row and
is readable via `OEGlobalsBag::getInstance()->getBoolean(...)`. (Note the
per-request load cost, Perf P1.)

---

## 5. Constraints that will shape any architecture edit

Since this precedes an **architecture edit** review, these are the seams where
"the obvious change" fights the current design — confirm intent before editing:

- **The DI container is aspirational.** `config/*.php` exists but is "not fully
  integrated"; REST route closures `new` up controllers directly. You usually
  **cannot** just declare a dependency and have it injected — you wire it
  manually or via the event/module bootstrap (`OPEN_QUESTIONS.md` #3;
  `START_HERE.md` #4).
- **Two HTTP object models on the API path.** Request is HttpFoundation;
  controllers return PSR-7; bridged per response (`OPEN_QUESTIONS.md` #4). New API
  code must live with the bridge.
- **Authorization is per-route and manual** (Sec S5 / `OPEN_QUESTIONS.md` #5) — a
  new route without an explicit check is silently unprotected. No default-deny gate.
- **Auth enforcement is an ambient global** (`$ignoreAuth`, Sec S4) — any new
  legacy page inherits this fragile pattern; don't add more opt-outs without review.
- **`globals.php` and `auth.inc.php` are untested and load-bearing** (Sec S11,
  `START_HERE.md`) — edits there have huge blast radius and no test net.
- **Executable config tables** (`background_services`, `modules`) mean some
  "architecture" lives in **data**, not code — a full picture requires reading
  those rows, not just the tree.
- **The module `include`-a-bootstrap model** (`ModulesApplication:188`, "do we
  really want to just include a file??") is itself flagged as provisional — a
  likely target of any modernization, so build modules to the documented
  interface, not its current internals.

---

## 6. Architecture health summary

**Genuinely modern, safe to extend:** the service tier (`BaseService` +
`QueryUtils` + `ProcessingResult`), the FHIR/OAuth2 stack, the event system, and
the module bootstrap interface. These are the sanctioned extension points and
they are coherent.

**Extend-with-care (mid-migration or legacy):** the REST dispatch (two HTTP
models, manual authz), the two-bootstrap seam, and anything in
`library/`/`interface/`. The architecture is **mid-transition** — a modern skin
(events, modules, services, migrations) over a legacy spine (globals, framesets,
procedural pages). The extension seams point at the modern skin; the risk is that
"copy the nearest legacy file" pulls new work back into the spine.

*The most useful pre-edit question for the team (from `OPEN_QUESTIONS.md` #22):
is there a documented **target** architecture and an owner for the modernization
frontier? The seams above describe where to plug in *today*; whether a given edit
should follow the modern seam or the legacy pattern depends on that answer.*

---

# Audit 4 — Data Quality

**Focus:** how complete, consistent, and reliable the data actually is — framed
around the failure modes that matter to us: missing fields, inconsistent
formatting, duplicate records, and stale data that an agent consuming this data
would trip over.

**Method caveat (read this — it changes how to use this audit).** We do **not**
have a populated production database to profile from here, so this audit does
**not** report "X% of names are blank." It audits the **schema and the write path**
— the structural conditions that *permit* bad data — which is arguably the more
durable artifact: these conditions apply to *any* deployment's data, and they are
exactly what an agent must defend against regardless of a given site's current
numbers. Every finding names the code/schema and ends with the concrete
**profiling query** the team should run against their real DB to quantify it. The
"agent consumption rules" (§end) are directly actionable now.

## Severity summary

| # | Finding | Dimension | Severity |
|---|---|---|---|
| **D0** | `SET sql_mode = ''` on every connection — strict mode disabled | *root cause* | **Critical** |
| D1 | 318 columns are `NOT NULL DEFAULT ''` — empty string = missing | Completeness | **High** |
| D2 | `DOB` and 167 date columns nullable — clinical dates often absent | Completeness | **Medium** |
| D3 | Layout/`lists`/`list_options`-driven fields — presence is app-enforced only | Completeness | **Medium** |
| D4 | Boolean stored 4+ ways (`tinyint`, `'YES'`, `'yes'`, `enum('Yes','No')`) | Consistency | **High** |
| D5 | No charset/collation declared on any table — encoding deployment-dependent | Consistency | **Medium** |
| D6 | Free-text/`TEXT` date columns; zero-dates re-permitted by D0 | Consistency | **Medium** |
| D7 | Dual `pid`/`uuid` identity; `uuid` nullable (31/35) + batch-backfilled | Consistency | **High** |
| D8 | No natural-key uniqueness on `patient_data` — duplicate patients allowed | Duplicates | **High** |
| D9 | Polymorphic `lists` + free-text coded fields — near-duplicate entries | Duplicates | **Medium** |
| D10 | Soft-delete/`activity` flags, not hard deletes — stale rows persist | Staleness | **Medium** |
| D11 | `*_date`/updated timestamps maintained by app code, not the DB | Staleness | **Medium** |

---

## D0 (root cause) — Strict mode is disabled on every connection — **Critical**
`src/BC/DatabaseConnectionFactory.php:70` and `:133` both run
`SET sql_mode = ''` when opening the connection (ADODB and mysqli paths). This
**turns off MySQL strict mode entirely**, so the database silently accepts what
it would otherwise reject:
- **String overflow → silent truncation** (a 300-char note into a `varchar(255)`
  loses 45 chars, no error).
- **Invalid/zero dates accepted** — `'0000-00-00'` enters `DATE` columns even
  though *no schema default* uses it (so the "0 zero-dates in schema" result is
  cold comfort; the write path re-permits them).
- **Out-of-range numerics clamped**, wrong types coerced, missing values for
  NOT-NULL-without-default columns get implicit defaults.

This is the **root cause that makes every finding below worse**: the datastore is
deliberately configured not to defend its own integrity, so all validation
responsibility falls on app code — which the legacy tier largely lacks (see
Security S11, and `CURRENT_ARCHITECTURE.md` §8 on legacy under-testing).
**Profiling query:** `SELECT @@SESSION.sql_mode;` (expect empty) and scan
high-value text columns for values at exactly the column length (truncation
signature). **Recommendation:** move toward a strict `sql_mode`
(`STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE`) — but this is a
behavior-changing migration (writes that silently "worked" will start erroring),
so it needs a data-cleanup + app-hardening plan, not a one-line flip.

---

## Completeness

### D1 — `NOT NULL DEFAULT ''` everywhere (empty string as "missing") — **High**
**318** columns are declared `NOT NULL DEFAULT ''` (`grep -c "NOT NULL default ''"`).
Patient core is representative (`sql/database.sql`, `patient_data`):
```sql
`fname`  varchar(255) NOT NULL default '',
`lname`  varchar(255) NOT NULL default '',
`ss`     varchar(255) NOT NULL default '',
`sex`    varchar(255) NOT NULL default '' COMMENT 'Sex at birth',
`pubpid` varchar(255) NOT NULL default '',
```
`NOT NULL` here guarantees the *column* is present, **not that it has a value**.
`WHERE lname IS NOT NULL` returns everyone; the missing ones have `lname = ''`.
**Agent failure mode:** false-complete filters; treating an empty string as a
real name/sex/identifier. **Profiling query:**
`SELECT COUNT(*) FROM patient_data WHERE lname = '' OR fname = '' OR sex = '';`

### D2 — Nullable clinical dates — **Medium**
`DOB` is `date default NULL`; **167** date/datetime/timestamp columns are
`DEFAULT NULL`. Dates central to age/eligibility/ordering are frequently absent.
**Agent failure mode:** date arithmetic on NULL, or assuming DOB present for age
calculations. **Profiling query:**
`SELECT COUNT(*) FROM patient_data WHERE DOB IS NULL;`

### D3 — Layout- and list-driven fields are schema-optional by design — **Medium**
Much clinical/demographic data is stored via Layout-Based Forms and the
polymorphic `lists`/`list_options` model (**4,890** `list_id`/`list_options`
references in the schema). Field presence and validity are **entirely
app/config-enforced**, not schema-enforced — completeness is a runtime property
of how a given site configured its layouts, not a guarantee you can read off the
DDL. **Profiling query:** per-layout, count populated vs. empty
`form_*`/`lists` rows for the fields your agent depends on.

---

## Consistency

### D4 — Boolean represented at least four incompatible ways — **High**
- `tinyint(1)` — **138** columns (`active`, most modern flags)
- `varchar(3) NOT NULL DEFAULT 'NO'` — e.g. `hipaa_allowsms`, `pc_sendalertsms`
- `varchar` `'YES'` — e.g. `patient_data.allow_patient_portal` (compared as
  `!= "YES"` in `AuthUtils`)
- `enum('Yes','No')`, `char(3) DEFAULT 'yes'` — mixed case, mixed type

**Agent failure mode:** a truthiness check that passes for `1` silently fails for
`'YES'`/`'yes'`/`'Yes'`; string `'NO'` is truthy in most languages. Every boolean
must be normalized **per column** (case-insensitive, type-aware).
**Profiling query:** `SELECT DISTINCT allow_patient_portal FROM patient_data;`
(and similar) to see the actual value vocabulary per flag.

### D5 — No charset/collation declared on any table — **Medium**
`grep -ci charset sql/database.sql` = **0**; every table ends `) ENGINE=InnoDB;`
with no `DEFAULT CHARSET`/`COLLATE`. Encoding is inherited from the server/db
default at install time, so it is **deployment-dependent and not guaranteed
utf8mb4**. **Agent failure mode:** 4-byte characters (emoji, some CJK, certain
accented names) truncated/corrupted on a latin1/utf8(3-byte) server; string
comparison and sort order differ between deployments; you cannot assume Unicode.
**Profiling query:**
`SELECT default_character_set_name FROM information_schema.SCHEMATA WHERE schema_name = DATABASE();`

### D6 — Free-text dates + re-permitted zero-dates — **Medium**
Some "date" data is stored as free text: `erx_rx_log.date varchar(25)`,
`publ_code_eff_date TEXT`. Formatting is unenforced, and via **D0** even real
`DATE` columns can hold `'0000-00-00'`. **Agent failure mode:** parse failures,
zero-dates read as year-0 / epoch-underflow. **Profiling query:**
`SELECT COUNT(*) FROM <table> WHERE <date_col> = '0000-00-00';` across DATE
columns; sample the varchar/TEXT date columns for format spread.

### D7 — Dual identity; `uuid` nullable and batch-backfilled — **High**
Most resources carry both `pid`/integer id **and** `uuid binary(16)`, but only
**4 of 35** `uuid` columns are `NOT NULL` — **31 are `DEFAULT NULL`**. Rows are
created with a NULL uuid and populated later: `UuidRegistry` backfills in
batches keyed on `WHERE uuid IS NULL OR uuid = ''`
(`src/Common/Uuid/UuidRegistry.php:278`), with whole-table backfills "triggered
by an authenticated request" (:240) — i.e. **non-deterministic timing**. So at
any given moment some rows have no uuid. **Agent failure mode:** joining/resolving
by uuid silently drops un-backfilled rows; the two identity systems can disagree
about what exists. **Rule:** treat `pid` as the reliable key and `uuid` as
best-effort. **Profiling query:**
`SELECT COUNT(*) FROM patient_data WHERE uuid IS NULL OR uuid = '';`

---

## Duplicates

### D8 — No natural-key uniqueness on `patient_data` — **High**
`patient_data` has UNIQUE keys only on the **surrogate** identifiers (`pid`,
`uuid`) — nothing on `(lname, fname, DOB, ss)` or any demographic combination.
The database therefore **permits multiple rows for the same human being**
(duplicate patients — the canonical EHR data-quality failure). The only mitigation
is an app-layer "search or add patient" flow, which is advisory, not enforced,
and bypassed by imports/API writes. **Agent failure mode:** treating a `pid` as
"a person" when it is really "a registration"; a patient's history split across
several pids so no single query returns the whole record. **Profiling query:**
`SELECT lname, fname, DOB, COUNT(*) c FROM patient_data GROUP BY lname, fname, DOB HAVING c > 1;`

### D9 — Polymorphic `lists` + free-text coding — **Medium**
Problems, allergies, and medications share one wide `lists` table discriminated
by `type`, with coded values (`list_options`) and free text coexisting in the
same fields. Near-duplicate entries (same concept, different text/code, or
re-entered per encounter) are common and unprevented. **Agent failure mode:**
double-counting a condition/med; reconciliation across free-text + coded variants.
**Profiling query:**
`SELECT pid, type, title, COUNT(*) c FROM lists GROUP BY pid, type, title HAVING c > 1;`

---

## Staleness

### D10 — Soft-delete / activity flags, not hard deletes — **Medium**
Clinical rows are typically retired via an `activity`/`deleted` flag rather than
removed. Retired/superseded data persists indefinitely in the same tables.
**Agent failure mode:** an agent that omits the `activity = 1` (or equivalent)
filter reads discontinued meds, resolved problems, or deleted encounters as
current. **Profiling query (per table):**
`SELECT activity, COUNT(*) FROM lists GROUP BY activity;`

### D11 — Timestamps are app-maintained, not DB-guaranteed — **Medium**
Many `date`/`*_date`/updated columns are set by application code, not by DB
`DEFAULT CURRENT_TIMESTAMP` / `ON UPDATE` triggers, and — via **D0** — a missed
update won't error. "Last updated" is therefore only as reliable as the code path
that wrote the row. **Agent failure mode:** using a timestamp to decide freshness
/ "most recent" when it may not have been updated on the last change.
**Profiling query:** compare `audit`/`log` write times against row timestamps for
a sample of recently-edited records.

---

## Positives / mitigations observed

- **Zero-dates are absent from schema *defaults*** — the DDL never defaults to
  `'0000-00-00'` (weakened by D0 at write time, but the schema intent is clean).
- **All-InnoDB with FKs in the modern tier** — referential integrity is enforced
  where modern migrations added constraints (legacy tables largely lack FKs).
- **`UuidRegistry` systematically backfills** — the uuid gap is self-healing over
  time, just not instantaneous (D7).
- **The modern service/API tier validates at the app layer** — `ProcessingResult`
  carries `validationErrors`; `CLAUDE.md`'s "parse, don't validate" applies to new
  `src/` code, so **data entering via the FHIR/REST path is materially cleaner**
  than data written by legacy pages or direct SQL.
- **`list_options` provides a controlled vocabulary** where sites actually use
  coded values instead of free text.

---

## Rules for an agent consuming this data (actionable now)

1. **Treat `''` as NULL/unknown** — especially names, `sex`, identifiers (D1).
   Filter with `!= '' AND IS NOT NULL`.
2. **Trust `pid`, not `uuid`** — expect NULL/absent uuids; resolve identity via
   `pid` and backfill uuid only if present (D7).
3. **Normalize booleans per column** — case-insensitive, accept
   `1/'1'/'yes'/'y'/'YES'/'Yes'` as true; never rely on language truthiness (D4).
4. **Don't equate a `pid` with a unique person** — dedupe by demographics before
   aggregating a patient's record (D8).
5. **Validate every date defensively** — handle NULL, `'0000-00-00'`, and
   free-text formats; never assume a parseable ISO date (D0, D6).
6. **Always apply `activity`/`deleted` filters** — or you will read stale/retired
   clinical data as current (D10).
7. **Don't assume Unicode/utf8mb4** — degrade gracefully on encoding artifacts (D5).
8. **Prefer the FHIR/REST read path over raw table reads** — it is validated,
   paginated, and uuid-resolved; raw legacy tables expose all of the above (D-positives).

*This audit is schema/write-path-derived, not a live-data profile. Run the per-
finding profiling queries against the target deployment to turn each "structurally
permitted" into a measured rate — that quantification is the natural next step and
the thing to hand an agent-integration effort.*

---

# Audit 5 — Compliance & Regulatory (HIPAA-focused)

**Focus:** audit logging requirements, data retention policies, breach
notification obligations, Business Associate Agreements, and the implications of
sending PHI to an LLM provider. Security Audit 1 touched HIPAA at the *control*
level (cookies, error leakage, authz); this pass treats compliance as its own
regime — technical safeguards **plus** the administrative/organizational
obligations the code cannot satisfy on its own.

> **This is not legal advice.** It is a technical/architectural compliance
> assessment to inform your compliance officer and counsel. HIPAA has three
> safeguard families — **administrative** (§164.308), **physical** (§164.310),
> and **technical** (§164.312) — and most of what follows about retention,
> breach, and BAAs is administrative/contractual: the codebase can *support* it
> but not *be* it. Citations are to 45 CFR Parts 160/164 for orientation, not as
> authoritative legal interpretation.

**Applicable regimes for this system:** HIPAA Privacy + Security + Breach
Notification Rules; the HITECH Act; ONC Health IT Certification / Cures Act
**information-blocking** rules (45 CFR Part 171) — OpenEMR is ONC-certified for
the (g)(10) FHIR API (`GLOSSARY.md`: Inferno/G10); and **state** medical-record
retention and breach laws, which are often stricter than federal and vary by
state. 42 CFR Part 2 (substance-use records) may apply depending on the practice.

## Summary

| # | Area | Code support today | Gap / obligation | Severity |
|---|---|---|---|---|
| C1 | Audit logging (§164.312(b)) | Strong: `EventAuditLogger`, granular events, optional ATNA export | Not tamper-resistant; disable/delete possible; long default logoff | **Medium** |
| C2 | Data retention (§164.316(b)(2) + state law) | None (logs grow unbounded) | No retention schedule, archival, or minimum-retention enforcement | **Medium** |
| C3 | Breach notification (§164.400-414) | Indirect (audit trail = evidence) | Purely organizational; security gaps ↑ breach risk | **High (if unaddressed)** |
| C4 | Business Associate Agreements (§164.502(e)) | Reveals PHI-sharing vendors | Each PHI vendor — incl. any LLM — needs a signed BAA | **High** |
| C5 | **PHI → LLM provider** | None at runtime (Claude used only for dev) | BAA + minimum-necessary + de-id + disclosure logging before any PHI flows | **Critical (gating)** |

---

## C1 — Audit logging requirements — **Medium**
**Requirement:** §164.312(b) (audit controls) + §164.308(a)(1)(ii)(D) (information
system activity review) — record and examine activity in systems containing ePHI.

**What the code provides (genuinely strong):**
- `EventAuditLogger` writes logins, logouts, auth failures, and data
  create/update/delete to `log`/`extended_log`, with **granular per-category
  toggles** (`audit_events_patient-record`, `…_lab-results`,
  `…_security-administration`, etc.) all **defaulting on** (Sec S10;
  `library/globals.inc.php:2778+`).
- **ATNA export exists** — `enable_atna_audit` ships audit events over TCP to an
  external IHE-ATNA/syslog audit repository (`EventAuditLogger.php:49-52`,
  `src/Common/Logging/Audit/Atna/`). This is the HIPAA-grade control: an external,
  append-only, tamper-evident audit sink. **Off by default.**
- **Automatic logoff** (§164.312(a)(2)(iii), addressable) is present: `timeout`
  (default **7200s / 2h**) and `portal_timeout` (default **1800s / 30m**)
  (`library/globals.inc.php:2113-2120`).

**Gaps:**
1. **Not tamper-resistant.** The DB audit tables can be disabled (Sec S10) and
   rows deleted — `EventAuditLogger.php:624` even issues
   `DELETE FROM extended_log WHERE id=…`. Without ATNA enabled, the audit trail
   lives in the same DB it audits, editable by anyone with DB access. **Enable
   ATNA (or equivalent external SIEM shipping) for any real deployment.**
2. **2-hour idle logoff is long** for workstations that may sit in shared
   clinical areas. Many covered entities set 10-15 min. Policy tuning item.
3. **No log-review workflow** — §164.308 requires *reviewing* activity, not just
   recording it. That's procedural, but the data supports it.

---

## C2 — Data retention policies — **Medium**
**Requirement:** §164.316(b)(2) — retain required HIPAA *documentation* (policies,
risk analyses, and audit/access records to the extent they are "required
documentation") for **6 years** from creation or last-effective date. **Medical-
record** retention itself is set by **state law** (commonly 6-10 years for adults,
longer for minors — until majority + N years) — a frequent misconception is that
HIPAA sets it; it does not.

**What the code provides:** effectively nothing — there is **no retention
schedule, archival, or purge policy**. Audit tables (`log`, `extended_log`) and
clinical data grow unbounded (see also Perf P5, and soft-deletes never removed,
Data D10). The only deletion is the manual single-row `DELETE` noted in C1.

**Implication (double-edged):**
- Unbounded growth *accidentally* satisfies retention **minimums**, but there is
  **no minimum-retention enforcement** — nothing stops premature deletion (ties
  to the tamper-resistance gap C1) — and no lifecycle management, which becomes a
  storage/performance liability.
- **Recommendation:** define a written retention schedule (per record type, per
  applicable state law), implement archival for aged audit data, and — importantly
  — **protect the audit trail against deletion before its retention minimum**
  (append-only store / WORM / external SIEM).

---

## C3 — Breach notification obligations — **High (if unaddressed)**
**Requirement:** Breach Notification Rule §§164.400-414 — on discovery of a breach
of unsecured PHI: notify affected individuals **without unreasonable delay, ≤60
days**; notify **HHS** (annually for breaches <500 individuals, within 60 days for
≥500); notify **media** for ≥500 in a state/jurisdiction; business associates must
notify the covered entity.

**What the code can and cannot do:** breach *notification* is procedural and
cannot live in code. But two code-level facts matter:
1. **The audit trail (C1) is the evidence base** for the breach-risk assessment
   ("what PHI, whose, accessed by whom") — another reason to make it
   tamper-resistant and complete.
2. **The open security findings are breach *precursors*.** Sec S1 (API error body
   leaks internals), S2 (JS-readable session cookie → XSS session theft), S3
   (`cookie_secure` off) are exactly the technical weaknesses that turn into
   reportable breaches. **Closing the Audit 1 findings is breach-risk reduction.**
3. **A PHI disclosure to an LLM with no BAA (C4/C5) could itself be a reportable
   breach** — an impermissible disclosure of unsecured PHI. This is the tightest
   coupling between this audit and C5.

**Recommendation:** ensure an incident-response + breach-assessment procedure
exists; wire the audit log into it; treat Audit 1 remediation as breach
prevention.

---

## C4 — Business Associate Agreements — **High**
**Requirement:** §164.502(e) + §164.308(b) + §164.314 — a covered entity must have
a signed **BAA** with any vendor that creates, receives, maintains, or transmits
PHI on its behalf, binding that vendor to safeguard PHI.

**PHI-touching third parties visible in the code** (from
`CURRENT_ARCHITECTURE.md` §4 external dependencies — each is a BAA candidate to
confirm against actual data flows):

| Vendor / integration | PHI exposure | BAA needed? |
|---|---|---|
| **Twilio, RingCentral** (SMS/fax reminders, `library/MedEx`, FaxSMS) | Appointment/name/contact PHI in message bodies | **Yes** |
| **Stripe, Authorize.net** (Omnipay, `src/PaymentProcessing`) | Payment + patient identity | **Yes** (payment + PHI) |
| **EMR Direct / Direct messaging** | Clinical documents | **Yes** |
| **Node `ccdaservice`/`ccr`** | C-CDA clinical docs | In-infrastructure, but if hosted externally → yes |
| **Cloud host / DB / backups** | All PHI at rest | **Yes** (hosting BAA) |
| **Google Sign-In** | Auth identity (arguably not PHI itself) | Assess |
| **An LLM provider (Anthropic / cloud LLM)** | *Whatever PHI an agent sends* | **Yes — see C5** |

**Recommendation:** maintain a BAA register mapped to these integrations; gate
enabling any PHI-transmitting module on a signed BAA. The code makes the *data
flow* discoverable — use it to drive the BAA inventory.

---

## C5 — Implications of sending PHI to an LLM provider — **Critical (gating)**

This is the finding most specific to what this team is evidently building — the
whole audit series is oriented toward "agent failure modes" and agent-ready data.

### Current state (important, and reassuring)
There is **no runtime PHI-to-LLM flow in the codebase today.** The only "AI"
footprint is **developer-time code generation** — files authored/refactored by
Claude Code / Claude.AI (`src/USPS/USPSAddressVerifyV3.php`,
`src/RestControllers/SMART/ScopePermissionParser.php`, `src/Gacl/Gacl.php`
comments, etc.). **Source code is not PHI**, so this is not a HIPAA event. The
compliance obligations below attach the *moment* a runtime feature sends patient
data to an LLM API — which the direction of this work suggests is coming.

### The obligations that attach when PHI first flows to an LLM
1. **BAA is mandatory and gating (§164.502(e)).** Sending *any* PHI (names, DOB,
   conditions, notes, appointment data, or an identifier like `pid`/`uuid` that
   maps back to a person) to an LLM API makes that provider a **Business
   Associate**. A **signed BAA must be in place before the first PHI-bearing
   request.** For Anthropic specifically, that means a HIPAA-eligible commercial
   arrangement, or routing via a cloud provider that offers its own BAA and
   HIPAA-eligible model hosting (e.g. AWS Bedrock / GCP Vertex) under that cloud's
   BAA. **No BAA → every such request is an impermissible disclosure** (and a
   candidate breach, C3).
2. **Zero Data Retention (ZDR).** Even with a BAA, pursue a **zero-retention /
   no-training** configuration so prompts and completions are not retained or used
   for model improvement. This shrinks the "maintain" surface and simplifies
   breach exposure. Confirm it contractually, not just by setting.
3. **Minimum Necessary (§164.502(b)).** Send only the PHI the task requires.
   Dumping a whole chart into a prompt likely violates minimum-necessary.
   Design prompts to include the narrowest field set; strongly prefer passing
   *references/IDs the agent resolves under access control* over inlining raw PHI.
4. **De-identification is the strongest mitigation where feasible
   (§164.514(a)-(b)).** If the task can run on **de-identified** data (Safe Harbor
   = remove the 18 identifiers; or Expert Determination), it is **not PHI** and
   HIPAA does not govern that flow. **But** — and this connects directly to Audit
   4 — naive de-identification is unsafe here: the **dual `pid`/`uuid` identity
   (D7)** means stripping the name while keeping a `pid` that maps back is **not**
   de-identified; and the **inconsistent formatting / empty-string / free-text
   (D1, D4, D6)** means an automated scrubber will miss identifiers hiding in
   free-text notes. De-identify deliberately and verify.
5. **Log every LLM disclosure in the audit trail.** Each PHI send to the LLM is a
   *disclosure* and should be recorded (who, which patient, what data class, when,
   purpose) via `EventAuditLogger` — for accounting-of-disclosures, minimum-
   necessary review, and breach investigation. Add an audit category for
   "external-AI disclosure." **This does not exist yet — build it with the
   feature.**
6. **Data residency & subprocessors.** The BAA must cover the LLM provider's
   subprocessors and data location; if using a cloud LLM, pin region and rely on
   the cloud BAA.
7. **Clinical-safety coupling (not strictly HIPAA, but adjacent).** Audit 4 showed
   the data is incomplete/inconsistent/duplicated. An agent that reasons over
   `''`-as-missing, duplicate patients, or stale (`activity`-flagged) rows can
   produce wrong clinical output. Garbage-in to an LLM that then informs care is
   both a safety and a liability exposure — the **Audit-4 "rules for an agent"**
   are a prerequisite, not an optional nicety.

### Recommended gating checklist before any PHI → LLM
- [ ] Signed BAA with the LLM provider (or cloud BAA covering the model).
- [ ] Zero/'no-training' data-retention confirmed contractually.
- [ ] Minimum-necessary prompt design (field-level, not whole-chart).
- [ ] De-identify where the task allows; verify the scrubber against free-text and
      the pid/uuid re-identification path (Audit 4 D7).
- [ ] Disclosure logging wired into `EventAuditLogger`.
- [ ] Data-residency / subprocessor terms reviewed.
- [ ] Compliance-officer / counsel sign-off (this audit is not that).

---

## Audit series — closeout

Five audits complete: **Security · Performance · Architecture · Data quality ·
Compliance**. They interlock deliberately:
- Disabled strict mode (Data **D0**) is a data-quality root cause *and* leans on
  the untested auth/legacy tier (Security **S11**).
- The audit-write cost (Perf **P5**) is bounded by the HIPAA audit requirement
  (Security **S10** / Compliance **C1**) — so the fix is async, not "log less."
- The security findings **S1/S2/S3** are breach precursors (Compliance **C3**).
- The data-flow map (Arch **§4** / external deps) drives the BAA inventory
  (Compliance **C4**), and the extension seams (Arch **§4**) are where any LLM
  integration — and its disclosure logging (**C5**) — would actually land.
- The Audit-4 "rules for an agent" are a **prerequisite** for safe/compliant LLM
  use (**C5.7**).

The single highest-leverage sequence if standing up agents on this EHR:
**(1)** close Security S1-S3; **(2)** enable ATNA/external audit (C1) and define
retention (C2); **(3)** put BAAs in place for all PHI vendors incl. the LLM (C4/C5);
**(4)** enforce minimum-necessary + de-id + disclosure logging on the LLM path
(C5); **(5)** apply the Audit-4 agent data rules. Findings that trace to unresolved
team questions remain cross-referenced to `OPEN_QUESTIONS.md`; resolving those may
reclassify individual items from "risk" to "accepted design."
