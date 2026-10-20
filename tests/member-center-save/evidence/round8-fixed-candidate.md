# M008-R8-001 Personal Center Menu Recovery

## Status and Baseline

- Development and local self-tests complete; awaiting product-manager acceptance.
- Canonical source HEAD: `ba6d2f52ae7d3bb1a249c0aa1c2109f30635b3a3`.
- Four changed business files were clean at task start. Unrelated staged and working-tree changes were not modified or staged.
- No commit, push, deployment, mini-program upload or production data write has occurred.
- Production target for subsequent authorized recovery: 008 only. Deployment version/fingerprints and a fresh independent backup must be captured before any release; no remote shell connection was made in this closure.

## Root Cause and Evidence

- The 2026-10-19 accepted configuration installed 14 services into the DIY member document; legacy menu/banner groups were intentionally not created. See `tests/customer-008/evidence/2026-10-19-member-service-menu-008-config-copy.md`.
- The editor unconditionally queried those missing groups, failing its GET. Its default empty preview state remained saveable.
- The 2026-10-20 save wrote the DIY document before the group write failed, leaving empty menus despite reporting failure. Existing evidence is `tests/member-center-save/evidence/008-save-check.json`; it records a partial write, two missing groups and an unrelated log-permission error masking the original exception.
- Round-8 read-only checks reproduced the admin load error, H5/mini empty services, and `/api/menu/user` returning `menu.is_show=1` with `menu.list=[]`.
- Local MySQL regression also demonstrated that querying absent group IDs could resolve to unrelated gid=0 rows. The two personal-center legacy response fields now explicitly return empty when their definitions are absent.

## New Repairs

- Missing legacy groups no longer block editor reads: existing DIY lists provide the editor-compatible image/list shape. No group is synthesized, and gid=0 is never written.
- Existing group-backed installations retain their old read/write compatibility.
- DIY document and any installed legacy mirrors save in one transaction; failures roll back all three writes. DIY cache invalidation occurs after successful transaction completion.
- Editor request promises propagate loading failures; Save is disabled until a successful load. In-flight duplicate submission is blocked and failure resets the button state.
- Icon-only services retain their existing icons without requiring an uploaded image; image arrays and image URL strings both round-trip intact.

## Existing Functionality to Preserve / Restore

- The 14 service definitions are an existing accepted feature, not a new menu design. Their names, ordering, relative routes and icon classes remain unchanged, with no test entry.
- Accepted fixture SHA-256, excluding its final newline: `9bd2214a50dea15361b4b4c73df005c9b93b56f98a0ba2704db74f67d5ebf0cf`. It matches `/private/tmp/mohe-008-service-menu-20261019/008-menu.json` exactly.
- Existing balance, membership, invoice and promoter feature/qualification filters are unchanged. The local test configuration returns 13 visible entries; this is not a claim that production should show 13. Production retains its own flags and identity-dependent filtering.
- Order-center customization, member assets, customer rights, payment, coupons, points, theme and old merchant-menu suppression remain unchanged.
- The phone-only project-tab blank report is separate: the existing simulator package retrieved 15 records and displayed 14 valid projects. This closure does not claim physical-device project rendering has been fixed.

## Tests and Limits

- `editor.cjs`: failed-load save guard, icon-only save, string/array image normalization, duplicate click, rejected-save reset; PASS.
- `mysql.php`: real local MySQL 5.7.44, temporary shadow tables and isolated Redis prefix; missing groups, 14-entry save/refresh, real member API, gid=0 read/write isolation, existing mirrors, injected second-mirror failure rollback, explicit empty lists and uninitialized-document rejection; PASS. Temporary tables and test cache keys removed.
- `browser.cjs`: real canonical HBuilderX H5 at 8081, isolated headless Chrome, local generated GET response, no real credentials/business writes. 375/320 widths and refresh preserve menu/icon counts; PASS. Screenshots contain only the service section.
- `mini.cjs`: existing 008 package AppID `wx85d4bfd0b4b6aafb`, local service-generated projection injected into the current simulator page; menus and icons visibly rendered, then original projection restored. No persistent configuration change. This is not physical-device acceptance or online recovery.
- Both PHP files lint and the actual Vue2 editor template compiles. No new member frontend source or mini-program build is needed for this defect.
- No full platform release bundle has yet been built. A release must use a fixed accepted commit and reconcile the target's current shared-file baseline before deployment; other unaccepted work must not be bundled.
- Existing root-owned production error-log permissions have not been changed. They may still mask unrelated errors and require separately authorized runtime maintenance if encountered at release.

## Data Architecture Check

- Authority: personal-center DIY document; optional legacy group rows are compatibility mirrors, not new business facts. No member/rights/order/financial data is read for repair or written by the patch.
- Granularity/event/time: one editor configuration save; existing update timestamp retained. No sale, payment, balance, writeoff, performance event or report change.
- Transaction/retry: all config writes commit/rollback together. Resaving the same content does not append business data. Existing last-save-wins editor behavior is unchanged; this closure does not introduce cross-editor optimistic locking.
- Permissions: existing admin route authorization and member endpoint qualification filters remain in place; no new permissions, client-supplied customer scope or merchant entry.
- Recovery/audit: restore only the accepted service list from the proven 008 record, preserving all other current document fields and switches; back up and compare raw document hashes immediately before/inside the write transaction. No cross-customer query or copying is needed.
- Index/performance: two bounded group-definition checks on the public menu endpoint; existing template/group lookup paths retained. No migration, fact backfill, reporting aggregation or schema change.

## Pending Authorized Online Recovery

1. Product manager accepts the fixed version and authorizes 008 deployment plus restoration of its original 14 services.
2. Precisely commit this closure; re-check source and 008 shared-file baselines. Build only a traceable standard release retaining all accepted online functionality; stop for supplemental acceptance if material baseline changes occur.
3. Independently back up affected code/resources and the current member/type=3 row, record fingerprints and rollback points. Only replace `menu.list` with the accepted fixture list; keep current member style, assets, order configuration and other fields byte/semantically intact.
4. Apply guarded, transaction-locked configuration recovery and the accepted code; invalidate only the affected 008 DIY cache key. Do not modify unrelated gid=0 records, synthesize groups or clear other clients' caches.
5. Verify authenticated H5/mini menus, qualification filtering, icon rendering, admin load/save/refresh and failure safety. No real purchase/recharge/writeoff is needed. No push or mini-program upload unless separately requested.

## File Groups

- Frontend: two platform editor files; no member or cashier frontend changes.
- Backend: DIY service and public personal-center menu controller.
- Database migrations: none. Production configuration recovery is prepared, not executed.
- Tests: four regression runners, the exact accepted fixture, and the verification runner.
- Generated evidence: H5 service-section screenshots, H5/mini result JSON and fixed-version manifest. Historical evidence was not rewritten.
- Responsibility/constraint comments were added or updated in all four changed business files.
