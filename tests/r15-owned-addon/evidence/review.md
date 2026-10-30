# R15 owned projects and card presentation

Fixed source: fixed.json. Four business files (one member page, three PHP files), seven tests, two scripts. Source baseline 96953d21; shared HEAD 6e959c83. Existing released icons, service visibility, removal and fullscreen layout are retained. No shared-index edits, commit, deployment, push or mini upload.

## Behavior
Both tabs and unused catalogue fetching/filtering are removed from this picker. Ordinary and historical main rights use one owned-right query. Old historical_order_id and context-free callers preserve their contracts.
Cards group by purchase order, with their issued rule and optional actual card number. Equal names cannot merge purchases. Normal cards show independent counts; choice-count cards show shared counts; choice-kind candidates show pending selection; time cards show unlimited within validity; combinations keep separate groups. Legacy rules are never guessed from a name or current product. Standalone projects render a single row. Folding preserves selection and search expands matches.

## Data architecture
Existing order details, issued card state/components/groups, imported organization policy and V3 reservation lines remain authoritative. New display fields are hints, never a grant of rights or a second quota source. No schema migration or new event/fact type. Existing booking header/lines/event/idempotency are reused.
The write transaction locks source orders and details in stable order, then rechecks ownership, store, expiry, shared demand and distinct kind limits including pending reservations. Server durations define the single booking. Failed attempts roll back; cancellation releases occupied availability; physical rights are not consumed by booking. Refund, redemption and cancellation writers are unchanged.
The owned picker is read-only and does not call the legacy writeoff consistency repair. Candidates are narrowed by member/status/project type before individual checks. Small real-DB scenarios were tested; no production-scale load or multi-process concurrency claim.

## Verification
Disposable m008-rights-isolate only: historical regressions, ordinary-main/imported-addon creation, replay, stale/foreign/expired/depleted rights, occupied sibling, cancellation release, full rollback and authoritative durations pass. Real fixtures cover normal, choice_kind, choice_count, time and combination, including combined shared/kind over-demand. Existing group test schema was added only to this local fixture. All business fixture transactions rolled back and the previously stopped database container was stopped again.
Actual Vue template/styles/methods in isolated Chrome at 320/375/430: five card types, standalone rows, folding/search, 40-row scrolling, fixed header/footer, removal/readd pass. Prior 22 removal checks and historical frontend regressions pass. H5/WeChat builds and 419 native WXML/WXS compile. No real-device, live booking or production business writes.
