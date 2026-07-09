# Current Architecture (as-found baseline)

> **What this document is.** A snapshot of how OpenEMR is structured **as we
> found it**, before any changes by the current team. It is descriptive, not
> prescriptive — it records the existing design (including its inconsistencies),
> not a target design.
>
> **Baseline commit:** `859d6d3` (2026-07-06)
> **Method:** derived by reading entry points, wiring, and tests — not the
> project's own prose docs. Where the code and the docs disagree, this file
> follows the code. Open uncertainties are collected in
> [`OPEN_QUESTIONS.md`](OPEN_QUESTIONS.md); domain terms are defined in
> [`GLOSSARY.md`](GLOSSARY.md).
>
> If you want a *proposed* architecture, create a separate document
> (e.g. `TARGET_ARCHITECTURE.md`) rather than editing this one.

---

## 1. One-paragraph overview

OpenEMR is a Free/Open-Source electronic health records (EHR) and practice
management application. It is a **hybrid codebase spanning two eras**: modern
PSR-4 PHP under `/src` (the `OpenEMR\` namespace — Symfony/Laminas/Doctrine
components, strict types, DI-friendly services) coexisting with two decades of
legacy procedural PHP under `/library` and `/interface` (global state,
superglobals-as-plumbing, Smarty templates). The seam between the two eras is
the single most important thing to understand about the system, and it recurs
in every flow below.

---

## 2. Layers

From outermost (request entry) to innermost (data):

| Layer | Where | Responsibility |
|---|---|---|
| **Web entry points** | root `*.php` (`index.php`, `setup.php`, `admin.php`), `interface/**` | Legacy page controllers. Each page is its own entry point that includes the bootstrap and renders HTML. |
| **API entry point** | `apis/dispatch.php`, `oauth2/authorize.php` | Modern REST/FHIR front controller built on Symfony HttpKernel. |
| **Bootstrap** | `interface/globals.php` (legacy) **or** `bootstrap.php` + `SiteSetupListener` (modern) | Two parallel bootstraps. Set site id, open DB, load global config, wire auth. See §5. |
| **Routing / dispatch** | `_rest_routes*.inc.php`, `src/RestControllers/*RouteFinder`, `HttpRestRouteHandler` | Maps HTTP verb+path to a route closure. Array-of-closures, not attribute routing. |
| **Controllers** | `src/RestControllers/**` (API), `interface/**`, `controllers/` | Parse input, call services, shape responses. |
| **Service layer** | `src/Services/**` (mostly `extends BaseService`) | Business logic + persistence. The consistent "modern" tier. |
| **Data access** | `src/Common/Database/QueryUtils`, ADODB surface, Doctrine DBAL/ORM/Migrations | SQL execution. New schema via Doctrine Migrations; legacy via ADODB-style `sqlStatement()`. |
| **Templating** | Twig 3 (`templates/**`, modern), Smarty 4 (`library/smarty`, legacy) | Two engines; pick by file extension. |
| **Cross-cutting** | `src/Common/**` (Auth, Acl, Session, Logging, Crypto, Http, Csrf), `src/Events` | Shared services + Symfony EventDispatcher. |

**Namespace/location rule (from `CLAUDE.md`, confirmed by `composer.json`
autoload):** new code → `/src` under `OpenEMR\`; legacy helpers → `/library`.
The legacy patterns are explicitly *not* the standard for new code.

---

## 3. Module map (high level)

```text
                          ┌─────────────────────────────────────────┐
   Browser / API client   │  Web pages (interface/**, root *.php)    │
        │                 │  REST+FHIR (apis/dispatch.php)           │
        ▼                 └───────────────┬──────────────────────────┘
 ┌──────────────┐                         │
 │ Bootstrap    │  legacy: interface/globals.php  (sets $ignoreAuth,
 │ (2 variants) │  DB, $GLOBALS/OEGlobalsBag, includes auth.inc.php)
 │              │  modern: bootstrap.php + SiteSetupListener subscriber
 └──────┬───────┘
        │
        ▼
 ┌───────────────────────────────────────────────────────────────┐
 │ Cross-cutting (src/Common/**)                                  │
 │  Auth (AuthUtils, AclMain)   Session (SessionUtil, Tracker)    │
 │  Logging (EventAuditLogger, SystemLogger)   Crypto   Csrf      │
 │  Http (HttpRestRequest)      Events (Symfony EventDispatcher)  │
 └───────────────┬───────────────────────────────────────────────┘
                 │
                 ▼
 ┌───────────────────────────┐      ┌──────────────────────────────┐
 │ Controllers               │─────▶│ Service layer (src/Services)  │
 │  src/RestControllers/**   │      │  extends BaseService          │
 │  interface/** page logic  │      │  Patient, Encounter, Facility,│
 └───────────────────────────┘      │  FHIR/**, Background/**, ...   │
                                     └───────────────┬──────────────┘
                                                     │
                                                     ▼
                          ┌────────────────────────────────────────┐
                          │ Data access                             │
                          │  QueryUtils / ADODB  ·  Doctrine DBAL   │
                          │  Doctrine Migrations (new schema)       │
                          └───────────────┬─────────────────────────┘
                                          │
                                          ▼
                                 ┌──────────────────┐
                                 │ MySQL / MariaDB  │  + Redis (sessions/cache)
                                 └──────────────────┘
```

Notable `src/` domains (each roughly a bounded area): `Patient`, `Appointment`,
`Billing`, `PaymentProcessing`, `FHIR`, `Cqm`/ECQM, `ClinicalDecisionRules`,
`Pharmacy`/`Rx`, `Reports`, `PostCalendar`, `Pdf`, `Services/Background`,
`RestControllers`, `Common`. FHIR and the REST/OAuth2 stack carry the densest
modern implementation *and* the densest tests.

---

## 4. External dependencies

| Dependency | Used for | Where it enters |
|---|---|---|
| **MySQL / MariaDB** | Primary datastore | `QueryUtils`, ADODB, Doctrine DBAL/ORM |
| **Redis** | Sessions, cache (optional; there is a `development-easy-redis` stack and a redis-sentinel session handler) | `src/Common/Session/**`, `ext-redis` |
| **OAuth2 server** (`league/oauth2-server` + OpenID Connect) | API bearer-token auth | `oauth2/authorize.php`, `AuthorizationController`, `OAuth2AuthorizationListener` |
| **Selenium / Chrome** | E2E tests (Panther) | `tests/Tests/E2e`, dev `selenium` container |
| **LDAP / Active Directory** | Optional enterprise login | `AuthUtils::activeDirectoryValidation` |
| **Google Sign-In** (Workspace/OpenID) | Optional login | `AuthUtils::verifyGoogleSignIn`, `google/apiclient` |
| **Payment gateways** (Stripe, Authorize.net via Omnipay) | Billing/payments | `src/PaymentProcessing`, `src/Billing` |
| **Messaging** (Twilio, RingCentral) | SMS/fax/notifications | `library/MedEx`, FaxSMS modules |
| **PDF toolchains** (dompdf, mpdf, wkhtmltopdf/snappy) | Document generation | `src/Pdf`, report/export paths |
| **Node services** (`ccdaservice`, `ccr`) | C-CDA / CCR clinical document generation | separate Node processes, tested with Jest |

Config for the primary datastore is per-site: `sites/<site_id>/sqlconf.php`
(not `.env`). `.env` currently carries only `OPENEMR__ENVIRONMENT` and
`OPENEMR__NO_BACKGROUND_TASKS`. Runtime service wiring lives in `config/*.php`
(PSR-11 container via `firehed/container`), but per `config/README.md` that
container is **not yet fully integrated** into the legacy app.

---

## 5. The two-bootstrap seam (read this before anything else)

There are **two different ways the app initializes a request**, and which one
runs depends on the entry point:

- **Legacy pages** include `interface/globals.php`. This ~730-line procedural
  file resolves the site id, opens the DB, populates `$GLOBALS` /
  `OEGlobalsBag`, and — unless the caller set `$ignoreAuth = true` beforehand —
  includes `library/auth.inc.php` to enforce authentication.
  **`$ignoreAuth` is an ambient global that reconfigures a shared include**;
  the login screen sets it `true` (to render without auth), the post-login
  screen leaves it unset (to enforce auth). This is the "`$GLOBALS` as service
  locator" pattern that `CLAUDE.md` explicitly says is *not* the standard for
  new code — but it is load-bearing across the legacy tier.

- **The REST/FHIR API** does **not** use `globals.php`. Instead
  `apis/dispatch.php` → `ApiApplication` registers a `SiteSetupListener`
  subscriber that performs the equivalent setup (site id, DB, globals) inside
  the Symfony HttpKernel event chain.

Understanding this fork explains most "where does X get initialized?" questions.

---

## 6. Golden-path flows

### Flow A — A user logs in (legacy tier)

```text
index.php  ──redirect──▶  interface/login/login.php   (renders Twig form; sets $ignoreAuth=true)
                                     │ POST authUser/clearPass
                                     ▼
        interface/main/main_screen.php?auth=login   (also hosts MFA: TOTP + U2F)
                                     │  includes interface/globals.php  (auth NOT ignored)
                                     ▼
                        library/auth.inc.php:62
                                     │  (new AuthUtils('login'))->confirmPassword(...)
                                     ▼
        src/Common/Auth/AuthUtils::confirmUserPassword
          IP failed-login counter → users row (active?) → UserService group →
          AclExtended ACL group → users_secure hash → per-user failed counter →
          AuthHash::passwordVerify (or LDAP) → rehash → expiry → setUserSession →
          EventAuditLogger (audit row)
```

Touches: MySQL (`users`, `users_secure`, `globals`, audit log), optional
LDAP/Google. **Surprises:** the login form posts to `main/main_screen.php`
(the "screen" both authenticates *and* renders the app frame); the `AuthUtils`
constructor performs DB writes (dummy timing-attack hash, `password_expiration_days`
normalization); passwords are passed by reference and zeroed with
`sodium_memzero`.

### Flow B — A REST/FHIR API request

```text
apis/dispatch.php
  HttpRestRequest::createFromGlobals()  (Symfony HttpFoundation)
  ApiApplication::run()  ──▶  OEHttpKernel (Symfony HttpKernel) ──▶ subscriber chain:
     ExceptionHandler · Telemetry · ApiResponseLogger · SessionCleanup ·
     SiteSetup(site+DB+globals) · CORS · OAuth2Authorization · Authorization ·
     RoutesExtension · ViewRenderer
                                     │
     RoutesExtensionListener ──▶ Standard/FHIR/Portal RouteFinder
                                     │  (RestConfig::$ROUTE_MAP from _rest_routes*.inc.php)
                                     ▼
     HttpRestRouteHandler::dispatch  ──▶ route closure  e.g. "GET /api/patient" =>
        RestConfig::request_authorization_check(...) ; new PatientRestController()->getAll()
                                     │
        PatientRestController ──▶ PatientService (BaseService) ──▶ QueryUtils ──▶ MySQL
                                     │  returns ProcessingResult
        RestControllerHelper::createProcessingResultResponse  (PSR-7)
        ViewRendererListener bridges PSR-7 ──▶ Symfony Response ──▶ send()
```

Touches: MySQL, OAuth2 token validation, FHIR service locator, telemetry.
**Surprises / known rough edges (several flagged in the code's own TODOs):**
two HTTP object models coexist (request is HttpFoundation, controllers return
PSR-7, then it's bridged back); routes are a giant array of closures that `new`
up controllers directly (DI container bypassed); authorization is repeated
per-route as bare strings (`"patients","demo"`); `dispatch.php`'s last-resort
`catch` echoes `$e->getMessage()` in the JSON body, which contradicts the
project's own logging standard.

### Flow C — A background job runs ("Ajax piggyback" / cron)

```text
Triggers: browser AJAX poll (while logged in) │ cron CLI │ REST │ bin/console
                                     ▼
   library/ajax/execute_background_services.php
     (AJAX path: CsrfUtils::checkCsrfInput ; CLI path: sets $ignoreAuth, maps $argv→$_GET)
                                     ▼
   src/Services/Background/BackgroundServiceRunner::run
     single named service → runOne (inline)
     run-all-due → GET_LOCK orchestrator lock → one subprocess per service
                    (SymfonyBackgroundServiceSpawner) for fault isolation
                                     ▼
     acquireLock (atomic UPDATE lease with lock_expires_at/next_run; steals
       expired lease from crashed workers) → executeService: resolve require_once
       path via SafeIncludeResolver → require → call $function() by name → release
```

Touches: MySQL (`background_services` table + `GET_LOCK` advisory lock),
OS subprocess spawning, plus whatever each service reaches (Direct messaging,
MedEx reminders, etc.). **Notable:** this subsystem is a well-documented,
recently-modernized island (lease-based locking, crash recovery, subprocess
isolation, PSR-3 logging, references to GH issues #11661/#11794/#11827) that
stands apart from the surrounding legacy. Also notable: services are dispatched
by a `(file path, global function name)` pair stored in a DB row and invoked
dynamically — `SafeIncludeResolver` exists to guard that path. Background work
is coupled to user activity unless cron is configured; gated by
`OPENEMR__NO_BACKGROUND_TASKS`.

---

## 7. Build / run / test (grounded in manifests + CI)

- **Build:** `composer install`, `npm install`, `npm run build` (Webpack 5 + SASS),
  `composer dump-autoload -o`. Node ≥ 24, PHP ≥ 8.2.
- **Run (dev):** `docker/development-easy` compose stack → app on `:8300`/`:9300`,
  phpMyAdmin `:8310`, login `admin`/`pass`. `CLAUDE.md` wraps this in an
  `openemr-cmd` CLI + git-worktree workflow.
- **Test:** PHPUnit suites in `phpunit.xml` (unit, e2e, api, services, validators,
  controllers, common, ECQM, redis-sentinel) + `phpunit-isolated.xml` (DB-less).
  Jest for JS, bats for shell/docker.
- **Quality gates:** PHPStan level 10 (+ custom rules in `tests/PHPStan/Rules`),
  Rector, PHP_CodeSniffer, composer-require-checker, codespell, ESLint,
  Stylelint, Semgrep, hadolint — enforced via ~55 GitHub Actions workflows and
  pre-commit hooks.

---

## 8. Testing posture (one-line summary — see the review for detail)

Testing investment concentrates on **(a)** the externally-audited surface (FHIR
/ ONC certification, the REST API contract) and **(b)** the modernized `src/`
islands (background services, crypto, routing, OAuth2 subscribers). It is thin
-to-absent across the legacy web tier and the login/`AuthUtils` security core,
and the legacy `library/`/`interface/` directories sit in the coverage
denominator while being nearly uncovered. Treat the FHIR/API/certification and
`Isolated` tests as reliable ground truth; treat the legacy tier as
under-specified. Details and specific gaps are in the accompanying testing
review and in [`OPEN_QUESTIONS.md`](OPEN_QUESTIONS.md).
