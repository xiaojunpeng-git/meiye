# R39 库存入出库统计周期合并：开发与自测

- 基线 HEAD：`14348b7fc0a44f447bb98db11d184a64a194426e`。当前工作区含其他任务的未提交改动，本次没有暂存、提交、部署或推送。
- 范围：入出库统计去除结果区重复标题、说明卡与业务日期列；保留一处业务日期周期筛选。事实层按业务类型、商品 ID、SKU、库存单位、数量精度与历史名称快照跨日分组。`COUNT(DISTINCT source_id)`、流水数、数量和成本均由后端 SQL 计算。原按日列表接口保持原口径。
- 额外修正：整数数量格式化不再把 `100` 截成 `1`。非周期日期谓词明确拒绝，避免在聚合行上错误过滤。日期仍可作为周期筛选字段，但不作为默认结果/导出列。

## 自测

- 4 个 PHP 文件 `php -l` 全部通过；`npm run build` 通过，构建产物 `dist/assets/index-1d809731.js`、`dist/assets/index-1ebf15aa.css`（仅生成物，未入库）。
- `php tests/inventory/php/statistics-period-aggregation-contract.php`、`php tests/inventory/php/platform-operational-query-contract.php`、`php tests/inventory/php/store-inventory-policy-contract.php` 和 `node --test tests/inventory/js/inventory-period-controls-contract.mjs` 全部通过。
- 本地门店 `store_id=133`、授权仓 `location_id=79` 的真实只读统一查询：2026-09-28 入库 2 行且数量为 `100,100`；2026-09-29 入库 2 行；整月入库 4 行；无成本权限的出库行返回 `cost_amount_cents=null`。
- 本地数据库事务内临时复制同一 SKU、同一来源单据到另一业务日，周期查询返回单据数 1、流水数 2、数量 200、成本 46000 分。事务回滚后 `TEST-period-rollback-%` 记录数为 0。没有远程数据库操作。
- `git diff --check` 通过。浏览器连接工具报 `Unable to load browser request-header policy`，因此未完成 18091 页面视觉实测；截图验收仍待产品经理查看固定版本。

## 本次文件

- 前端源码：`前端代码/inventory-vue3/src/App.vue` SHA-256 `7b76d31ba6e6be62821e6812deccbd65f2714befcb71b7fd87683465f9539fd1`。该文件还包含先前 R39 同工作区未提交改动。
- 后端源码：`后端代码/app/services/product/inventory/InventoryMovementAnalyticsServices.php` `b5c516423a813aa1756d3e163fa71bed25c0d9001dd2ef01aff73ce55ff18cc6`；`query/InventoryOperationalUnifiedQueryProvider.php` `2e51cdb9008747294c9fa12585353288e8d4718959a703eb1fee3c06cdd39645`；`query/InventoryStatisticsUnifiedQueryContract.php` `fefed831670225e3e5c8b710d4ca39fa5d3eb588ee18df29f7aa38bb70798621`；`query/InventoryStatisticsUnifiedQueryProvider.php` `fdf7ba48d614336dad70ec7f80f8cf33eeaef39b5dc09f885328d07714282365`。
- 自动化测试：`tests/inventory/js/inventory-period-controls-contract.mjs` `7d1c4082a0ef46baa0aa942c55bed73848b6ae371fb8c297040f2eaf14693c36`（亦含先前 R39 同工作区未提交改动）；`tests/inventory/php/statistics-period-aggregation-contract.php` `f0d266f7790f9bbedea27e881e5b3a1bb2c5d8a65d902fbc38dc3261d33fb8ee`。
- 数据库迁移、工程脚本：无。本文件为验收证据，非业务源码。
- 上述已跟踪文件补丁指纹（本轮记录时）：SHA-256 `3d2074f3140ad17e79eb317585b004b38a1be8d001ec8d687a2f8cee9a27bfac`。

状态：开发与自测完成，待产品经理对固定版本确认验收；未 commit、未部署、未 push。
