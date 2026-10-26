# Mobile Auth Route Contract Plan

## Scope

This is a planning-only contract test artifact. It reads no production PHP,
opens no network connection, and does not authorize route or service changes.
The route source reviewed for this plan is
`后端代码/route/api-mobile.php`.

## Current Route State

The reviewed route file currently registers only the following merchant
customer and customer-care endpoints under `/api/mobile/merchant`:

- `POST /customers/query`
- `POST /customers/exclusive/query`
- `GET /customer-audiences`
- `POST /customer-audiences`
- `PATCH /customer-audiences/:audienceId`
- `DELETE /customer-audiences/:audienceId`
- `POST /customer-care/workbench`
- `POST /customer-care/actions/:action`

None of the eight routes in the registration matrix below is currently
registered. Existing customer routes are outside this plan and must not be
changed as part of mobile authentication delivery.

## Registration Matrix

| Priority | Method | Required path | Contract endpoint | Metadata profile | Required request body |
| --- | --- | --- | --- | --- | --- |
| P0 | POST | `/api/mobile/auth/captcha/challenges` | `createCaptchaChallenge` | `PUBLIC` | Not frozen by `mobile-auth-v1` |
| P0 | POST | `/api/mobile/auth/captcha/verify` | `verifyCaptchaChallenge` | `PUBLIC` | Not frozen by `mobile-auth-v1` |
| P0 | POST | `/api/mobile/auth/sms/challenges` | `createSmsChallenge` | `PUBLIC` | `phone`, `purpose`, `deviceId`, `captchaProof`, `idempotencyKey` |
| P0 | POST | `/api/mobile/auth/sms/verify` | `verifySmsChallenge` | `PUBLIC` | `challengeId`, `code`, `idempotencyKey` |
| P1 | POST | `/api/mobile/merchant/session` | `createMerchantSession` | `APP_SESSION_EXCHANGE` | No body fields frozen |
| P1 | GET | `/api/mobile/merchant/bootstrap` | `bootstrap` | `MERCHANT_SESSION` | None |
| P1 | POST | `/api/mobile/merchant/context/switch` | `switchContext` | `MERCHANT_SESSION` | `targetContextId`, `expectedAuthVersion`, `idempotencyKey` |
| P1 | POST | `/api/mobile/merchant/logout` | `logout` | `MERCHANT_SESSION` | `idempotencyKey` |

P0 is the closed phone-verification chain. P1 may only be delivered after a
P0 verification result can provide the app session required by
`APP_SESSION_EXCHANGE`.

## Header Matrix

Every metadata profile requires these four headers with non-empty values:

```text
X-Mobile-Contract-Version: the profile contract version
X-Mobile-Client-Session-Id: clientSessionId
X-Mobile-Platform: platform
X-Mobile-Request-Id: requestId
```

`PUBLIC` uses `mobile-auth-v1` and must reject `Authorization`,
`X-Mobile-App-Session`, `Cookie`, `queryToken`, `bodyToken`, and
`headerAlias`.

`APP_SESSION_EXCHANGE` uses `mobile-merchant-v1`; it additionally requires
`X-Mobile-App-Session` and rejects `Authorization`,
`X-Mobile-Active-Context-Id`, `X-Mobile-State-Context-Id`, `Cookie`,
`queryToken`, `bodyToken`, and `headerAlias`.

`MERCHANT_SESSION` uses `mobile-merchant-v1`; it additionally requires
`Authorization: Bearer <merchantToken>`, `X-Mobile-Active-Context-Id`, and
`X-Mobile-State-Context-Id`. It rejects `X-Mobile-App-Session`, `Cookie`,
`queryToken`, `bodyToken`, and `headerAlias`.

Both merchant profiles disallow empty context values. Route tests must verify
the exact header presence and forbidden-alias behavior before testing any
controller result.

## Success Envelopes

| Endpoint | Required successful response fields |
| --- | --- |
| `createSmsChallenge` | `contractVersion`, `challengeId`, `maskedPhone`, `expiresAt`, `resendAfterSeconds`; must not expose `code`, `verificationCode`, `providerPayload`, or `resendAfter` |
| `verifySmsChallenge` | `contractVersion`, `appSession`, `phoneVerification`, `availableModes`; `appSession` contains `accessToken`, `expiresAt`; `phoneVerification` contains `status`, `maskedPhone`, `verifiedAt`, `method`, `verificationVersion` |
| `createMerchantSession`, `bootstrap`, `switchContext` | Full `merchantRoot`: `contractVersion`, `merchantToken`, `expiresAt`, `employee`, `authVersion`, `activeContextId`, `activeContext`, `contexts`, `capabilities`, `dataScope`, `availableActions`, `reasonCode`, `stateContextId`, `stateRevision` |

`createCaptchaChallenge`, `verifyCaptchaChallenge`, and `logout` do not yet
have a frozen successful response field set. Their route implementation and
positive-path tests remain blocked until the mobile-auth/merchant contract
defines those envelopes. Do not reuse a legacy response shape or invent a
generic success payload.

## Failure Envelopes And Error Priority

Business failures must contain exactly the frozen business envelope fields:

```text
contractVersion, requestId, errorCode, message
```

Protocol failures must contain exactly the frozen protocol envelope fields:

```text
contractVersion, requestId, protocolCode, fieldPath, message
```

The implementation test order and error selection priority are:

1. Route and request protocol: unknown path or wrong HTTP method returns
   `ROUTE_NOT_FOUND`; unsupported media type returns `UNSUPPORTED_MEDIA_TYPE`.
2. Version and metadata validation: missing, malformed, unsupported, or
   forbidden credential headers return the applicable protocol result in this
   order: `CONTRACT_HEADER_INVALID`, `CREDENTIAL_CONFLICT`, then
   `CREDENTIAL_FORBIDDEN`. Reject before any credential lookup.
3. Idempotency and captcha proof validation: reject malformed request fields
   with `INVALID_REQUEST_FIELD`; reject duplicate keys with
   `IDEMPOTENCY_KEY_CONFLICT`; reject stale or invalid captcha proof with
   `CAPTCHA_PROOF_EXPIRED` or `CAPTCHA_PROOF_INVALID` before issuing an SMS.
4. Credential ownership and liveness: reject absent/invalid credentials with
   `AUTH_REQUIRED`; reject a token/client-session mismatch with
   `TOKEN_OWNER_MISMATCH`; reject changed authorization version with
   `AUTH_VERSION_CHANGED`; reject expired merchant session with
   `MERCHANT_SESSION_EXPIRED`.
5. Phone verification and merchant eligibility: after app-session validation,
   return `PHONE_VERIFICATION_REQUIRED`, then evaluate
   `LEGACY_PHONE_CONFLICT`, `EMPLOYEE_NOT_FOUND`, `EMPLOYEE_DISABLED`,
   `NO_VALID_ASSIGNMENT`, `MOBILE_ENTRY_DISABLED`, `MOBILE_JOB_FUNCTION_MISSING`,
   `STORE_NOT_ALLOWED`, and `STORE_DISABLED`.
6. Merchant context integrity: only after a valid, eligible merchant session,
   validate context headers/body and return `ACTIVE_CONTEXT_MISMATCH` for stale
   or foreign active context.

When more than one condition is invalid, the highest applicable item above
wins. Tests must prove that lower-priority checks do not leak employee,
assignment, store, or context information before credential validation.

## Static Test Cases For Implementation

1. Parse `mobile-auth-v1.contract.json`, `mobile-merchant-v1.contract.json`,
   and `mobile-api-errors-v1.json`; require all eight paths, metadata profiles,
   request fields, frozen success fields, failure envelope fields, and listed
   error codes to remain unchanged.
2. Read `route/api-mobile.php` as text only and require each matrix route once,
   with its declared HTTP method and its intended mobile controller action.
3. Require the P0 route group to carry only public-safe middleware and require
   the P1 group to use the new mobile session middleware. Existing legacy
   merchant token middleware must not be accepted as an alias.
4. For each route, exercise only a fake request adapter or controller boundary
   supplied by the implementation test fixture. No test may send SMS, call a
   captcha provider, access a remote database, or accept a real user token.
5. Assert all error cases against the two frozen envelopes and execute
   mixed-invalid-input cases in the priority order above.
6. For `verifySmsChallenge`, assert that a successful response has no
   merchant token. For merchant-root endpoints, assert an opaque non-empty
   merchant token, positive auth version, non-empty state context, a numeric
   `stateRevision` beginning at `1` for a new state context, and a non-empty
   authorized data scope.

## Exit Criteria

This plan is replaced by executable static route-contract tests only when all
eight route registrations exist and the three currently unfrozen success
envelopes are first added to their authoritative JSON contracts. Until then,
the correct state is `ROUTE_IMPLEMENTATION_PENDING`, not a passing claim for
mobile authentication.
