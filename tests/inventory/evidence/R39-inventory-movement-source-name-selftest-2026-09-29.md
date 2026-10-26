# R39 库存统计业务类型中文显示自测（2026-09-29）

- 基线 HEAD：`e1cd593c6cf03e30332e024ec5f30a9047764d6e`
- 本闭环范围：仅将库存批次流水来源代码 `cashier_sale` 展示为“收银销售出库”；事实来源代码、过滤键和统计汇总不变。
- 后端源码：`后端代码/app/services/product/inventory/InventoryMovementAnalyticsServices.php`，SHA-256 `ce6fe3fcf8133869aedcf8d64d4566e7007f5e475f3949d18e578d602ba2bfed`。
- 自动化测试：`tests/inventory/php/inventory-movement-source-name-contract.php`，SHA-256 `4dbfe63deb2abe3ab9414464444d689a8748b25e5120f2aad7bf0d6e4563638f`。
- 后端源码补丁 SHA-256：`15bccbf1f16f8e952c0ac8388d235f5729ba1f5b81e53edab92c58e3eafc3bde`。
- 前端源码、数据库迁移、工程脚本、生成物：无。本文件为验收证据，不是业务源码。

## 架构与权限边界

权威数据源仍为已结算库存批次流水事实；事实粒度、业务日期、门店范围、金额权限和后端汇总均未改动。只在返回 `source_type_name` 时翻译代码，保留 `source_type=cashier_sale` 供精确过滤与追溯。无写事务、并发、幂等、冲销、迁移或数据回填变化。未知来源仍回显原代码，防止无依据地误分类。代码注释补于上述后端文件，说明此边界。

## 验证

- PHP 语法检查：源码及新测试均通过。
- 新映射契约：`cashier_sale` 中文、既有 `manual_outbound` 中文和未知来源回退均通过（3/3）。
- 周期聚合契约：4/4 通过。
- 本地 `mohe-cashier-app` 只读查询门店库存位置 79、2026-09-01 至 2026-09-30 出库：返回 1 行，`source_type=cashier_sale`、`source_type_name=收银销售出库`、单据数 1、流水数 2、数量 4 盒、成本 1320 分，与修复前业务数据一致。
- `git diff --check` 通过。未进行线上写入或部署。

状态：开发与自测完成；产品经理查看本固定版本后明确要求 commit，本闭环据此进入本地提交。未部署或 push。工作区其他改动不属于本闭环。
