# Booking submit feedback

Scope: one member Vue page, two tests, one local build script. No backend or migration. Outputs: /private/tmp/r15-booking-submit-008-*.

Root cause: request.js rejects a structured {status,msg} object; the old handler passed that object as the showToast title. Read-only production evidence shows main detail16711 physical remaining1 and bookable0. No production create endpoint was invoked; the original click response was not captured.

Changes: textual error normalization, safe fallback for missing/internal SQL errors, persistent inline status, in-flight button state and duplicate-click guard; failure releases the guard for manual retry, success retains the original result route. Payload, optional staff, entitlement quota, transaction and backend idempotency remain unchanged. No new business facts, events, statistical timing, caches or data writes. UI locking does not replace backend locking. Comments explain new methods and boundaries. Existing required-field behavior is outside this narrow change.

Actual Vue handler/template tests cover structured/string/error/empty failures, sync errors, retry, duplicate clicks, payload and success navigation. Browser320/375/430 verifies visible persistent status above the button. Occupied-right/grouping and22 remove/payload/race regressions pass. H5 and mini builds and419 native WXML/WXS checks pass. No real-device verification. Awaiting fixed-version product acceptance.
