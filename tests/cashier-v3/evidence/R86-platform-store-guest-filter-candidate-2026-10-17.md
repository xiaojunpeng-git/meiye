# R86 platform/store unified filters: supplementary candidate

## Scope and authorization

- Product manager accepted the original 25-report store candidate and requested this round's commit, platform/store parity, RH deployment, Gitee push, then 008 deployment.
- Product manager explicitly confirmed that guest display is a wanted feature developed in another task. It must remain present.
- Original store-filter commit: `18607d71939092f9ebd8f5f0afd58695675612e3`.
- Accepted/live guest source: `c8c626ab994fc51a5c74d1f238e5f21f40be470c`.
- Separate preservation commit: `8e340d29` (`R86-BASE`), seven files, PHP 7.4 lint and guest contract passed. Guest display and existing controller/export behavior are preserved baseline, not new R86 features.
- Current supplementary candidate base is `8e340d29`; business/test tree is `2e3ca63004362296dc322b6e7249f79ff0342ac8`. Scoped seven-file patch SHA-256: `3e00541fe000f113ce7730fd5358167b2b813ac5321693198b80af4de2c34f00`.
- This supplementary candidate changes platform behavior and adds guest-aware filtering after original acceptance. It requires product-manager supplementary acceptance under AGENTS.md before its commit/release. Existing deployment authorization remains effective; it is not requested again.

## New R86 changes

1. Shared 25 reports use the same backend-declared fixed text/month fields in platform and store routes. Filters start collapsed; numeric, dynamic and editable fields are excluded.
2. Shared platform reports hide the old report-specific filter inputs while preserving the existing organization scope/date controls and hidden drilldown parameters.
3. Admin, cashier and legacy-store controller allowlists pass `row_filters` to the shared service. This closes a gap in the original isolated store commit.
4. Fixed-field name filtering matches the existing guest display projection. A row with explicit `member_id=0` can be found by “游客”; unknown/NULL identity is not guessed. Matching returns the original rows, preserving identity, historical name snapshots and metric values.

## File groups and SHA-256

Frontend source:

- `前端代码/cashier-v3/src/views/StoreBusinessReportView.vue`: `8424449fb116d27b09f984e7f97f514dff3c7880c9acf70d4ffabdc9c218c7b2`.

Backend source:

- `后端代码/app/controller/admin/v1/report/UnifiedReport.php`: `e3b0a1628b363ceb1b17c497b0729a3c6871b1ec62e151e310b9d49f42715d5b`.
- `后端代码/app/controller/cashier/v3/Report.php`: `f36c3d87ca5c22f8966d8710af803532c443148cc118942f29d7f1f052c3a706`.
- `后端代码/app/controller/store/report/UnifiedReport.php`: `618c021d317caeb62b2760278f4de8e28e9c251590f4f96c32b97beddeb568f9`.
- `后端代码/app/services/report/StoreReportResultRowFilterServices.php`: `447150a4d85269c0a3a075715f0cc6c0de48bfca5b586b48bb3e0675dd00353a`.

Automated tests:

- `tests/cashier-v3/js/r86-platform-store-filter-parity-contract.mjs`: `3a968a8a45539bb8b4e0bee1ac4244ef02be4bff21215b64c4fd1fc18dae302a`.
- `tests/cashier-v3/php/report-result-row-filter-contract.php`: `cf0cc08237037f5616c60dec933cb68edc9f15d62c1c008aae2e19a87faac4f3`.

No database migrations or new engineering scripts. Comments were added/updated in the Vue source, the three request controllers and row-filter service to explain permission, fixed-column and guest-display boundaries. Other dirty working-tree changes remain unstaged.

## Verification

- Fixed-tree frontend parity contract passed.
- Fixed-tree Vite build passed: 2,012 modules; prebuild entitlement-experience snapshot passed 70 checks. Existing large-chunk warning remains.
- Fixed-tree PHP 7.4 syntax checks passed for all three controllers; guest and filter contracts passed. Filtering 20,000 rows took 9 ms in the local container (helper microbenchmark only, not full-query latency).
- Guest cases cover numeric/string zero IDs, real member names, missing/NULL identity, both `member_name` and `customer_name`, and preserved original rows/amounts. Hidden historical guest names do not match the visible-name condition.
- Local platform route was checked with default collapsed filters and the existing scope/date controls. Store 126, October 2026: unfiltered partner summary had 6 rows, 10 deal people and 196,668 performance; the “昊美” category condition returned 1 row, 6 people and 181,800. These are local-mirror results, not production acceptance.
- Fixed-tree 25-report local read-only regression passed. Composer's report namespace was explicitly directed to the immutable candidate in the PHP 7.4 container; database host/database were restricted by the test to local `mysql` / `ruihao`, with a read-only transaction and rollback. Six reports had empty local results, so those samples validate metadata/empty behavior rather than real nonempty filtering. Synthetic six-dimension and cross-industry-summary checks additionally passed.

## Build provenance

- Immutable generated build extraction: `/private/tmp/r86-final-jEajIi`, sourced from canonical Git tree `dd9ea5edceb523d3137abc3732d1c12c3245bfa4`. The later candidate tree changes only the PHP helper/test, so frontend source tree and build inputs are unchanged.
- Main entry SHA-256: `86b03e6d9f9efba9f4b34f245b02a4c95732e1444179d2eca0d515784bd50cc5`.
- Main JS `index-1a4e4fff.js`: `cb82b17730667b098d17bd312b2448a3df2a788264e30da9d487a60da8e08016`.
- Main CSS `index-c04e1975.css`: `c04e1975bb4f09b9d6483a46dd3f6cd7431c48cb15db14d2c6ebc5d7ce923e6e`.
- Canonical source HEAD also contains accepted R43 inventory dependency commit `eb286b01`. This is a pre-existing committed dependency, not an R86 change. Its platform-inventory backend is not part of this report release. The cashier shell instantiates `InventoryWorkbench` with `entry-mode="store"`; the changed R43 catalog/detail paths are platform-mode branches. Preserve store-mode behavior in release smoke checks.
- Platform reports reuse the cashier Vue bundle in the existing admin iframe. No admin frontend source change is part of this candidate.

## Read-only production baseline

RH and 008 were separately inspected; shared source/entry fingerprints were rechecked after preparing this candidate. No remote write, service restart, database mutation or push has occurred in this release attempt.

- RH formal and preview, and 008 formal cashier index: `5a4901e602e4b8dd5ec59c06b17b5ea0c9be6a785d8aaa91a3c78e5cee785da6`.
- Existing main JS: `380b3b2c9a3cf20415e4011ec5aa3f30416648175867c924e0a3f6306fbf9f0a`; CSS: `4ad410be8346e3fc48579e1907a9f938af04a025a986dc9abe93977143ef9baa`.
- Both admin controllers: `4bc57b1806b9791709a64f30b41b8b5ef8c78fa9b997cbcbfe9ebbf2b220ce59`; cashier controllers: `eff70a6d79a46cf820266efe9b89b924039e5a25a879d3fa670543b2a16ea45e`; legacy-store controllers: `961aa71cee0f85fb11ca149b1005e946a1f837e2e926e31fcf45b15a8fdbcc6f`.
- Both PhaseSix services: `d820e2b130a390d5aae68e904ac5e140a7a3d3aaadc732c00e9ee48f3ae2f6c3`; guest display helper: `cc4836d77e2a918af96f12ed1cbc3ae9095c705e699e35990015dff72907a0bc`.
- Controllers in the candidate preserve those live versions and only add the R86 parameter allowlist/comment. PhaseSix preserves accepted guest identity fields plus the already-accepted R86 filter changes.
- RH preview deployment is not explicitly authorized in this task. Keep it unchanged and record its version difference after formal deployment. The shared backend will still affect both entries.
- Independent per-instance rollback archives and a final baseline comparison remain required immediately before each authorized switch. RH verification must precede push, and push must precede 008 deployment.
