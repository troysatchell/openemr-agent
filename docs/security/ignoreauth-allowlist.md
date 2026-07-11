# `$ignoreAuth` opt-out allow-list (AUDIT.md S4)

OpenEMR enforces the staff authentication gate in `interface/globals.php` only
when `$ignoreAuth` is false — a legacy page that sets `$ignoreAuth = true` (or
`$ignoreAuth_onsite_portal = true`) before including `globals.php` is served
**without the staff auth gate** (`interface/globals.php:170-176, 721-725`;
`:722` auto-sets it when `portal_onsite_two_enable` is on). There is no central
registry of these opt-outs, no type safety, and the value is a mutable global —
so a forgotten pattern silently serves unauthenticated (S4).

This file is the reviewed registry. **Phase 1 (this doc): enumerate + classify.**
Phase 2 (a log-only runtime assertion when `$ignoreAuth` is true for a URI not
listed here) is deferred pending founder approval — see `tickets/security/SEC-S4`.

> **Reproduce (drift check):**
> ```bash
> grep -rnE '\$ignoreAuth(_onsite_portal)?\s*=\s*(true|1)\b' \
>   --include='*.php' interface library apis modules public portal \
>   | grep -vE '//|/\*'
> ```
> Enumerated 2026-07-10 → 45 files (docblock references excluded). This
> allow-list is enforced by
> `tests/Tests/Isolated/Security/IgnoreAuthOptOutCoverageTest` — a new opt-out
> file not on the reviewed list fails CI.

## Verdict legend
- **ALLOW** — legitimately unauthenticated by the staff gate; a *different*
  control applies (patient-portal session, OAuth2 spec, pre-login necessity).
- **ALLOW (note)** — accepted, but with a standing hardening note.
- **REVIEW** — not obviously justified from the entry point alone; needs a human
  decision that its alternative control (token, webhook signature, network
  restriction) is actually present.

---

## A. Pre-auth by necessity — ALLOW
The staff gate cannot apply here (you can't require a login to log in, or a token
to register for one).

| File:line | Purpose |
|---|---|
| `interface/login_screen.php:15` | login screen render |
| `interface/login/login.php:51` | login POST handler |
| `interface/smart/register-app.php:34` | SMART/OAuth2 dynamic client registration (pre-auth per spec) |
| `interface/globals.php:722` | auto-sets `$ignoreAuth` when `portal_onsite_two_enable` — delegates to the portal gate (the S4 mechanism itself) |

## B. Patient-portal session authenticated (`$ignoreAuth_onsite_portal`) — ALLOW
These skip the **staff** gate but require a **patient portal** session; the
portal authenticates the patient through its own mechanism.

| File:line |
|---|
| `interface/forms/LBF/new.php:31` |
| `interface/forms/questionnaire_assessments/questionnaire_assessments.php:32` |
| `interface/forms/questionnaire_assessments/save.php:31` |
| `interface/forms/sdoh/new.php:24` |
| `interface/forms/sdoh/save.php:25` |
| `library/ajax/upload.php:30` |
| `portal/add_edit_event_user.php:47` |
| `portal/find_appt_popup_user.php:54` |
| `portal/get_patient_info.php:66` |
| `portal/index.php:53` |
| `portal/lib/doc_lib.php:38` |
| `portal/lib/paylib.php:32` |
| `portal/lib/persist.php:27` |
| `portal/lib/track_portal_events.php:27` |
| `portal/messaging/handle_note.php:36` |
| `portal/messaging/messages.php:31` |
| `portal/patient/_machine_config.php:33` |
| `portal/portal_payment.php:38` |
| `portal/questionnaire_render.php:22` |
| `portal/report/pat_ledger.php:23` |
| `portal/report/portal_custom_report.php:47` |
| `portal/report/portal_patient_report.php:44` |
| `portal/report/portal_patient_report.php:61` |
| `portal/sign/assets/signer_modal.php:40` |
| `portal/sign/lib/save-signature.php:39` |
| `portal/sign/lib/show-signature.php:37` |
| `portal/verify_session.php:62` |

## C. Background / system entry points — ALLOW (note)
Run as the system (no interactive user). **Note:** these must be network-restricted
to cron/localhost; the callable safety of the background runner is covered by S6.

| File:line | Purpose |
|---|---|
| `library/ajax/execute_background_services.php:57` | background-service runner (cron/AJAX piggyback) — see S6 |
| `library/MedEx/MedEx_background.php:34` | MedEx messaging background |
| `library/MedEx/MedEx.php:30` | MedEx messaging |
| `interface/modules/custom_modules/oe-module-faxsms/library/rc_sms_notification.php:78` | FaxSMS notification task |

## D. External webhooks — REVIEWED 2026-07-11
No session by design (external callers), so authenticity **must** come from a
verified signature/shared secret. Confirm each validates before trusting input.

| File:line | Review action | Outcome |
|---|---|---|
| `interface/webhooks/payment/rainforest.php:18` | confirm Rainforest webhook signature verification | ✅ **ALLOW** — `Verifier::verify()` runs before any processing (`:40-54`): HMAC-SHA256 over `id.timestamp.body`, constant-time `hash_equals`, 300s replay window, 400 on failure. Tested (`VerifierTest.php`). |
| `portal/portal_payment.rainforest.php:21` | confirm Rainforest webhook signature verification | ✅ **ALLOW (reclassify)** — *not* a webhook; a same-origin portal AJAX endpoint returning payin config. CSRF-gated (`X-CSRF-TOKEN` vs the `rainforest` session subject, 403+exit, `:15-20`) before `globals.php`; token minted only in an authenticated portal session. |
| `interface/modules/custom_modules/oe-module-faxsms/library/webhook_receiver.php:25` | confirm inbound FaxSMS webhook secret/signature check | 🔧 **FIXED 2026-07-11** — was the one finding (no signature/secret verification on a state-changing endpoint). Now verifies the `X-Twilio-Signature` (SignalWire-compat) before any write/fetch, fail-closed. [`SEC-101`](../../tickets/security/SEC-101-signalwire-webhook-signature.md) (`review` — pending live confirmation + merge). |
| `interface/modules/custom_modules/oe-module-faxsms/library/phone-services/voice_webhook.php:29` | confirm FaxSMS voice webhook secret/signature check | ✅ **ALLOW (note)** — token gate (`$_GET['token']` vs session `ringcentral_voice_token`, 403 on empty/mismatch, `:33-39`); fails closed. Hardening notes: uses `!==` not `hash_equals` (timing); session-stored expected token is a questionable model for a sessionless caller. |

## E. Portal pre-auth account flows — REVIEWED 2026-07-11
Part of the *unauthenticated* portal reset/verify flow (patient not yet logged
in), so they cannot rely on a portal session — they must be gated by a
one-time/email token. Confirm the token gate is present and strong.

| File:line | Review action | Outcome |
|---|---|---|
| `portal/account/account.php:33` | confirm token/credential gate for account creation | ✅ **ALLOW** — `$ignoreAuth_onsite_portal` set only when a matching session flag exists (`:27-34`); each action re-checks its flag + CSRF + reCAPTCHA (`:45-70`). |
| `portal/account/index_reset.php:32` | confirm reset-token validation | ✅ **ALLOW** — not an anonymous reset: requires an authenticated portal session (`pid` + `patient_portal_onsite_two`) else destroys session + redirects (`:31-38`); CSRF on POST (`:50`); verifies current password before change (`:76`). |
| `portal/account/verify.php:34` | confirm verification-token validation | ✅ **ALLOW** — self-registration step 1; gated behind the `portal_onsite_two_register` toggle (401 + destroy if off) plus reCAPTCHA + CSRF (`:39-47, 228`). Anonymous by necessity; defense-in-depth present. |

## F. Utility / status AJAX — REVIEWED 2026-07-11
Unauthenticated utility endpoints; confirm each is intended to be reachable
without the staff gate and discloses nothing sensitive.

| File:line | Review action | Outcome |
|---|---|---|
| `library/ajax/sql_server_status.php:18` | confirm no server/info disclosure to anonymous callers | ✅ **ALLOW** — CSRF-gated with subject `sqlupgrade` (obtainable only from the admin `sql_upgrade.php` page, 403 on fail, `:29`), *and* output is regex-scrubbed of all query values before echo (`:47`). |
| `library/ajax/easipro_util.php:28` | confirm intended unauthenticated scope | ✅ **ALLOW (reclassify)** — not anonymous: `$ignoreAuth = true` only on a valid portal session; the staff path uses the core session (`$ignoreAuth = false`); CSRF on POST (`:25-39`). |
| `interface/forms/eye_mag/taskman.php:34` | confirm intended unauthenticated scope | ✅ **ALLOW (reclassify → bucket C)** — `$ignoreAuth` is set **only under CLI** (`php_sapi_name() === 'cli'`, `:33-34`); web access stays staff-authenticated. This is a CLI/cron opt-out, not a web-anonymous endpoint. |

## G. Test / non-production — ALLOW
| File:line | Purpose |
|---|---|
| `interface/modules/custom_modules/oe-module-comlink-telehealth/tests/bootstrap.php:21` | test bootstrap, not a served route |

---

## REVIEW outcomes (completed 2026-07-11)
All ten REVIEW-flagged entries in buckets D/E/F were code-verified against HEAD.
**Nine confirmed adequately controlled** (details in the per-bucket Outcome
columns above); **one finding**:

1. **Webhooks (D):** Rainforest payment webhook ✅ (HMAC `Verifier`);
   `portal_payment.rainforest.php` ✅ (CSRF — not actually a webhook);
   RingCentral voice webhook ✅ (token, fails closed). **SignalWire fax receiver
   — was the one finding (no signature/secret verification); 🔧 fixed 2026-07-11
   via [`SEC-101`](../../tickets/security/SEC-101-signalwire-webhook-signature.md)
   (X-Twilio-Signature verification, fail-closed; `review` pending live
   confirmation + merge).**
2. **Portal pre-auth flows (E):** all three ✅ — session-flag + CSRF + reCAPTCHA
   (account), authenticated-session + current-password (reset), register-toggle
   + reCAPTCHA + CSRF (verify).
3. **Utility endpoints (F):** all three ✅ — CSRF + output scrubbing
   (`sql_server_status`), portal/staff-session + CSRF (`easipro_util`),
   CLI-only opt-out (`eye_mag/taskman`, belongs in bucket C).

Nothing here was removed or changed — this phase only makes the opt-out surface
visible and re-checkable, and the review confirms the alternative controls. The
long-term fix (opt-*out* middleware that is on by default) remains an S4/S11
danger-zone project, not a change made in this pass. `SEC-101` is the one net-new
control the review surfaced.
