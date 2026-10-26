# R39 盘点单批量操作本地自测与待验收版本

- 基线 HEAD：`ad27384a2a9ed128fd26afdc965572840b32594a`。
- 验收记录：产品经理在收到本文件所列固定版本与自测结果后，本轮明确要求 `commit`；按该固定范围确认验收并执行本地提交。未部署、未 push。
- 目标：仅门店端盘点草稿增加导出、导入、库存清零、加载全部商品；盘点确认不再按 100 行截断。
- 数据边界：导出/导入/清零均只操作当前表格与浏览器草稿；只有“完成盘点”调用服务端，整单事务入账。加载目录按当前登录门店及默认库存仓过滤；零库存规格也包含。

## 固定源码文件与 SHA-256

| 类别 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue` | `dc2772719099fe84c3187515a84367f2eb3daabb5314f778e969a569d0f693f5` |
| 前端源码 | `前端代码/inventory-vue3/src/components/countWorksheet.js` | `a48707144c270596c88d0b3a5ee7bb3613e877a0838e09dc3fc888060b44ae7e` |
| 前端源码 | `前端代码/inventory-vue3/src/services/inventoryApi.js` | `3ef95a5bdac6ae2787c7d38c69ab75751e6537c8525ef6f88f1b93dd8926761d` |
| 后端源码 | `后端代码/app/controller/store/product/inventory/InventoryStoreCatalog.php` | `33679f2154b704e216c7676b10f00d04ead6f5b08e1a8f99e3460f6085e3e1b9` |
| 后端源码 | `后端代码/app/services/product/inventory/InventoryStoreCatalogServices.php` | `f083ab3a6e06f045f021d9a98aabdc90e006d64a25cef3b5e72ce9687b6c5070` |
| 后端源码 | `后端代码/app/services/product/inventory/InventoryStockCountServices.php` | `8ed7c0db6ceb23dcda496e4e1214625f1cf04f5b73d41b766978550b07653a35` |
| 后端源码 | `后端代码/app/services/product/inventory/InventoryErrorMessage.php` | `3ca11762a273f0031e1730719b31e097b3bfa02b9122d42d8f521def14ff5328` |
| 自动化测试 | `tests/inventory/php/stock-count-integration.php` | `d0a61902a68ca8458622545a1e49b0a7edfc26f61a112723fb4b007230185818` |
| 自动化测试 | `tests/inventory/php/stock-count-large-document-contract.php` | `010518feff41b09c66f3df51744624f854c9f66ed5270ccedb84c494e85e4bb1` |
| 自动化测试 | `tests/inventory/js/count-worksheet-contract.mjs` | `0764f78152e777b890c4f2331a489ec30e917d5a3f531168121b527f1512cf3b` |
| 自动化测试夹具 | `tests/inventory/sql/manual-inbound-fixture.sql` | `f4b41a9a3a3cdb703545ae2009c4bd4d041d9aebee9a3fd7ba525cf39cb13083` |

数据库迁移、工程脚本：无。生成物：`inventory-vue3/dist/` 为未纳入源码的本地构建结果。验收证据：本文件。

## 自测记录

- `npm run build`：通过，当前 JS 指纹 `index-db48b6bd.js`、CSS 指纹 `index-45915847.css`。
- PHP 语法检查：盘点服务、门店目录服务、目录控制器、错误文案均通过。
- 独立本地测试库 `inventory_manual_inbound_test_20260730`：原盘点盘亏 FEFO、盘盈、幂等、缺少盘盈批次信息回滚均通过；151 个零库存 SKU 单张盘点单确认并产生 151 条明细通过；分页目录包含零库存 SKU 通过；账面快照过期时整单回滚通过。该库不是客户实例或线上库。
- `stock-count-large-document-contract.php`：151 行不截断、旧请求指纹兼容、重复规格拒绝均通过。
- `count-worksheet-contract.mjs`：151 行 CSV 往返、可见列一致、特殊字符/前导零、错误表头/引号拒绝均通过。
- `inventory-v3-c3-contract.mjs`、`catalog-scan-contract.php`、`git diff --check`：通过。
- 未进行线上或真实门店盘点验收，不能将本地集成测试视为产品经理验收。

## 业务与注释说明

- 盘点确认只创建一个事务性单据；账面数通过当前默认库存仓的库存投影取得，最终服务端加锁再次核对，防止导出后库存变更误清零。
- 零库存、从未入库的有效库存规格允许盘点为 0；库存记录仅在最终确认事务内建立。盘点数量 0 保持专用口径，不放宽其他入出库命令“数量必须大于 0”的合同。
- 本轮在 `InventoryBusinessModal.vue`、`countWorksheet.js`、`inventoryApi.js`、`InventoryStoreCatalog.php`、`InventoryStoreCatalogServices.php`、`InventoryStockCountServices.php`、`InventoryErrorMessage.php` 与测试夹具中补充了职责、输入/输出和关键边界注释。
- 导入文件按当前可见的商品 ID、名称、规格、条码核对；若可见身份完全相同导致无法唯一对应 SKU，则拒绝导入并提示重新核对，不猜测匹配。CSV 文件经 Excel 另存若改写了条码前导零，也会被拒绝。

## 待产品经理验收

在门店端盘点弹窗核对四个按钮的位置、导出列与当前表格一致、导入回填、清零只改实盘数、全量加载保留已填数以及大单提交行为。确认验收后，按项目门禁只暂存本闭环文件并创建本地 commit；部署与 push 仍需另行授权。
