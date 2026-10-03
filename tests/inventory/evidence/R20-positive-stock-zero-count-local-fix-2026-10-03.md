# 第20轮：有库存但一键清零未加载——本地修复自测

状态：开发与自测完成，待产品经理对下述固定版本确认验收；未 commit、未部署瑞昊、未 push、未写入线上库存。

## 现象、口径与修复

- 瑞昊黄山大润发店库存查询显示 4 个批次、合计 54 件，但盘点页点击“把所有库存改成0”得到 0 行。原因是原按钮按有效商品目录取批次，而这 4 个有余额的商品对应门店商品已删除或目录关系缺失。
- 清零专用读接口现在从当前认证门店默认仓的正数库存主体及有效批次读取；普通“导入库存”“加载全部商品”仍沿用原目录，不改变其范围。成本仍由现有权限决定，客户端不能指定其他仓。
- 批次合计与库存主体余额不一致或有余额却无可盘批次时拒绝加载，避免部分批次被误称为“全部”。按钮只预填实盘 0，不直接改库存。
- 正式提交仍走原有事务、批次盘亏、幂等与移动事实。失效目录仅允许本仓已有正数批次以 BATCH 粒度实盘为 0；盘盈、SKU 总量清零、伪造批号及跨仓均不放行。历史目录缺失的盘点详情从批次快照显示商品/规格名称。

## 固定版本

基线 HEAD：`885d8a7b78145a6f82dceab30ec8b846e90c0b79`。以下文件名与 SHA-256 顺序组合的指纹：`7b8e7b114bc8e2b756e6f5793e328b07a829c318be57431e11540350757e5302`。

| 分组 | 文件 | SHA-256 |
|---|---|---|
| 前端源码 | `前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue` | `a41d0fcbc551b48d0666cd22ca0a7a8f3a7dc14137e4d9765932d716fe9ae8d6` |
| 后端源码 | `后端代码/app/controller/store/product/inventory/InventoryStoreCatalog.php` | `9261b786f1041c3b3c366d9d1ff3467343532a9990525127e1864a072b0afdad` |
| 后端源码 | `后端代码/app/services/product/inventory/InventoryStoreCatalogServices.php` | `043d13a6ff747339142e792679d3bf345082635248cbd9299820b469968d9dac` |
| 后端源码 | `后端代码/app/services/product/inventory/InventoryStockCountServices.php` | `ccb68773426107771bc935c3cb7ff17ef04f2395dbd65970b79892deb0ca0373` |
| 后端源码 | `后端代码/app/services/product/inventory/InventoryStockCountDetailProjectionServices.php` | `a62cfde84322b88223ae24dc9eb37f8778abe0239df0fdc3ea6d4824427dd441` |
| 自动化测试 | `tests/inventory/js/count-catalog-batch-entry.mjs` | `bb17ebce2d7cc93718d47ad874926cb7553de2075b1955ff35ec69af8572ec45` |
| 自动化测试 | `tests/inventory/php/stock-count-catalog-validation-integration.php` | `6680599a292ad7424b409fdf19bdbf699a9cabaa1909dfb64d56e9488c579af8` |

数据库迁移、工程脚本：无。生成物：构建仅写 `/tmp/r20-inventory-zero-fix-build`、`/tmp/r20-cashier-zero-fix-build`，不纳入源码。验收证据：本文件。

## 自测

- 隔离库存测试库 `STOCK_COUNT_CATALOG_VALIDATION_INTEGRATION_OK`：下架商品双批次及缺失目录单批次读入、无成本权限、余额不符阻断、盘盈/非批次/伪造批号拒绝、三批次正式盘亏到 0、详情快照名称。只写隔离测试库；未操作瑞昊线上库。
- `STOCK_COUNT_CATALOG_VALIDATION_CONTRACT_OK`、`COUNT_CATALOG_BATCH_ENTRY_OK`、`COUNT_BATCH_ENTRY_CONTRACT_OK`、`COUNT_SUBMISSION_CONTRACT_OK`；`INVENTORY_API_RESULT passed=59 failed=0`。
- 修改的 PHP 文件语法检查、`git diff --check` 均通过。
- `inventory-vue3` 构建 1821 模块通过；`cashier-v3` 集成构建 1988 模块通过。仅有既有大 chunk 提示。
- 本轮更新业务注释的文件：上述前端组件、目录控制器、目录服务、盘点确认服务、盘点详情投影及专项集成测试。

尚未在瑞昊真实门店完成点击和提交验收；产品经理验收前不提交代码，不将预填 0 视为已经清零库存。
