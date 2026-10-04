# 第 24 轮：库存单据作废固定版本与本地自测

状态：本地实现和技术自测完成，待产品经理对本固定版本确认验收；未 commit、未部署、未 push、未改远程数据库。

## 范围与业务口径

- 院装：新领用单只提供“作废”，按原批次原量恢复库存并留冲销事实；有历史退回的旧领用单及退回单继续只读，不迁移或改写旧记录。核销项目不是领用必填项。
- 请货：仅供货方有权人员可作废；只要该请货单曾生成关联调拨（草稿、在途、已收货、历史已取消／已冲销均算），就不显示作废入口且服务端拒绝作废。未生成调拨的请货单作废只改请货权威单据和审计，不改库存。供货门店可看到发给本店的请货。
- 调拨：V3 列表只保留查看、发货、收货，不提供作废；原取消／冲销 URL 为兼容旧客户端保留，但控制器及领域服务均拒绝写入。历史已取消／已冲销的单据和事实继续可查，不修改旧记录。
- 入库／出库沿用既有原批次库存反向事实和作废入口；平台新增院装、请货作废权限登记，供货主体、组织范围及库存权限仍由后端校验。

## 固定版本

- 基线 HEAD：`ba0392b846f018d3cc3a9e2ac3216d93bd9d17f0`。
- 本闭环已跟踪文件补丁指纹（SHA-256）：`14bcfb50e130aa6bde41d5731003e8fbab49386320973eb0b8e27ced1dd0d9ed`。
- 以下清单为本闭环源码、迁移和测试的 SHA-256；原工作区的其他脏文件不属于本闭环，不可夹带提交。

| 分组 | 文件（相对源码正本） | SHA-256 |
|---|---|---|
| 前端源码 | `前端代码/inventory-vue3/src/App.vue` | `653e83f49d03d4031df57f39e335b37a9d0fe5abf0ce5ecc2036512920640a8c` |
| 前端源码 | `前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue` | `ac9f0d0120cf9190c5fef31c2ade86a7652109790420a37fe418cb9c98487d9e` |
| 前端源码 | `前端代码/inventory-vue3/src/services/inventoryApi.js` | `fc2739af66a69508b58c52bfd432e169491b8ea82be51e0c7d1b4ad605dccb2f` |
| 后端源码 | `后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformHqCrossTransfer.php` | `257b7c00d9ee9348476030ef63374123d100d6b416415c06c1ab8ee0c662f2dc` |
| 后端源码 | `后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformHqStockRequest.php` | `26c0c82bd37824e6f58f86e747830a3a92e3cdf85bdb6fcf91e9b7b13f3975e2` |
| 后端源码 | `后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformSalonUsage.php` | `85b2b6d704bdbe256f6b5faf1de5fd4666e0980f2c3e2ea3281b1626a4b3e2cf` |
| 后端源码 | `后端代码/app/controller/store/product/inventory/InventoryCrossSubjectTransfer.php` | `b02bea378b4c71c4e28516d3607da79301f8e67c5c497ec7b139b4e7d86d7c1e` |
| 后端源码 | `后端代码/app/controller/store/product/inventory/InventorySalonUsage.php` | `5afbbb1f9d3dd039484287af471fb5ad92a489aeec16c041a709d56430f38fed` |
| 后端源码 | `后端代码/app/controller/store/product/inventory/InventoryStockRequest.php` | `48d7ebc1e7b83f8112f4fbc485f05f6fa6a03f87c10eb4f473c45016b13eb5ce` |
| 后端源码 | `后端代码/app/services/product/inventory/InventoryCrossSubjectTransferServices.php` | `1f0e3c48280df6bb671a1fa11a2bb61e2e5589e00214c134544cde6156223b2b` |
| 后端源码 | `后端代码/app/services/product/inventory/InventoryManualDocumentReversalServices.php` | `a3affe21986e8d9eeed8613a5e6fc66fd9a61638d27592030aec7c90d7501704` |
| 后端源码 | `后端代码/app/services/product/inventory/InventorySalonUsageServices.php` | `c4f75fffa0c21d05b2a867f3d89132b83c9602ad1cc3451bbf6534795ca6e00e` |
| 后端源码 | `后端代码/app/services/product/inventory/InventoryStockRequestQueryServices.php` | `9e267ac8e9e797f7318f13c4c9ecbba6b65d234c920130654ed77af197c226e5` |
| 后端源码 | `后端代码/app/services/product/inventory/InventoryStockRequestServices.php` | `043de71c913e886d225cb0b442d6a2956f379357b926c9c654564bd0778082ea` |
| 后端源码 | `后端代码/app/services/product/inventory/query/InventoryOperationalUnifiedQueryProvider.php` | `a94396d2cf546af8aa9e328135d5d472a3ab97b251517bd6f15533d1a26639f7` |
| 后端源码 | `后端代码/route/admin.php` | `cd92005d7af3aed59eaae230238861f6eb6389dffef196290678e16755ea9225` |
| 后端源码 | `后端代码/route/store.php` | `ced82f645a45b28d6069bdd77486ef998bf9b5ee4fe3dc5fff8a12e25c13a1f3` |
| 数据库迁移 | `后端代码/database/upgrades/2026-10-04-库存V3作废权限/00-升级清单.md` | `3377bf996ff393bdc09d9f0d733b56ff11f261902b38f0e0c6287ec49893c574` |
| 数据库迁移 | `后端代码/database/upgrades/2026-10-04-库存V3作废权限/01-升级前检查.sql` | `c60aceb1a9d99c9626cfb56fe0338a0da022d8fa068fdbc1eafe478a98ab8109` |
| 数据库迁移 | `后端代码/database/upgrades/2026-10-04-库存V3作废权限/02-正式升级.sql` | `28b3676987dc5be2a224ec76c41db9e05c4cf75326a205f2d14622dae0db4461` |
| 数据库迁移 | `后端代码/database/upgrades/2026-10-04-库存V3作废权限/03-升级后验证.sql` | `fc7ec36c59755a9ecd6db6872b3a17cc645d6caee795095a1cecf7c6981e29d8` |
| 自动化测试 | `tests/inventory/php/request-transfer-lifecycle-contract.php` | `1f8b7d5939f3d7bf7daf7f1fdeb1599510764abc74d389951e3c7dec0c2e19fc` |
| 自动化测试 | `tests/inventory/php/request-transfer-lifecycle-integration.php` | `5037c7d0eb5fa6fef62620d921d1195fa7126dd898bdcda2939060cf7da1c116` |
| 自动化测试 | `tests/inventory/php/salon-usage-integration.php` | `cc97ffb2465679cd21d621e5b8c60a9092e2f70ec31b330998f368f7313c1166` |
| 自动化测试 | `tests/inventory/js/salon-optional-project-contract.mjs` | `f97499dac5221b0743c9654c1d94f60b460ed54a44031f6bfe34bd7ed795e6f4` |

## 已执行验证

- 隔离库 `inventory_manual_inbound_test_20260730`：院装领用／作废 8 项 PASS，含原批次恢复、幂等、跨门店拒绝、无项目领用。
- 同一隔离库：请货／调拨生命周期 14 项 PASS，含供货门店可见、请货方拒绝作废、草稿和已收货调拨阻止请货作废、历史已取消调拨仍阻止作废、旧终止接口拒绝绕过、调拨取消／冲销服务拒绝且库存和流水不变、无关联调拨请货作废幂等。
- 静态契约：`request-transfer-lifecycle-contract.php` 8 项 PASS；`document-lifecycle-route-contract.php`、`hq-request-party-contract.php`、`platform-operational-query-contract.php`、`salon-optional-project-contract.mjs` PASS。
- 两个新平台菜单路由曾在隔离库执行升级 SQL 后核对权限登记；未在远程实例执行。
- Vue 3 库存工程 `npm run build` PASS，产物 JS `index-2dd6771d.js`；只有既有大包告警。PHP 语法检查与 `git diff --check` PASS。
- 既有 `cross-subject-transfer-contract.php` 有一项与本轮无关的旧静态断言失败：要求未修改的总部入库代码出现特定 `where('f.store_id', 0)` 文本；本轮不把该断言当作通过证据。

## 注释与交付边界

- 业务注释已在院装冲销、请货供货方权限、关联调拨禁作废、旧调拨接口封锁、跨店读模型及测试隔离边界处补充或更新。
- 生成物：仅本地 `inventory-vue3/dist/`，不作为源码提交；未生成 8080 集成包。
- 验收建议：请货单未生成调拨时供货方可作废；一旦曾生成调拨，该单无作废入口，直调接口也不可作废；调拨列表只见查看／发货／收货；新院装领用作废后原批次库存恢复。
