# Data-menu current platform category: fixed self-test version

- Baseline HEAD: `02b5e704207e2c9783dbc85aa51a922c1d1434ec`.
- Scope: operations reports shown in the Data menu, related platform report readers and category dashboards. Report-time category follows the linked platform product ID's current category. No report-time name fallback. Historical award grouping recalculates when the platform category changes; cash, refund, service, inventory and allocation facts are not rewritten.
- Local classification fixture changes were enclosed in database transactions and rolled back. No production data or remote instance was changed.
- Pre-acceptance worktree patch SHA-256 for the listed modified files: `c46fca46f752fae24b2848d513382f27731be7732b03d395b68c2d628c3c423e`. It includes pre-existing unrelated hunks in two files; the release index excludes those hunks. Untracked helper and new tests are fixed by their individual file hashes below.

## Source files

| File | SHA-256 |
| --- | --- |
| `后端代码/app/services/report/StoreReportCurrentProductCategoryServices.php` | `5ff167414b654826815e1331684efe9f2c589ef0ea4db4520108e8d7b6c3326c` |
| `后端代码/app/services/report/StoreUnifiedReportServices.php` | `bb9c27e13d17b50a42817f88b4bc7a0cfc2a16011648e2f213cb380078aa217a` |
| `后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php` | `a170c12e3b49e6fa64d8ad5ebd6416b3e9ac0108c0ddbd549b3f833a36f07f30` (release projection) |
| `后端代码/app/services/report/StoreUnifiedReportPhaseThreeServices.php` | `af68a257a77b69077079b118a9878083a2a67844620a25fa47666a0dfd9288a6` |
| `后端代码/app/services/report/StoreUnifiedReportPhaseFourServices.php` | `d1e07aa2fff9e2d5b3b083e10ff1d17fc331dd9f9cba56def85d97b34f08955e` |
| `后端代码/app/services/report/StoreUnifiedReportPhaseSixServices.php` | `2a8254991cf7abdafb23345d2738a8dc16f90331f3812009286807510a5d859d` |
| `后端代码/app/services/report/CustomerAnalyticsServices.php` | `ad894d046fe24f797ef33155eba4ce0cef5dce7af64b87725e54597754b65677` |
| `后端代码/app/services/report/ProductManagementDashboardServices.php` | `2c6072e44006a04195a0d34e417e8894aa9c3fe35dd3da23d7d273021f1da106` |
| `后端代码/app/services/report/GroupManagementDashboardServices.php` | `d28952c6b7d880dfc7e2964b7de5fecedf0ac22796ba64ec3089aab74f9ec738` |
| `后端代码/app/services/report/MemberManagementDashboardServices.php` | `de818dd5fcc844ae9f221f3a77c5f981f54d85e6a96070d0064ac4ad2fba9677` |
| `后端代码/app/services/query/metric/RegisteredMetricReadServices.php` | `2c005f198ed390cd989d16e6029bf1a7c749115c16310ca5e6a39933ed0dffce` (release projection) |

## Test files

| File | SHA-256 |
| --- | --- |
| `tests/report-current-platform-category/php/store-operations-current-category-rollback.php` | `a08a878aee30cc77207f5a78e7a3dd683e5f2fc55113c6ee9f25f7f100035e65` |
| `tests/report-current-platform-category/php/store-operations-catalog-smoke.php` | `20428d568d2a4370dc6a3405a5ec1537ca621f686a364fb50dc2aef08cf95a99` |
| `tests/report-current-platform-category/php/platform-catalog-smoke.php` | `507ff257247590b5fa474eccdc6ee1fd31b3b1cf4fbde371b036719d93848e36` |
| `tests/report-phase-six/php/d3-salary-current-category-rollback.php` | `367ad784b2c65209b6c842cb6831a57bdcaea07ffc6bbe69018a6ecb6fdd8ead` |
| `tests/report-store-item-analysis/php/contract.php` | `7809fd8af8813ace36f02fdd70067183d6f744d77b0c649c83d970ef05f8e2cc` |
| `tests/cashier-v3/php/store-refund-ledger-mysql-integration.php` | `55c73a9aeda5d195935d89655bb5c23ff180f39e24fe9eb42e1ccf3387ccfa22` |

## Verification

- PASS: 19 operations report codes across two local stores; 51 further platform report codes plus three category dashboards.
- PASS: transaction-and-rollback checks for linked product reclassification, historical award regrouping, precise drilldown, filtering, blank category, immutable financial facts and member/product/group dashboards.
- PASS: 30 salary-category rollback checks and 10 store/date refund-ledger reconciliation cases. The October 4 historical card refund for 东方熏蒸 resolves `生美/卡项` via its verified cart component and linked platform product; mismatched holder, order, member or store remains blank. A local transaction proved the category changes with the platform product while 120000 refund cents remain fixed, then rolled back.
- PASS: store-item analysis, customer analytics and group-dashboard contracts; PHP syntax checks on all scoped PHP source and tests; scoped `git diff --check`.
- Existing `tests/report-product-dashboard/php/static-contract.php` fails on its `performanceTotalScalar` source-text expectation, which is absent from baseline HEAD. This is not a new runtime failure and is not treated as acceptance evidence.
- Multi-ID legacy `store_product.cate_id` values with no matching current platform category remain unclassified; no name-based report fallback was added.

## Delivery boundary

- Frontend source: none. Backend source: 11 files above. Database package: the scoped, audited RH catalog repair and rollback scripts (no schema change beyond their audit table). Engineering scripts: none. Generated artifacts: none. Evidence: this file and test output recorded in the task.
- Source files in this worktree also contain pre-existing changes from other work. The release index contains only this category closure; `StoreUnifiedReportPhaseTwoServices.php` and `RegisteredMetricReadServices.php` are projected without their unrelated hunks. The product manager accepted this round's tested behavior on 2026-10-13 and separately authorized a RH-only commit, deployment, data update and push; execution results are recorded separately.
- Business-code comments added or updated in the current-category resolver, phase-two/three/four/six report readers, customer analytics, dashboard readers and metric reader to explain ID authority, snapshot/money boundaries, and filter placement.
