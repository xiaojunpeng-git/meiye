# M008-R2-20261019 Employee Portrait Restoration

## Scope And Current Status

The product manager authorized restoring old Meiyue portraits to 008 employees
matched uniquely by phone, only when the existing portrait is empty or a known
built-in placeholder. Manually uploaded portraits, identity, accounts, job
assignments, permissions and all business data must remain unchanged.

Development, isolated MySQL 5.7 tests and production read-only checks passed.
**Pending product-manager acceptance of this fixed candidate. No local commit,
production data update, application deployment or push has occurred.**

## Fixed Candidate

- Canonical repository: `美容源码/`, HEAD `09ed19deefe53fe8b886bb18ee9c97833eadb051`.
- Only two new source/test files belong to this closure. Other existing dirty
  workspace files and the untracked round-1 release receipts are excluded.
- `scripts/import/customer_008_staff_avatars.php` SHA-256:
  `75a16df0fa5aa05eacdd0a0a5fb8876dce1d950d54730346709e387833583af0`.
- `tests/customer-008/php/staff-avatar-repair.php` SHA-256:
  `75ffbd7c7e029e9454b6fa5f38273cd27550e6010b4b5a0a30c2872878211917`.
- Patch SHA-256: `15fbf96dc4576a1c0e8f65197c19756fea52ab89485fed32af8e8951ff4cd36d`.
  Method: concatenate `git diff --no-index -- /dev/null <file>` bytes in the
  source/test order above, then SHA-256. This excludes the evidence document.

## Read-Only Production Findings

- Source: old Meiyue `morestore:3306`, only `eb_user group_id=7`, read-only
  database transaction. 101 source technicians currently exist.
- Target: `008.cc3798.com`, independent `mohe_008:3307`. Last schema record:
  id 39, `20261018-001-store-service-name-master`, code
  `5e3f84008c928d97f72492d52bc729d59ad11cfe`.
- 98 unique employee matches; 3 unmatched records are excluded. One matched
  employee is outside the Meiyue organization and is also excluded.
- Final candidate: **97 employee portraits and 97 active store-staff projections**.
- All 97 selected pictures were fetched over verified HTTPS with bounded size,
  no redirects and decoded raster-image validation. 96 use the existing Meiyue
  Qiniu CDN and one uses the WeChat avatar CDN. Total checked bytes: 9,500,050.
  008 already uses the existing Meiyue bucket; no bucket, key or upload settings
  are copied or changed in this task.
- The reported live default portrait corresponds to employee 734 / staff 701.
  Its replacement is an 800x800 JPEG, 139,110 bytes, image SHA-256
  `12b900f00f7e6bda97465997ab36ab1e407dbcf76d41d06dafd44b91fba60587`.
  Another employee with the same name is not matched by name and is not selected.
- Private plan, mode 0600: `/tmp/008-staff-avatar-plan-20261019.json` on the
  Meiyue server. File SHA-256:
  `446a15725c74f146bf6a175de54347d444708de0ae5d090df9e734fa3b41b495`.
- Canonical plan-object SHA-256:
  `d7999b9d29010bfd36c25403c5fedc4dac9be836eeeedc55b2e87cc1ffebeab9`.
  Plan contains no phone numbers, passwords or authentication tokens. Portrait
  URLs and internal IDs remain private on the server rather than entering Git.
- Relevant live PHP files exactly match canonical source:
  - EmployeeStaffWriteServices: `122bda2e905c25473702f30920a9c47a093c43d95fab49c1b84c2d51a8e4f15a`.
  - EmployeePersonCompleteWriteServices: `c4c5948a7d933cdc6665946e1a6970798b08fa3bc2f2ff8140baa8ecf47119c2`.
  - SystemStoreStaffServices: `30712fd445c1399af9aa668642a9d6b1c53597e6a30bf3a718546d63c1e62785`.
- Current member H5 entry SHA-256:
  `48c0a7e57dc001ebf9bca13eff477e21a0f774a2930604aed10fa5da3acb1cbc`.
  No PHP application or frontend bundle is replaced by this data-only repair.
- All affected and locked tables are InnoDB. Production audit `before_data` and
  `after_data` are MEDIUMTEXT; action/request/source/operator/reason lengths were
  checked against the generated values. No schema changes are required.

## Data Architecture And Preservation

- Authority is `eb_employee.avatar`; active `eb_system_store_staff.avatar` is
  its existing member-facing projection. Match by unique, valid phone only.
  Require every live organization/store assignment to be inside Meiyue root 3.
- Preserve known custom images on either master or any nondeleted assignment;
  an ambiguous identity or an inconsistent assignment is skipped, never guessed.
- Only master `avatar`, its custom-image discriminator `avatar_type=3`, technical
  `update_time`, and active staff `avatar` may change. Existing production
  profile-write semantics recognize custom images as type 3.
- No membership UID, names, phones, passwords, status, roles, organization,
  store, reservations, services, visits, balances, orders or performance values
  are modified. No reporting metric, fact grain, business date or historical
  transaction changes; this is current profile maintenance, not a business event.
- Scope tables and affected employee/staff rows are transaction-locked in a
  deterministic order. Identity, original portraits, unique phone ownership and
  live organization scope must still match the fixed plan before any write.
- One named lock serializes the repair; a deterministic request ID and atomic
  per-employee audit prevent replay. Any failure rolls back the entire batch.
- Current existing appointment/add-on/filter/menu behavior must remain intact;
  no application code, route, account, authorization or feature flag is replaced.
- No Redis flush or service restart is planned: the home staff projection is
  read directly from its table. Verify API/avatar and an actual member page after
  the authorized write. Recheck runtime fingerprints before and after.

## Backup And Execution Gate

After fixed-version acceptance, create the precise local commit for the script,
test and this evidence only. The existing repair authorization targets 008 only;
it is not authorization for a Git push or for other customer deployments.

Before apply, rerun the fixed plan check including image hashes. Create a 0700
independent directory under
`/www/backups/008.cc3798.com/20261019-round2-staff-avatar/`.
While holding the transaction locks, the script writes and reads back a 0600
`before-<plan-object-sha256>.json` containing exact original avatar/type/time and
staff-avatar fields. A SHA-256 of this snapshot and the local commit are linked
from every employee audit. No data update occurs unless backup creation and
readback succeed. This snapshot has not yet been created or used in production.

Rollback is limited to these backed-up profile fields, never a whole-database
restore. Before compensating, lock and check each affected row against the
recorded replacement and subsequent edits; preserve intervening manual changes
and write a compensating audit. It must not remove audit history or restore old
accounts, permissions, assignments or unrelated production data.

Old Meiyue, 007, 012 and Ruihao remain read-only or untouched. No shared service
restart, remote application replacement, mini-program upload or external
publication is included.

## Tests And File Groups

- `php -l scripts/import/customer_008_staff_avatars.php`: PASS.
- `php tests/customer-008/php/staff-avatar-repair.php`: PASS. Covers unique phone
  matching, both built-in placeholders, custom master/staff protection,
  duplicate source/target identities, unmatched/inactive source, mismatched
  staff, inactive/no assignment, foreign organization/store, missing images,
  allowlisted CDN URLs and host/credential/local-file URL rejection.
- `php tests/customer-008/php/staff-avatar-repair.php --mysql`: PASS on the
  existing disposable MySQL5.7 container at 127.0.0.1:13318, using only a newly
  created synthetic database `m008_avatar_repair_test`, dropped at completion.
  Covers real write/readback, exact outer rollback, private backup readback,
  concurrent manual portrait and scope drift rejection, injected audit failure
  after profile updates causing full rollback, audit presence, replay rejection,
  and exact preservation of all non-avatar fixture columns.
- The test container was initially stopped, started only for tests, and returned
  to its stopped state. No test is a production write or a claim of completed
  post-repair browser verification.
- `git diff --check`: PASS.
- Business-boundary comments were added to both the engineering script and test.
- Frontend source: none. Backend application source: none. DB migrations: none.
  Engineering scripts: one PHP file. Automated tests: one PHP file. Generated
  artifacts: private server plan only. Evidence: this document.
