# M008-R2-20261019-NAV Navigation Outer Margin Candidate

## Scope And Baselines

The product manager clarified that the entire navigation component/background
must be inset from the page edges. Existing `prConfig` is padding, not the
requested gutter. The earlier explanation that the old control met this need
was incorrect. This candidate introduces a distinct outer gutter and does not
reinterpret saved padding or automatically modify existing decorations.

Canonical HEAD: `b3c6dfe5d4fa1d031920d43a7251af825d70867f`.
The three affected business files were clean at task start. They remain in the
single canonical source tree; no worktree or editable business source copy was
created. Other tasks' dirty files, including admin build configuration and AI
changes, are excluded. No full application release bundle has been built.

This task is local development/verification only. Online runtime/migration,
instance backup and rollback checks are not applicable yet. No remote instance
connection, production DB write, decoration save or publish was performed. The
user's live 008 DIY tab and its in-progress edits were left untouched. Ruihao
and all other instances remain unchanged. No commit, deployment or push yet.

## New Behavior

- Navigation settings add `左右外边距` (`outerMarginConfig.val`, 0..100).
- The old padding control is labeled `左右内边距`; its stored value and effect
  are preserved. Outer and inner settings remain independent.
- Admin preview applies CSS margins to its complete root, including background.
  Vue2 uni-app member applies equivalent `rpx` margins to its complete root.
- Legacy decorations with no new field use zero external gutter. Opening the
  editor extends only its cloned draft. New decorations also default to zero.
- For example, inner 0 / outer 10 aligns the component's background with a
  product-list reference having side gutter 10. Outer 50 insets the whole
  component; it does not add 50 units of icon padding.

Unchanged existing behavior separately checked: visible menu filtering,
3/4/5-column grids, page grouping, link targets and existing inner padding.
Those are regression checks, not additional optimization results.

## Source And Evidence Groups

Frontend source, three files:

1. `前端代码/admin/src/components/mobilePage/home_menu.vue`
   `f5ede415a5ab769dfa1bff8b5270e959e4112e270b79f14b4e4e0f666894f5f8`
2. `前端代码/admin/src/components/mobileConfig/c_home_menu.vue`
   `1e492eba7bc1589bbb4c5861020b8bc9dbd2929dd9fc1754081446befd8be2b1`
3. `前端代码/uniapp/pages/index/components/menus.vue`
   `ef07717dc55595804f6864658c04a76310c6183c164e3dfe7b63e561e63d2935`

Automated tests and local fixture, four files:

4. `tests/diy-menu-spacing/source-harness.cjs`
   `4b0b662cfbc4d1ea8a000d2124903260e7d48b3422c50fddba79a7a0f1773389`
5. `tests/diy-menu-spacing/contract.cjs`
   `a65a4f9a9245cf8a9f8846bb5444a385d3cded4e06ca7dd9ecb4bb7706b81f63`
6. `tests/diy-menu-spacing/fixture.html`
   `c067bcac589a5b95d79527d4ec13334911a653bee2ecd54390b8a5d8a43cce66`
7. `tests/diy-menu-spacing/serve.cjs`
   `411114f2f2a83116f145a14f2abf2d71b68318b209a36b24fa1f9235afe18b02`

Backend source, schema migrations and production engineering scripts: none.
Acceptance evidence: this document. Generated evidence: desktop/default and
mobile screenshots in this directory. Screenshots are not business source.
New/updated business-boundary comments are in all three frontend files; test
comments explain source compilation, synthetic persistence and unit adapters.

Fixed patch SHA-256:
`7d7cf2f92cb673757fc273dc608529f0b9e84dba33d4e91925774d0f46856de8`.
Method: SHA-256 of `git diff HEAD -- <three frontend paths>` followed by
`git diff --no-index -- /dev/null <file>` for test files 4, 5, 6, 7 in order.
This document and screenshots are excluded from the patch fingerprint. Exact
local-commit closure: the seven source/test files above plus this document.

## Self-Tests

- `node tests/diy-menu-spacing/contract.cjs`: PASS. Actual Vue2 SFC template
  compilation, new default and legacy fallback, cloned-draft isolation,
  independent padding/margin, values 0/10/50/numeric string, invalid/negative/
  excessive value guard, saved JSON round-trip, settings control registration,
  visible menus, grid columns, pagination and navigation delegation.
- `node tests/diy-staff-component/diy-staff-component-contract.mjs`: PASS.
  Neighboring existing staff-component contract remains intact.
- `git diff --check`: PASS.
- Browser uses the canonical components compiled in memory. Fixture adapters
  provide uni-app tags, rpx conversion, image stub, synthetic store and test-only
  localStorage. The fixture does not duplicate navigation business logic or
  call a customer API. Assets are existing local test images, not customer data.
- At component width 375, both admin and member roots measured exact left/right
  gutters 0, 10 and 50, corresponding widths 375, 355 and 275. Padding remained
  0 throughout. All four local images loaded in each component.
- Inner 50 plus outer 10 measured root gutters 10/10, padding 50 and width 355
  on both components. Saving the synthetic test draft and reloading retained
  both independent values and menu content.
- At actual CSS viewport width 320, no page horizontal overflow: scrollWidth
  320. Component width 296: editor gap 10, member scaled gap about 7.885 CSS px;
  both exactly align with their matching product reference. Both have four
  loaded images and component scrollWidth equals clientWidth. Browser zoom was
  compensated using observed innerWidth, then the viewport override was reset.
- No fixture JavaScript errors. Final fixture is inner 0 / outer 10.
- Screenshots: `2026-10-19-menu-outer-margin-desktop.png` (default window,
  two 375-wide components stacked) and `2026-10-19-menu-outer-margin-mobile.png`.

## Data And Delivery Boundaries

The existing DIY JSON is the authoritative configuration. The unchanged
controller preserves navigation fields when encoding `value`; the service
updates that JSON via its existing permission/save path. No extra table, data
authority, migration, business event, monetary/statistical fact or permission
change is introduced. Old configuration stays readable with zero gutter.

This is component-level browser testing, not a full admin-page save, a real
database persistence test or HBuilderX production H5/mini-program compilation.
The localStorage test proves only synthetic serialization/reload. No production
save was used to make the test pass. No mobile-vue3 merchant code was changed.

Loopback-only local preview: `http://127.0.0.1:19208/fixture.html`.
Server: `node tests/diy-menu-spacing/serve.cjs`, no external listener.
Development and component self-tests are complete; pending product-manager
acceptance of this exact candidate. Acceptance permits the normal precise local
commit only; deployment, online configuration updates and push remain separate.
