# SEC-101 · SignalWire fax webhook has no authenticity verification

| Field | Value |
|---|---|
| **Audit ref** | net-new — surfaced during the S4 REVIEW pass (bucket D), not in AUDIT.md |
| **Severity** | Med |
| **HIPAA nexus** | §164.312(c)(1) integrity · §164.312(d) person/entity authentication |
| **State** | blocked (founder sign-off — this is an auth/authenticity control) |
| **Wave** | — (post-S4-review) |
| **Depends on** | — |
| **Sign-off required** | YES — adds an authentication control on a live webhook; founder owns auth-surface calls (`CLAUDE.md` bright lines) |
| **Suggested worktree** | `sec-101` |
| **Files touched** | `interface/modules/custom_modules/oe-module-faxsms/library/webhook_receiver.php`, `interface/modules/custom_modules/oe-module-faxsms/src/Utils/SignalWireWebhookValidator.php` (new signature method), plus a new unit test |
| **Upstreamable?** | yes → PR to `openemr/openemr` (the module ships upstream); the Rainforest `Verifier` is the in-repo precedent |

## Problem

`interface/modules/custom_modules/oe-module-faxsms/library/webhook_receiver.php`
is a directly-served inbound webhook (its URL is handed to SignalWire; there is
no wrapping router). It opts out of the staff gate with `$ignoreAuth = true`
(`:25`) and then processes attacker-reachable input with **no signature or
shared-secret verification anywhere in the module.**

- Confirmed: the imported `SignalWireWebhookValidator` has only
  input-sanitization / SSRF-URL methods (`validateFaxId`, `validateFaxStatus`,
  `isValidSignalWireUrl`, …) — **no signature method exists**
  (`src/Utils/SignalWireWebhookValidator.php:26-114`).
- Confirmed: a module-wide grep for `hash_hmac` / `signature` / `signing` /
  `X-*-Signature` finds nothing. Authenticity rests solely on knowing the URL
  and a valid `site` id.

Because the endpoint is state-changing, an anonymous caller who knows the URL
and a `site` id can:

1. **Forge inbound-fax records.** POST arbitrary (sanitized but attacker-chosen)
   `From`/`To`/`Status`/`FaxSid` → INSERT/UPDATE rows in `oe_faxsms_queue`
   (`:245-269`). Enables sender-number spoofing and queue pollution / DoS.
2. **Inject a document onto a patient chart.** With
   `Direction=inbound, Status=received, MediaUrl=<signalwire-hosted url>`, the
   handler calls `downloadAndStoreFaxMedia()` (`:272-273`), which fetches the
   URL **using the practice's stored SignalWire API token** and stores the
   result as a document, matched to a patient by phone number
   (`:120-133`). The SSRF allow-list (`isValidSignalWireUrl`) limits the fetch
   *host*, but content hosted on a SignalWire-matching URL would be stored
   against a chart with a forged provenance.

CWE-345 (Insufficient Verification of Data Authenticity) / CWE-306 (Missing
Authentication for a Critical Function).

**In-repo precedent that does this correctly:** the Rainforest payment webhook
(`interface/webhooks/payment/rainforest.php:40-54`) builds a
`Verifier` and calls `verify()` — HMAC-SHA256 over `id.timestamp.body`,
constant-time `hash_equals`, replay-tolerance window — *before* any processing,
and 400s on failure. That is the exact shape this ticket should mirror
(`src/PaymentProcessing/Rainforest/Webhooks/Verifier.php`).

> The RingCentral voice webhook in the same module
> (`library/phone-services/voice_webhook.php:33-39`) already gates on a token
> and fails closed — this ticket brings the SignalWire fax path up to that bar
> (and ideally to the Rainforest HMAC bar).

## Acceptance criteria

- [ ] The webhook rejects (HTTP 4xx, no DB write, no media fetch) any request
      whose SignalWire signature is missing or does not verify against the
      configured signing key, **before** any `oe_faxsms_queue` write or
      `downloadAndStoreFaxMedia()` call.
- [ ] Verification is constant-time (`hash_equals`), mirroring the Rainforest
      `Verifier` — no early-return optimization on the compare.
- [ ] A validly-signed request continues to process exactly as today (no
      behavior change on the happy path).
- [ ] The signing secret is read from stored config/credentials (the same
      `module_faxsms_credentials` surface already used for the API token), never
      hard-coded, and is a `#[SensitiveParameter]`.
- [ ] SignalWire's validation-ping / empty-body handshake still returns 200
      (don't break provisioning — see the existing `:214-222` path), but only
      via a path that cannot be abused to write data.

## Implementation sketch

1. Add `SignalWireWebhookValidator::verifySignature(string $rawBody, array
   $headers, string $signingKey): bool` — HMAC per SignalWire's documented
   scheme (Twilio-compatible `X-Twilio-Signature` for the compatibility API, or
   the SignalWire messaging signature; confirm which the fax product emits),
   constant-time compare. Model the method on `Verifier::verify()`.
2. In `webhook_receiver.php`, immediately after `$rawInput` is captured
   (`:38`) and the signing key is loaded, call the verifier and `http_response_code(403); exit;`
   on failure — before the `$_POST` mapping and all DB work. Annotate with
   `// SEC-101: verify webhook authenticity before trusting input`.
3. Store/read the signing key alongside the existing SignalWire credentials;
   add a setup note in the module docs.

## Test plan

- New unit test for `verifySignature()`: valid signature → true; tampered body →
  false; missing header → false; wrong key → false; constant-time path exercised.
- Because `webhook_receiver.php` is a procedural entry point (hard to unit-test
  directly), keep the security logic in the testable `verifySignature()` method
  and cover the entry point with a live smoke against the dev stack (documented,
  not gated).
- `openemr-cmd ut` green; `openemr-cmd pst` L10 clean (module is analyzed, NOT
  baselined — write it L10-clean); `openemr-cmd pr` phpcs clean.

## Definition of done

- [ ] Acceptance criteria met
- [ ] Inline `// SEC-101:` comment marks the change
- [ ] `openemr-cmd ut` green in the worktree
- [ ] `openemr-cmd pst` (PHPStan L10) clean — no new baseline entries
- [ ] `openemr-cmd pr` (phpcs) clean
- [ ] Conventional-commit message (`fix(security): verify SignalWire fax webhook signature`)
- [ ] Re-verified in the main session (subagent "done" is a claim until re-run)

## Dispatch brief
> Copy the block below verbatim into the swarm agent's prompt.

```
Add SignalWire webhook signature verification to the FaxSMS module. Context:
interface/modules/custom_modules/oe-module-faxsms/library/webhook_receiver.php
is a directly-served inbound fax webhook that sets `$ignoreAuth = true` (line 25)
and processes $_POST/$_GET into oe_faxsms_queue writes (lines 245-269) and a
token-authenticated media fetch + document store (downloadAndStoreFaxMedia,
lines 120-133, 272-273) — with NO signature/secret check anywhere in the module.

Fix (mirror the working precedent at src/PaymentProcessing/Rainforest/Webhooks/
Verifier.php, which the Rainforest webhook at interface/webhooks/payment/
rainforest.php:40-54 uses correctly):
1. Add SignalWireWebhookValidator::verifySignature(string $rawBody, array
   $headers, #[SensitiveParameter] string $signingKey): bool in
   interface/modules/custom_modules/oe-module-faxsms/src/Utils/
   SignalWireWebhookValidator.php — HMAC per SignalWire's documented signing
   scheme, constant-time hash_equals, NO early return on the compare.
2. In webhook_receiver.php, right after $rawInput is read (line 38) and the
   signing key is loaded from the existing module_faxsms_credentials surface,
   verify and `http_response_code(403); exit;` on failure BEFORE any $_POST
   mapping or DB write. Comment: `// SEC-101: verify webhook authenticity before
   trusting input`. Preserve the existing empty-body validation-ping 200 path
   (lines 214-222) but ensure it cannot write data.

Constraints (HARD): this is an auth/authenticity control on a live webhook —
do NOT change any other auth behavior, do NOT touch core files or globals.php,
do NOT weaken the existing SSRF allow-list. The module is CI-PHPStan-analyzed
and NOT baselined, so write L10-clean. Custom ForbiddenCatchTypeRule: catch
(\Throwable) and catch (\Exception) are forbidden unless the body ends in an
unconditional `throw;`. Add a unit test for verifySignature (valid/tampered/
missing-header/wrong-key). Run: openemr-cmd ut, pst, pr — all green. Report what
changed, what you tested, and anything unverified.
```
