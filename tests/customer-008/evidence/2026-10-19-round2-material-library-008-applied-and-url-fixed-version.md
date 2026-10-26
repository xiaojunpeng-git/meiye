# M008-R2-20261019-MEDIA Import Result And URL Supplement

## Accepted Import: Committed And Applied, UI Validation Not Passed

The product manager explicitly accepted patch `d3c70a4c` and authorized the
precise commit, independent rollback snapshot and production import to 008.
Local commit: `9595d5197895fa05ed07a92ad02d9ba6bf530c8a`, message
`M008-R2-20261019-MEDIA 素材库去重迁移闭环`. Exactly four files: original
script, original test, fixed-version evidence and preparation evidence. No push.

The committed script was streamed from Git, SHA-256 checked before execution.
008 migration baseline was id 39, `20261018-001-store-service-name-master`.
Member `public/index.html`, platform `public/system.html`, attachment services,
`app/common.php` and `.env` were fingerprinted; they remain unchanged. The first
preflight attempt stopped on a nonexistent guessed platform entry, before any
staging or metadata update; the actual entry was checked and then used. No
accepted source was changed. Canonical HEAD remains `9595d519`; unrelated dirty
work was excluded from staging and remains untouched.

Only `mohe_008:3307` was updated. Source `morestore:3306` used a READ ONLY
transaction. Ruihao, 007, 012 and old Meiyue were not changed. Service
`mohe-008-swoole.service` was active, User/Group `www`, MainPID 13431; its
inspected workers run UID 1000. No service restart, application deployment,
global cache flush, decoration save/publish or configuration update occurred.

At `2026-10-19T18:24:45+08:00`, the atomic import committed:

- 923 new image references and 17 categories; existing IDs 25/26/27 changed
  category only. The other four picker rows and 16 automatic rows are unchanged.
- 915 distinct new files, 327,546,038 bytes. Duplicate content was staged once;
  three current picture files were never retransferred. Missing old id 60 skipped.
- Final attachment rows 946; picker images 930; category rows 17.
- Upgrade audit id 40, `20261019-008-old-media-library-r2`, code version
  `9595d5197895fa05ed07a92ad02d9ba6bf530c8a`.
- All 915 published file hashes match the fixed plan. Files root-owned 0444,
  namespace directory root-owned 0555; imported bytes were not re-encoded.
- Decoration whole-table hash and all recorded runtime/config hashes unchanged.

Private rollback directory (0700):
`/www/backups/008.cc3798.com/20261019-round2-material-library/`.
It contains accepted plan, preflight, private stage, exact before snapshot,
receipt and post-commit readback verification. Snapshot and receipts are 0600.

- `before.json` SHA-256:
  `70729de73b35057562729ac83e729a7ff90c38f5c9d01553171024381ec11c60`.
- `receipt.json` SHA-256:
  `7c9076f119fb96f21e290fe8c02043b2d36c6d5719b73676e89f3942c272a5f5`.
- Canonical plan SHA-256:
  `e2c7fc24d5ef9215b317e729431feab8ae465dc94fe7f3d409d7f43567842590`.

## Actual Browser Failure And Root Cause

The real 008 image picker shows 930 records and restored categories; page 2
loads metadata and `蓝色主题` shows 51 records. But new thumbnails display the
fallback image, so the material migration is **not yet business-usable**.
No image was selected and no decoration was saved or published.

Read-only DB evidence: existing `site_url` is
`https://008.cc3798.com/admin`. Existing attachment service prefixes local paths
with this value. The new stored `/uploads/import/...` paths therefore become
`https://008.cc3798.com/admin/uploads/import/...`, which serves HTML rather
than image bytes. The correct root URL returns HTTP 200 with exact accepted
image hash. Original images use absolute URLs and bypass this bug.

Screenshot: `2026-10-19-round2-material-library-url-prefix.jpg`. It records the
actual placeholder thumbnails and 930 count, not a fabricated passing preview.
The temporary standalone picker tab does not reload the user's unsaved DIY tab.

## Fixed URL-Only Supplement: Pending Product Manager Acceptance

Do not change global `site_url`: that would affect other features. Instead,
update **only att_dir and satt_dir of the 923 newly inserted records** to
`https://008.cc3798.com` plus their existing content-addressed path. No new
files or references, no category edits, no changes to original 23 rows, no
retransfer or decoration update. Original import script remains unchanged.

- Baseline HEAD: `9595d5197895fa05ed07a92ad02d9ba6bf530c8a`.
- New script `scripts/import/customer_008_material_library_urls.php` SHA-256:
  `2a92d16e3783d9cd6c1ac975ca13d1fbb215ed57811505c2e821b7962ab75306`.
- New test `tests/customer-008/php/material-library-urls.php` SHA-256:
  `e2b28a5dc4a224bf356db68f4223b16ae2c3aa1684f5db32f9a2e3390b2d4160`.
- Patch SHA-256:
  `80faaadb3d6817b2d8e12436b936c9e64a4691e038f1647ceee38f49aa228df6`.
  Concatenated `git diff --no-index -- /dev/null <file>` bytes, script then test.
- Supplement closure files: the new script, new test and this evidence document.
  Screenshot is generated evidence, not business source. No frontend, backend
  application source or schema migration changes. No second editable source.
- Current complete attachment hash:
  `1ed7cfbf6231d5c1c81e7ffe769623bf5285db39e949f862e42b9cca9349f34b`.
- Category hash:
  `e2784f44d8211334b81b77f6947adcd07a854bfce437e62a6bac632c2b4dd4dc`.
- Exact ID/path map hash:
  `e787f755373de3ba7c060a3a22289ef7ad041c677f9ce0c2141b09a349687997`.

The supplement pins the original immutable receipts and all post-import catalog
metadata, verifies 915 file hashes, locks the catalog, saves an independent
`absolute-urls/before-urls.json`, compares/sets the 923 old paths and writes the
unique upgrade marker `20261019-008-media-absolute-urls-r2` in one transaction.
It asserts all other columns and categories unchanged after real writes. Audit
failure rolls back; replay or drift stops execution. No old-source connection
is needed. Comments explain URL scope, snapshot protection and atomicity.

## Supplement Self-Tests

- Local pure test: absolute same-origin path, real attachment-service prefix
  expression under site_url `/admin`, path traversal/other-host/extension guards:
  PASS.
- Real isolated MySQL5.7: actual URL writes, cross-category shared file,
  existing/automatic row and all non-URL field preservation, unchanged category
  mappings, concurrent user edit refusal before snapshot, injected audit failure
  rollback, immutable backup readback and replay refusal: PASS.
- Original material MySQL5.7 dedup/mapping/rollback/audit/shared-file regression:
  PASS. Synthetic test DBs dropped; originally stopped container restored stopped.
- Server PHP7.4 fixed-candidate `check`: PASS; 923 rows, zero file transfers,
  zero existing-row/category changes, exact three metadata hashes above.
  This was read-only and did not run `apply`.
- `git diff --check`: PASS.

Development and self-tests are complete. Supplement has **not been accepted,
committed or applied**. After exact-version acceptance and local commit, apply
only the URL supplement to 008 under the separately requested authorization,
then verify real picker thumbnails/selection URLs and independent image bytes.
Do not declare business completion based on metadata counts or HTTP 200 alone.
No push is authorized.
