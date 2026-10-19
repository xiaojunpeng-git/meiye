# M008-R2-20261019 Old Meiyue Material Library Preparation

## Authorization And Cause

The product manager requested an explanation for old uploaded images missing
from the 008 image picker, then authorized **preparing** restoration of the 1024
old picker images and corresponding categories, preserving the current seven
008 images with deduplication. Videos and automatically generated attachments
are excluded. This is separate from the completed employee portrait repair.

Subsequent explicit decisions: skip old missing attachment 60; reuse the three
existing blue-carousel pictures and change only their category to the mapped
`蓝色主题`; do not transfer duplicate files. Full validation and the migration
tool are now prepared and tested. The fixed candidate is documented separately
in `2026-10-19-round2-material-library-fixed-version.md`; no production material
migration has occurred.

The earlier Qiniu configuration task explicitly excluded attachment-library
records. Connecting the bucket makes existing direct image URLs readable but
does not populate 008's attachment tables or picker categories. This is missing
catalog migration, not evidence that the old pictures were deleted.

## Read-Only Findings

- Sole source HEAD at the start of material preparation:
  `de11248ebcf895eee2c8da7a88ccda5d8994d34b`. Portrait supplement was separately
  accepted and committed as `d3c02480c8ad443263fc4b72b738797d178ab844` while this
  read-only material analysis continued. No material source/app changes exist.
- Old `morestore:3306.eb_system_attachment`: 32,358 total rows. Old picker scope
  is exactly `type=1, relation_id=0, file_type=1, module_type=1`: **1024** rows.
  The remaining 31,334 rows are not this image-picker import scope, including
  one video and automatic modules. They must not be blindly copied.
- Target `mohe_008:3307.eb_system_attachment`: 23 total rows; **7** in platform
  picker scope `type=0, relation_id=0, file_type=1, module_type=1`, plus 16
  automatic attachment rows. All existing rows must be preserved.
- Target attachment categories: zero. Source has 18 categories, of which **17**
  are image categories and one is a video category, excluded. Parent/child
  categories include carousel/shop/product-detail subcategories.
- Type values differ: old picker uses `type=1`, new platform uses `type=0`.
  A raw table copy would misclassify scope. Create target-side category IDs and
  explicitly remap parent IDs and attachment `pid`, rather than preserving old
  primary keys or overwriting target rows.
- Source 1024 rows contain **957 distinct image URLs**, with 67 repeated rows
  in 57 duplicate groups. All repeats are within the same category; there are
  no cross-category duplicate groups. Deduplicate by URL/category without
  inventing a priority between category paths.
- Address distribution: 810 Qiniu rows, 156 rows pointing to the historical
  `food.lovequwen.com` domain, 58 relative local rows. These are record counts,
  not distinct physical-file counts.
- The old frontend `meiyue.meiyuekeji.cn` vhost is named
  `morestore.cc3798.com.conf`, actual root
  `/www/wwwroot/morestore.cc3798.com/public`.
- All **156** historical-domain rows have their original file at the matching
  path under that root. A representative old-domain request failed DNS
  resolution. Do not import those dead-domain URLs: prepare copying verified
  files into an independent 008 media path, with content digests.
- Of 58 relative rows, **57** files exist under the real old document root.
  One old built-in-image record has no file:
  old attachment id 60, `/uploads/system/a377176f6808c45ee34270377c32ffb3.png`,
  category `系统自带图片`. The same filename is absent from the local workspace.
  Do not manufacture a replacement or claim this record is recoverable.
- Full subsequent validation checked all 957 unique old URL/category objects.
  Eleven initial remote timeout/interruption failures passed a bounded,
  lower-concurrency retry; 956 objects are image-valid and the only unavailable
  record remains old id 60. All seven existing 008 pictures are image-valid.
- Content/category dedup reduces these 956 objects to 926 references over 918
  distinct byte hashes. Thirty further same-category duplicates are removed;
  eight same-content cross-category relationships are preserved. Three content
  hashes match existing target ids 25/26/27, already approved for category-only
  updates. Final candidate: 17 new categories, 923 new references, 3 reused
  references, and 915 new distinct files (327,546,038 bytes). Expected picker
  count after migration: 930, including the seven existing rows.

## Recommended Next Candidate

1. Leave the old database and old files read-only. Freeze the exact allowed
   source records/categories and target catalog baseline; inspect parent-tree
   integrity and retain original names/category paths.
2. Stage each distinct new picture once in an independent 008 directory,
   including old Qiniu pictures; do not add editable picker rows pointing at
   old shared objects that an 008 delete action could remove. Prefer matching
   old local bytes when a CDN object has identical content. Imported files are
   root-owned/read-only; cross-category references can share bytes without the
   web worker deleting a file still referenced elsewhere.
3. Match target content safely; preserve all seven existing picker records and
   all automatic target attachments. Deduplicate exact same source URL/category
   and confirmed identical target content, not filename alone. Existing ids
   25/26/27 retain their files, ids and names; only their category is remapped,
   as separately approved. The other four picker rows and 16 automatic rows
   are unchanged.
4. Quarantine the one irrecoverable old built-in image rather than inserting a
   broken thumbnail. The product manager explicitly approved skipping id 60.
   Other unavailable objects
   found during full validation must also be reported explicitly.
5. Implement a precise transactional import of image categories and image
   records only, with old-to-new ID mapping, private rollback snapshot, an
   independent audit/replay guard and unchanged-target assertions. No existing
   source category/type semantics should be copied blindly.
6. Complete isolated MySQL5.7 write/rollback/ID-collision/dedup/scope tests,
   freeze the resulting source/test/file/plan fingerprints, and request product
   manager acceptance before local commit and any production migration.
7. Verify the actual 008 image picker category tree, total and selected image
   preview; never click a decoration save/publish control as part of migration
   verification. Do not change the homepage design or selected images.

## Data Architecture And Current Boundary

Source attachment rows are the old catalog authority; target attachment rows
become 008's independent picker catalog. Immutable image bytes are assets, not
alternate business source. This does not change customers, orders, money,
entitlements, appointments or reporting facts. Authorization is the platform
picker's target scope, not all media accessible by an old URL.

The engineering script and tests have since been developed and self-tested,
including isolated MySQL5.7 writes/rollback and source/target read-only checks.
No material-library commit, production backup, published media, database update
or deployment has occurred. Fixed-version product-manager acceptance is pending;
see the separate fixed-version evidence. No image-picker completion is claimed.
