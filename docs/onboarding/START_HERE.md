# Start Here (as-found baseline)

> **Read this first.** An opinionated orientation for anyone about to change
> OpenEMR, based on tracing the codebase **as we found it** (baseline commit
> `859d6d3`, 2026-07-06). It is deliberately blunt about what's risky. For the
> full picture: [`CURRENT_ARCHITECTURE.md`](CURRENT_ARCHITECTURE.md) (how it
> works), [`GLOSSARY.md`](GLOSSARY.md) (terms), and
> [`OPEN_QUESTIONS.md`](OPEN_QUESTIONS.md) (what to ask the team).

---

## The one-sentence mental model

OpenEMR is **two codebases in one**: a modern, strict-typed, service-based
`/src` (the standard) grafted onto two decades of global-state procedural PHP in
`/library` and `/interface` (not the standard) — and the seam between them
recurs in every flow.

---

## 5 things to understand before you touch anything

1. **The two-bootstrap seam + `$ignoreAuth`.** Legacy pages `require
   interface/globals.php`, which reads the ambient `$ignoreAuth` global to
   decide whether to enforce auth. The REST API skips `globals.php` and rebuilds
   the same state in `SiteSetupListener`. "Where does the DB / config / auth get
   set up?" has two answers depending on which door you came in.

2. **`/src` is the standard; `/library` and `/interface` are not.** Don't
   pattern-match off the file you happen to open — half of legacy is
   antipatterns the project is leaving behind. New code: PSR-4, `declare(strict_types=1)`,
   `BaseService` + `QueryUtils`.

3. **Dual identity: `pid` (legacy int) and `uuid` (modern).** Most resources
   carry both; the API returns both; routes use `:puuid`. Know which one a layer
   expects before reading/writing — mixing them corrupts silently.

4. **The DI container is aspirational.** `config/*.php` exists but
   `config/README.md` says it's "not fully integrated" and REST routes `new` up
   controllers directly. You usually *cannot* just inject a dependency and have
   it resolved.

5. **Know what "green" covers.** Tests are strong where OpenEMR is externally
   audited (FHIR/ONC certification, the REST API contract) and on the modern
   islands; near-absent on the legacy tier and login. A passing CI run says the
   FHIR surface still conforms — it says almost nothing about `library/`/`interface/`.

---

## Where to be careful (fragile / high-risk)

| Area | Why it's dangerous |
|---|---|
| **`AuthUtils` / `library/auth.inc.php` / login** | ~1,400 lines of security-critical logic with **no direct unit tests**; constructor even writes to the DB. You'd be working without a net. |
| **`interface/globals.php` + `$ignoreAuth`** | 730-line procedural bootstrap half the app depends on; behavior flips on a caller-set global. Huge blast radius. |
| **REST dispatch (mid-migration)** | Two HTTP models bridged (HttpFoundation ⇄ PSR-7), per-route string authz, `dispatch.php` leaks `$e->getMessage()`. "Copy the nearest example" can propagate a known wart. |
| **`background_services` table** | Executable config: runner invokes `(file path, function name)` from a DB row (guarded only by `SafeIncludeResolver`). |
| **Snapshot/golden tests** (Twig render, layout-field HTML) | Any markup change fails them; the "fix" is regenerate-fixtures, which makes rubber-stamping a regression easy. Eyeball the diff. |

---

## If you have to ship a change this week

**Go here first:** a `src/Services/*Service` reachable through a REST route that
already has an API test (`tests/Tests/Api`, `tests/Tests/Services`). That's where
the guardrails live — strict types, `QueryUtils`, `ProcessingResult`, and real
behavioral coverage to extend. `Services/Background` is the other safe bet (best
-documented, best-tested code in the repo).

**Avoid without more context:**
- `AuthUtils` / `auth.inc.php` / `globals.php` / `main_screen.php` (untested, global-driven, security-critical)
- ACL / phpGACL (`gacl/`, `AclMain`)
- The FHIR certification surface (breakage shows up as an Inferno test you may not run locally)
- Drive-by "cleanups" of the PSR-7 bridging or per-route authz to match `CLAUDE.md` — those are deliberate-for-now; refactoring them is a project, not a side quest.

---

## The biggest doc-vs-reality gap

`CLAUDE.md` describes a DI-first, strict-typed, no-global-state architecture —
but the two flows you're most likely to touch (login, and any legacy page) are
the **opposite** of that, **with no tests to catch you**. The standards describe
the destination; a large fraction of running code is the origin the project is
migrating from.

Its sharpest edge: **don't trust the checkmark on the legacy tier.** Some tests
that look like coverage are hollow — `FhirLocationServiceIntegrationTest` is
`markTestSkipped` on line 1 of every method (~200 lines of dead test), and the
E2e chain skips its own setup when data "already exists," so a green E2e run can
mean very little ran. **Read the test before you believe the coverage.**

---

*This file is a snapshot of first impressions, not a maintained spec. As the
team answers [`OPEN_QUESTIONS.md`](OPEN_QUESTIONS.md), fold confirmed facts into
`CURRENT_ARCHITECTURE.md` and let this file age into history.*
