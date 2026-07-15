# TRO-51 Design — Patient-Scoped SMART Launch

**Status:** PROVISIONAL — founder direction to proceed given 2026-07-15
("proceed on next backlog ticket swarm"); the D1–D4 decisions below are
implemented on their recommended options **pending founder ratification**
(asked 2026-07-15, no response — recorded here so the merge review can veto
any of them). The consent-skip global (D3) is NOT flipped without an
explicit founder yes. This document is the design pass the epic requires.

**Epic:** TRO-51. Children: TRO-52 (patient-bound authorization), TRO-53
(EHR patient-context launch), TRO-54 (Week 2 flows in the embedded panel),
TRO-55 (design pass for the embedded panel).

---

## 1. Problem (from the epic)

The co-pilot's OAuth client holds **physician-wide** `user/` scopes; the
patient is whatever `patient_uuid` the caller puts in the request body
(`Bootstrap::resolvePatientPid`, `oe-module-copilot/src/Bootstrap.php:470`).
Chart reads filter to the requested patient, but nothing constrains *which*
patient may be requested to the chart the physician is viewing. This was the
deliberate v1 delegation posture (ARCHITECTURE §4); TRO-51 tightens it to the
standards-based SMART EHR-launch model: a token **bound to one
launch-context patient**, structurally incapable of reaching another.

## 2. What core already provides (verified, file:line)

The entire launch → context → token → request-binding chain is native. **No
core edits are required.**

| Step | Mechanism | Evidence |
|---|---|---|
| Launch surface | "SMART Enabled Apps" card renders on the patient chart for every enabled client holding the `launch` scope | `src/FHIR/SMART/SmartLaunchController.php:40-96,183-198` |
| Launch click | `interface/smart/ehr-launch-client.php` → `redirectAndLaunchSmartApp()` → redirect to the client's launch URI with `?launch=<encrypted{p,e,i}>&iss=<fhir-url>&aud=<fhir-url>` | `SmartLaunchController.php:114-171` |
| Launch context token | Encrypted, opaque `{patient uuid, encounter, intent, appointment}` | `src/FHIR/SMART/SMARTLaunchToken.php:107-134` |
| Authorize flow | `launch` query param stored in the OAuth session; optional consent skip inside an EHR launch via global `oauth_ehr_launch_authorization_flow_skip` | `src/RestControllers/AuthorizationController.php:590,1657-1660` |
| Token context | Launch token decoded → `patient` written into the token response context and persisted with the access token | `src/Common/Auth/OpenIDConnect/SMARTSessionTokenContextBuilder.php:44-76`, `IdTokenSMARTResponse.php:200-202` |
| Request binding | For `users`-role tokens carrying `launch` or `launch/patient` scope, the stored context patient is loaded, **re-checked against the user's live patient access**, then bound: `setPatientUuidString()` | `src/RestControllers/Authorization/BearerTokenAuthorizationStrategy.php:258-265,434-454` |
| Patient-context routes | If the token's scope for the routed resource is `patient/…`, the request is marked a patient request; FHIR reads then hard-bind to the launch patient | `src/Common/Http/HttpRestRouteHandler.php:62-67`, `src/RestControllers/FHIR/FhirGenericRestController.php:94-104` |
| Module scope registration | `RestApiScopeEvent::addScope($context, $resource, $permission)` accepts a context — `patient/` module scopes are registerable; core already ships standard-API `patient/` scopes (precedent) | `src/Events/RestApiExtend/RestApiScopeEvent.php:53`, `src/Common/Auth/OpenIDConnect/Entities/ServerScopeListEntity.php:302-310` |

## 3. Module today (verified, file:line)

- **Scopes:** segment-generic `user/` only — `user/{ping,health,ready}.read`,
  `user/{turn,document,source}.write` (`Bootstrap::registerApiScopes`,
  `Bootstrap.php:144-163`). Demo client also requests broad `user/` FHIR
  scopes (`demo/copilot-demo.sh:55-61`).
- **Routes:** `GET ping|health|ready` (ACL `patients/demo`), `POST
  turn|document|source` (ACL `patients/med`), all via `GuardedRouteRegistrar`
  + `request_authorization_check` (`Bootstrap.php:165-440`). Patient = body
  `patient_uuid` → `resolvePatientPid` (`Bootstrap.php:470`). **No route
  consults `HttpRestRequest::getPatientUUIDString()`.**
- **Standalone token panel** (`public/panel.html:110-114`): Connection
  fieldset — API base + pasted Bearer token + pasted patient UUID.
- **In-EMR session tab** (`public/index.php` + `ajax.php`): session-bound
  (SessionGate: CSRF → ACL → named principal), patient chosen from the
  schedule dropdown; Week 1 read-only + the demo-night upload box.

## 4. Design

### 4.1 TRO-52 — patient-bound authorization (module-side only)

> **Verified constraint (2026-07-15 static pass):** core's route security
> check derives the required scope **context from the user role, not the
> token** — `$scopeType = role === 'users' ? 'user' : role`
> (`src/Common/Http/HttpRestRouteHandler.php:154`). A physician's
> EHR-launched token is `users`-role, so `/api/copilot/*` admission is
> always checked as `user/<segment>.<verb>`; `patient/`-context module
> scopes would 403 at `checkSecurity`. Therefore the module keeps its
> `user/` scopes for **route admission**, and the **patient binding** is
> enforced by the launch context (`launch` scope →
> `populateTokenContextForRequest` → `getPatientUUIDString()`) plus the
> module guard below. The binding — not the scope spelling — is the
> structural guarantee.

1. **Client scope set tightened (minimum necessary):** the SMART client
   registers `openid launch launch/patient api:oemr` + the module's
   existing `user/{ping,health,ready}.read` + `user/{turn,document,source}.write`
   — and **drops** the broad `user/` FHIR scopes the demo client carried
   (`demo/copilot-demo.sh:55-61`); the launched panel only calls module
   routes, and chart reads happen in-process as the delegated physician.
2. **New pure guard — `Routes\LaunchPatientBinding`** (final readonly, unit-
   testable in isolation): given the request's token-bound patient
   (`getPatientUUIDString()`) and the body `patient_uuid`, it returns the
   effective patient or refuses:
   - Token patient-bound + body uuid present → **must match exactly**, else
     403 before any chart read.
   - Token patient-bound + body uuid absent → the token's patient is the
     patient (the body field becomes optional on the launched surface).
   - Token **not** patient-bound → clinical routes (`turn`, `document`,
     `source`) **refuse** (403) — subject to decision D1 below.
3. **Wire the guard into the three clinical routes** in `Bootstrap.php`
   before endpoint construction. `ping/health/ready` stay as they are (no
   patient data).
4. **Defense in depth retained:** ChartReader/FhirReadGateway per-patient
   filtering and `resolvePatientPid` stay untouched; core's own
   `checkUserHasAccessToPatient` (BearerTokenAuthorizationStrategy.php:443)
   already re-validates the user↔patient relationship at token use.
5. **Disclosure/audit:** refusals log through the existing audit path with
   the correlation ID; no PHI in the refusal body (R11 generic errors).

**What TRO-52 does NOT touch:** `AuthorizationController`, phpGACL/ACL
internals, login flow, core route tables, core scope entities. The bright
lines hold: extension via module seams only.

### 4.2 TRO-53 — EHR patient-context launch

> **BLOCKING CONSTRAINT (verified live on the dev stack, 2026-07-15) — a
> founder call is required.** The client-registration endpoint **refuses
> `user/` scopes for a public client**: `POST /oauth2/default/registration`
> with `application_type: public` + any `user/…` scope returns
> `invalid_client_metadata: "system and user scopes are only allowed for
> confidential clients"`. The same registration as `application_type:
> private` (confidential) **succeeds**. Because §4.1 established that a
> physician's EHR-launched token is `users`-role and every `/api/copilot/*`
> route is therefore admitted as `user/<segment>.<verb>`
> (`HttpRestRouteHandler.php:154`), the launched client **must hold the
> module's `user/` route scopes** — which forces a **confidential** client.
> CodeRabbit independently flagged the same contradiction (`TRO51-DOC-001`).
>
> **This overturns recommended decision D2 (public client + PKCE).** The two
> coherent options are:
> - **D2-confidential (now recommended):** a confidential SMART client holds
>   the `user/` route scopes; PKCE is retained (valid and recommended even
>   for confidential clients), but the authorization-code → token exchange
>   **must move server-side** (it needs the client secret) — a small
>   module endpoint, not browser JS. `PkcePair`/`AuthorizeRedirect`/the
>   binding guard are unaffected; only `panel.html`'s current browser-side
>   exchange changes. This keeps §4.1 intact and touches no core scope model.
> - **D2-public-repriced:** re-scope every module route to `patient/` context
>   and prove core admits a `users`-role EHR-launch token against
>   `patient/<segment>` admission (unverified; deeper in the scope model,
>   closer to the danger-zone bright line). Not recommended without a spike.
>
> The frozen tests and the committed TRO-52/TRO-53 code are correct under
> **either** option; only the client type and the exchange location differ.
> **The live end-to-end smoke is parked on this decision** — recorded on the
> PR, not silently resolved.

1. **Register the co-pilot as a SMART app** (runtime data via API Clients
   admin, not code): a **confidential** client (per the constraint above)
   holding `openid launch api:oemr` + the module's existing
   `user/{ping,health,ready}.read` + `user/{turn,document,source}.write`,
   with `initiate_login_uri` → `public/launch.php` and a redirect URI →
   `public/panel.html`. The chart's SMART card then lists the co-pilot
   automatically (`SmartLaunchController.php:193` — any enabled client with
   `launch`).
2. **New `public/launch.php` + panel JS**: standard SMART app handshake —
   receive `?launch&iss`, verify `iss` is our own FHIR base, run the
   authorization-code + **PKCE** flow against `/oauth2/default/authorize`,
   exchange the code (server-side under D2-confidential), read `patient`
   from the token response context, open the panel bound to that patient.
   No Connection fieldset: base = `iss`, token = exchange result, patient =
   launch context.
3. **Rebind on relaunch:** each launch mints a fresh token bound to the
   currently-open chart; closing/reopening on another patient rebinds
   (acceptance criterion).
4. **Consent friction:** optionally set
   `oauth_ehr_launch_authorization_flow_skip = 1` (admin global,
   `AuthorizationController.php:1657-1660`) so in-EHR launches skip the
   consent screen — decision D3.

### 4.3 TRO-54 / TRO-55 — embedded surface (sequenced after)

TRO-55's aidesigner exploration targets the launched panel (no Connection
fieldset, patient identity as context, snapshot-first hierarchy, three
citation registers, upload/evidence/click-to-source as first-class controls).
TRO-54 then ports the Week 2 flows onto that surface, driving the same
guarded routes with the launch-bound token. Frozen Week 2 contract tests
(`DemoSurfaceContractTest`, `SourceResolverEndpointTest`,
`DocumentUploadContentModeTest`) stay green; the embedded surface gets its
own contract tests.

## 5. Test strategy (TDD, wave protocol)

- **Isolated (frozen before implementation):** `LaunchPatientBinding` guard —
  match/mismatch/absent/unbound cases incl. the cross-patient 403; scope
  registration contract (patient/ context present); route contract tests
  asserting the three clinical routes refuse an unbound token and a
  mismatched uuid.
- **DB-backed/live smoke (orchestrator, at wave close):** one end-to-end
  EHR launch against the dev stack via Selenium/Panther — chart → SMART card
  → launch → token with `patient` context → turn call succeeds for the
  launch patient and 403s for another patient. (The scope-context question
  that would have blocked freezing was settled statically:
  `HttpRestRouteHandler.php:154` — see §4.1.)
- **Regression:** full isolated suite + the standalone panel's frozen tests
  stay green; eval gate untouched (no prompt/fixture changes in this epic).

## 6. Founder decision points

- **D1 — fate of the `user/`-scoped clinical path.** Recommend: clinical
  routes (`turn`/`document`/`source`) require a patient-bound token,
  full stop. The standalone panel remains a dev smoke tool via a
  **standalone launch** (`launch/patient` — core prompts patient selection
  at authorize time), so no unbound path survives. Alternative: reserve the
  unbound `user/` path behind an explicitly named, env-gated operator mode.
- **D2 — client model. SUPERSEDED by the §4.2 blocking constraint** (public
  clients cannot hold the `user/` scopes the routes require; verified live
  2026-07-15). Now recommend: **confidential client + PKCE with a server-side
  code exchange** (D2-confidential). Original recommendation (public + PKCE,
  browser exchange) is not viable without re-scoping every route to
  `patient/` context (D2-public-repriced — needs a spike; not recommended).
  **Founder call needed before the live smoke and the `panel.html` exchange
  can be finalized.**
- **D3 — consent screen on each launch.** Recommend: set
  `oauth_ehr_launch_authorization_flow_skip = 1` (admin config, reversible)
  for the seamless one-click launch. Alternative: keep per-launch consent.
- **D4 — wave scope.** Recommend: Wave N = TRO-52 + TRO-53 (+ TRO-55 design
  exploration in parallel); TRO-54 port begins only after the TRO-55 review
  (the epic's own ordering).
