# M008-R2-20261019 Dynamic WeChat Portrait Supplement

## Gate Result And Scope

The product manager accepted the original 97-person repair. Its exact three-file
local commit is `de11248ebcf895eee2c8da7a88ccda5d8994d34b`.
Before any production update, the required live check rejected a changed image
digest. Read-only comparisons proved that all 97 employee/source identities,
organization scope and original portraits were unchanged. The only drift was
the thirdwx.qlogo.cn image for employee 17. Repeat fetches returned different
JPEG bytes and decoded pixel digests, so this was not treated as harmless
metadata and the original gate was not bypassed.

No production DB write, public image publication or push has occurred.
This supplement is **developed and self-tested, pending product-manager fixed
version acceptance**. The original accepted commit remains intact.

## Minimal Technical Change

Freeze that one dynamic WeChat image once into a private 0600 local object. The
new plan pins its exact image bytes and immutable intended 008 URL. Source UID,
phone ownership digest and original source URL remain checked. Later check/apply
uses that captured object rather than silently fetching a different WeChat
picture. The other 96 existing Meiyue CDN pictures still require their exact
original network-byte fingerprints.

After backup and before the profile update, publish only the one content-addressed
image under 008 `public/upload/staff_avatars/`. Never overwrite an existing
different object; verify the exact stored digest. This is a new immutable media
asset, not an editable source copy. No image re-encoding or content editing is
performed. A rollback-only DB rehearsal never publishes media.

All other original limits remain: 97 employees plus 97 active staff projections,
3 unmatched people and one non-Meiyue employee excluded, existing custom photos
protected, no account/permission/assignment/member/business changes. A data
rollback keeps the audit and must not blindly remove the new immutable image
once it may have consumers.

## Fixed Supplement

- Base HEAD: `de11248ebcf895eee2c8da7a88ccda5d8994d34b`.
- `scripts/import/customer_008_staff_avatars.php` SHA-256:
  `69855946d9712d6abcf6c5256232812a39715ef396f3447513e24764bf2839cf`.
- `tests/customer-008/php/staff-avatar-repair.php` SHA-256:
  `9dfee4357c952e34e23ed713e6ac1cf034a265d58a15aeb550d099b97074935c`.
- Source/test patch SHA-256 (`git diff -- <script> <test>` bytes):
  `fc56ad0e000c7195457dd84ea61fe7b655ca5c622c18b38cd5a6161563e5dcbd`.
- Private plan: `/tmp/008-staff-avatar-frozen-plan-20261019.json`, file SHA-256
  `8f57ff1c24871dfe2b4eff8b397bf4d69d958bd6d0fce961ed8fdf1fd120df21`.
- Plan-object SHA-256:
  `42cf5fb50a2294d6d59feabe4f10051011d6effd1a156d34a6765cddab94d6a4`.
- One frozen 132x132 JPEG, 4,571 bytes, SHA-256:
  `d40647ce26b9ed52b39870cf04a0fb88e1007a029add598a718569519550ea83`.
  The private object is mode 0600 and is not yet present under the public URL.

## Verification And Delivery Groups

- PHP syntax and planner/identity/scope/custom-photo/URL tests: PASS.
- Synthetic raster tests: frozen bytes verified; tampered private bytes rejected;
  publication to a disposable local test webroot preserves bytes; same-hash
  retry does not overwrite; a conflicting object is rejected and preserved.
- Disposable MySQL5.7 tests from the original closure rerun: PASS, including
  real profile writes, full rollback, manual-photo/scope drift rejection,
  injected audit failure atomicity, replay and exact non-avatar field invariants.
- New production plan plus repeated read-only check: identical file/object
  digests, 97 employees / 97 active projections, 96 original CDN objects and
  one private frozen object. No production updates occurred during these checks.
- `git diff --check`: PASS. The test container was restored stopped.
- Scope/authority, facts, time, permissions and rollback analysis from the
  original evidence remains applicable. This adds only controlled immutable
  file storage plus exact-byte verification, with boundary comments in the
  engineering script and automated test.
- Frontend/backend application source and DB schema migrations: none.
  Engineering script: one modified PHP. Automated test: one modified PHP.
  Generated asset: one private JPEG and revised private plan, not in Git.
  Evidence: this supplement. Prior evidence preserves the actual earlier gate
  and original fixed candidate; it is not rewritten as deployed success.
- The separately requested 1024-image material-library preparation is excluded
  from this candidate, commit, data repair and acceptance.
