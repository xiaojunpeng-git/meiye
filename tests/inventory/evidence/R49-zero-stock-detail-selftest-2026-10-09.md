# R49-00003 零库存商品详情修复 V1

状态：开发与自测完成，待产品经理确认验收。未提交、未部署、未 push。

## 故障与边界

- 用户报错位置：瑞昊 preview-8080，霍山文峰店，花园SPA生态平衡调理，库存数量“全部”。
- 浏览器实际请求显示列表查询成功，商品 product_id=117175、sku_id=115454、store_id=126、location_id=72、batch_id=18620，余额为 0。
- 点击“查看”调用 `/storeapi/product/inventory/v3/product-summary/117175/detail`，返回 `status=400`、`data.code=inventory_product_stock_not_found`。线上错误日志也记录同一错误。
- 根因：列表显式支持零库存，但门店商品详情仍以 `includeZero=false` 获取商品身份；已耗尽商品被错误当作不存在。
- 此次只修门店详情后端。保留现有页面、权限、成本脱敏、正常对账校验及最近 200 条流水限制；平台端不在此次变更范围。
- 详情从本店已结算批次事实识别零库存商品，不凭商品目录虚构库存。当前批次区域仍只显示有余额批次，已耗尽批次保留在历史流水。
- 默认库存首页和旧汇总仍只查询正库存。无迁移、无生产数据库写入、无真实资金操作。

## 自测结果

1. 新增隔离数据库集成回归：修复前真实复现 `inventory_product_stock_not_found`；修复后 17 项通过。覆盖零期初、完全耗尽、两位小数库存、成本权限、默认汇总、其他门店/租户/停用仓库、无事实/未结算/不存在商品、非法 ID、只读无写入、库存总账不一致拒绝及回滚无残留。
2. `stock-summary-total-contract.php`：8 项通过。
3. `inventory-batch-reconciliation-contract.php`：8 项通过。
4. `product-detail-batch-columns-contract.php`：5 项通过。
5. `product-summary-quantity-range-contract.php`：7 项通过。
6. PHP 7.4 后端语法检查、新测试语法检查、范围内 `git diff --check` 均通过。
7. 本地真实浏览器：扬州欧尚店选择“等于0”，分别点击“焕能净透洁面泡（裸装）”及“花园SPA天使套”的“查看”。两者均显示总可用库存 0、库存对账正常、暂无批次库存，以及各自完整的两条已结算历史流水。
8. 首个商品实际详情接口 `/storeapi/product/inventory/v3/product-summary/60504/detail` 返回 HTTP 200、业务 status=200、对账 MATCHED、两条流水。

测试运行：

```sh
docker exec -i -e BACKEND_ROOT=/var/www/html mohe-cashier-app php < tests/inventory/php/zero-stock-product-detail-integration.php
php tests/inventory/php/inventory-batch-reconciliation-contract.php
php tests/inventory/php/product-detail-batch-columns-contract.php
```

纯后端且依赖 BCMath 的合同测试在同一 PHP 7.4 容器中以 stdin 执行，路径仅作运行时替换，不复制源码。首次在容器执行同时读取前端的对账合同因前端未挂载产生两项缺文件失败；改为本机执行后 8 项全部通过，该失败不是业务回归。

隔离库固定白名单：本地 Docker mysql / `inventory_manual_inbound_test_20260730`。所有新增夹具在事务内，最终回滚并核对无测试事实残留。

## 固定版本及验收

- 基线 HEAD：`44dd39f9426e6bdfedbdc229fdc123d9af5067d9`。
- 固定版本：`R49-zero-stock-detail-v1-2026-10-09`，文件指纹见同目录 JSON 清单。
- 本地入口：`http://127.0.0.1:18091/view_cashier_v3/#/cashier`，当前恢复扬州欧尚店，留在第二个零库存商品详情供查看。
- 验收路径：库存 → 库存 → 等于0 → 查看。应显示 0 库存、正常对账及历史流水，不再出现“库存操作未完成”。
- 限制：霍山文峰店该线上商品在本地快照中没有对应库存记录，本地页面用同类真实零库存商品验证；线上原商品须在获准部署后复核。不得据此声称线上已修复。
- 现有 9 个无关暂存文件及其 diff SHA-256 保持不变：`dc31b057aca12a17ee75ed7a86e023bfeba79933849b91605faa54e209c95b46`。其他任务改动未触碰。

## 交付分组

- 前端源码：无。
- 后端源码及注释更新：`后端代码/app/services/product/inventory/InventoryStoreReadModelServices.php`。补充零库存身份识别、当前批次口径、默认正库存及权限/对账边界说明。
- 数据库迁移：无。
- 自动化测试：`tests/inventory/php/zero-stock-product-detail-integration.php`，含本地隔离库和回滚约束注释。
- 工程脚本：无。
- 构建生成物：无；仅 PHP 修复，不需要前端构建。
- 验收证据：本记录、固定版本 JSON、两张本地实际详情截图。
