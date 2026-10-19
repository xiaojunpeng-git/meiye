# M008-R2-20261019-MEDIA Fixed Candidate

## Accepted Business Scope, Pending Fixed-Version Acceptance

The product manager approved preparing the old 1024 picker image records and
17 image categories, excluding video/automatic attachments. They separately
approved skipping missing old attachment 60 and changing only the categories of
existing target attachments 25/26/27 to `蓝色主题`. They explicitly instructed
that duplicate files must not be transferred.

Development and self-tests are complete. **Pending acceptance of this exact
fixed code/data candidate. No material commit, stage, public media publication,
production DB migration or push has occurred.** The already completed 97-person
employee portrait repair is independent and is not included in this acceptance.

## Fixed Version And Files

- Canonical source HEAD: `d3c02480c8ad443263fc4b72b738797d178ab844`.
- Engineering script `scripts/import/customer_008_material_library.php`:
  `1c27da4badaafbe12cea0aff9e66819a1895b2fa74d996f62aea248a6f78f98c`.
- Automated test `tests/customer-008/php/material-library-import.php`:
  `4e686d99ff0531e5eb48ceba2719e668c762bfe12ec99f52ea35b337e7fcbfdd`.
- Patch SHA-256:
  `d3c70a4cf0f4cdafa0e33379d84ea3870ae10437684e653e1234ff0ab7b87491`.
  Method: concatenate `git diff --no-index -- /dev/null <file>` for the script
  then test, SHA-256 the bytes. Evidence documents excluded from this patch hash.
- Private validated report on Meiyue server:
  `/tmp/008-material-library-validated-20261019.json`, SHA-256
  `b559d1d0cea51d2eee9e08242b640a78ccc0deaee5514d0a258f873eeb4c1c9b`.
- Private fixed plan: `/tmp/008-material-library-plan-20261019.json`, SHA-256
  `10e3c2d06ed95eae1dcb3af452a6041a13be98a2b6c23c9c24b37edf61e4a7bf`.
- Canonical plan-object SHA-256:
  `e2c7fc24d5ef9215b317e729431feab8ae465dc94fe7f3d409d7f43567842590`.
- Exact closure file list: script, test, this fixed-version evidence, and
  `2026-10-19-round2-material-library-preparation.md`. Other untracked portrait
  deployment receipts/screenshots and unrelated dirty files are excluded.

## Exact Candidate

- Old `morestore:3306` is read-only, document root
  `/www/wwwroot/morestore.cc3798.com/public`. Only old image-picker scope
  `type=1,relation_id=0,file_type=1,module_type=1` is used: 1024 rows.
- 008 `mohe_008:3307` has 23 attachment rows: 7 picker rows and 16 automatic
  rows; category table is empty. Old video and other 31,334 attachment rows
  are excluded. Source metadata and target whole-table fingerprints are pinned.
- 1024 rows reduce to 957 URL/category objects. All 956 recoverable objects
  passed bounded TLS/image validation; the one truly missing built-in image is
  skipped. Eleven first-pass network failures passed the targeted retry.
- Same-content/same-category dedup yields 926 references, representing 918
  distinct byte hashes. Eight cross-category references are preserved, not
  discarded. Three hashes already exist in 008: ids 25/26/27.
- Create **17 categories and 923 image references**. Reuse those three existing
  rows with a category-only update. Expected final picker count: **930**.
  Actual IDs are generated on 008, never copied from the old primary keys.
- Only **915 distinct new files**, **327,546,038 bytes**, need staging; existing
  target pictures and duplicate hashes are never transferred again. A resumed
  stage verifies completed hashes and skips them. Matching old local bytes are
  preferred over downloading the same CDN object again.
- Existing picture IDs, names, files and decoration references stay unchanged.
  Other four current picker rows and 16 automatic rows are exact-preservation
  assertions. No homepage design, selected image, save or publish action changes.

## Storage, References And Deletion Safety

The imported files use the independent 008 namespace
`public/uploads/import/meiyue-library-20261019/<sha256>.<ext>`. They do not add
deletable picker entries pointing into old shared Qiniu or old-site files.
Metadata references in different categories reuse the same local file, instead
of uploading duplicates or losing category relationships.

Imported files are managed read-only assets: root-owned files mode 0444,
containing namespace directory mode 0555. The 008 application's current master
and inspected worker processes run with UID 1000, not root. A synthetic test
under server `/tmp` verified that UID 1000 can read such a root-owned file but
cannot unlink it; the fixture was cleaned up. Existing local upload deletion
uses `unlink`, with outer attachment deletion catching errors; category/item
references can be removed without physically destroying shared imported media.
Unreferenced imported files are retained until a separately reviewed cleanup,
never automatically deleted while products or decoration may still reference
them. No application-code, service-user or security-permission change is made.

Source files are read only. New asset publication verifies exact bytes, refuses
path escapes, symlinks, mismatched existing objects or non-root ownership.
This task never edits/re-encodes pictures, replaces an existing 008 image,
clears the whole cache or changes storage credentials. Free disk at preparation
was 219,877,736,448 bytes, enough for private stage and published copies.

## Architecture, Backup And Atomicity

Attachment and category tables are the independent 008 picker catalog authority.
Image byte hashes identify assets; content plus category identifies references.
This is asset metadata maintenance: no customer identity, orders, bookings,
money, visits, reporting facts or business/statistical dates change. The old
metadata upload time is preserved on new references; current execution time,
source plan and code version are recorded separately in the audit.

After acceptance and the precise local commit, recheck source, target, runtime
user and fixed plan. Stage only missing immutable content objects privately
under a 0700 directory in
`/www/backups/008.cc3798.com/20261019-round2-material-library/`.
Before any DB change, lock the independent target catalog and create/read back
a 0600 exact snapshot of the current 23 attachment rows and category baseline.
Then publish/seal the accepted assets, map new category IDs, create references,
update only the three approved pids and write the single unique upgrade audit
`20261019-008-old-media-library-r2` in the same MySQL transaction.

The audit's real `result_note varchar(500)` is checked and compact; detailed
mapping and snapshot hash go into private receipts. An error rolls back all
metadata/categories/audit. Already published immutable content may remain
unreferenced after rollback; it is not retransferred or blindly deleted on
retry. A receipt alone is not success: read back audit/data, original-row
invariants, mapped category paths and public asset bytes, then verify the
actual 008 image picker without saving/publishing any decoration.

Rollback after successful use requires a separately checked compensating plan:
do not restore a whole DB or delete files still used by subsequent decoration
or product edits. Source/target drift blocks execution rather than widening
scope. No non-target instance, shared server service or application bundle is
deployed. This acceptance request includes only 008 material migration, not push.

## Tests And Evidence

- Host PHP and server PHP7.4 syntax/entry loading: PASS.
- Planner tests: exact content/category dedup, existing-row reuse, cross-category
  reference retention, missing/cyclic parents, wrong scope, unapproved missing
  objects and ambiguous existing content rejection: PASS.
- Disposable MySQL5.7 synthetic fixture: real inserts and pid update, remapped
  nonmatching auto IDs, exact rollback, concurrent target drift rejection,
  injected audit failure after catalog writes causing full rollback, replay
  rejection, preservation of all other original columns/automatic records,
  reused staged/public files and readonly shared-content unlink protection: PASS.
- Same-content references share two files across three new fixture records;
  an already published exact hash is reused after rollback rather than copied
  again. No real customer data entered the local test DB.
- Production `check` mode: PASS, plan object `e2c7fc24...`, 17 categories,
  923 new / 3 reused references, 915 distinct new files, skip only id 60.
  This mode publishes no media and performs no INSERT/UPDATE.
- `git diff --check`: PASS. Disposable test database was dropped and the
  originally stopped test container returned to stopped state.
- New business-boundary comments are in the engineering script and test.
- Frontend/backend application source and schema migrations: none. Engineering
  script: one PHP. Automated test: one PHP. Evidence: two Markdown files.
  Generated artifacts: private report/plan only; no staged or public image
  payload yet. No source copy or credentials are stored in Git evidence.
