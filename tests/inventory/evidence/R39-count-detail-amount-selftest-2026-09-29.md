# R39 盘点明细金额与单价显示：本地自测

- 基线 HEAD：`d12fb0837b2fa220abeee1c34f37d3c082cd60fd`；当前工作区有其他未提交修改，本次仅修改下列四个源码与测试文件。
- 范围：盘点详情新增“金额”列；按已结算库存批次流水逐行汇总盘盈/盘亏成本，保留分精度在接口中，页面按元取整展示。未修改导入、盘点确认、库存事实写入和成本权限。
- 原因：本地已确认盘点单 `PD2609290001` 的两行 `surplus_unit_cost_cents` 均为 `330`（3.30 元），并未漏导；当前员工没有 `inventory.cost.view`，详情投影将单价置空，因此页面显示“—”且不报导入错误。金额列也必须沿用该权限，不能借新列泄露成本。

## 固定文件

| 类别 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue` | `176016b765ad1496a21bef5153838421e1518729e31a193b7112a90bd2b4b791` |
| 后端源码 | `后端代码/app/services/product/inventory/InventoryStockCountDetailProjectionServices.php` | `87c268428c22b0e29c86be6e91c090cfbc82b47aa0fe8b4058ab59adef4bea63` |
| 自动化测试 | `tests/inventory/php/stock-count-integration.php` | `8c0bc37f15b842b35bd11bc5f0c9dcd0a9e1a3ef938ee30ebf04973ca01d26c7` |
| 自动化测试 | `tests/inventory/js/inventory-v3-c3-contract.mjs` | `17afe10f78c0f27284435d8a791be1a5ff8398caa27e7e5e9e23bb871b8212a4` |

数据库迁移、工程脚本：无。生成物：本地 `前端代码/inventory-vue3/dist/`，未纳入源码。验收证据：本文件与以下本地只读核对结果。

## 验证

- 本地服务只读投影核对 `PD2609290001`：有成本权限时两行单价均为 330 分，金额分别为 29040 分、25410 分；无成本权限时两行单价和金额均为 `null`。
- 盘亏多批次的回归断言已加入 `stock-count-integration.php`，期望按实际 FEFO 流水得到负金额；该写入型脚本本轮未在全新隔离测试库执行，不能将语法检查冒充集成通过。
- `inventory-v3-c3-contract.mjs`、`inventory-api-contract.mjs`、`count-date-ui-contract.mjs`、PHP 语法检查、`git diff --check` 与库存 Vue 3 的 Vite 构建通过。
- 浏览器中该账号原已打开的详情因刷新回到目标页面，本轮没有把浏览器视觉检查标记为通过；固定版本待产品经理验收。

## 数据边界

- 权威源：盘点单明细提供数量、单价输入快照；金额只取同租户、同门店、同仓、同盘点单的 `SETTLED` 批次变动事实。盘亏跨批次按来源明细号归集，正负方向与列表口径一致。
- 权限：查询接口先限定当前员工与单据范围；无成本权限时不查询金额事实，单价和金额均返回 `null`。
- 注释：本次在 `InventoryStockCountDetailProjectionServices.php` 与 `InventoryBusinessModal.vue` 补充了金额权威来源、跨批次和权限边界说明。
- 交付状态：开发与上述自测完成；待产品经理对固定版本确认验收。未 commit、未部署、未 push。
