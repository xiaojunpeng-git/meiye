# 门店内嵌库存调拨快捷筛选与出库选品：本地自测

状态：开发与自测完成，待产品经理确认固定版本；未提交、未部署、未推送、未修改客户库存。

## 范围与数据口径

- `inventory-vue3` 是唯一库存前端源码，门店 `cashier-v3` 通过包别名嵌入；两种入口分别构建，不能只更新独立库存页面。
- 门店调拨页的“未到货”快捷按钮只查询当前门店作为调入方且已发货、尚未收货的调拨单；服务端投影 `can_receive` 限定接收资格。按钮选中时状态为“在途”，不受当前调拨日期范围限制。
- 门店出库选品只返回当前门店仓有正数有效批次的原商品/SKU；同名新商品没有库存时不能冒用历史 SKU 的批次。选品与真实出库仍分别由查询权限和后端扣减校验约束。
- 修复门店库存菜单切页时调用已删除函数导致出库页无法打开的问题；修复出库目录接口漏传 `inventoryOnly` 参数、使 `for_outbound_stock` 被误传到上一参数的问题。

## 固定开发版本

基线 HEAD：`f8f57b52c8375a5a588d0c0c375335357127ec01`。以下为本闭环文件 SHA-256；未列的脏工作区文件不属于本闭环。

| 分组 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/inventory-vue3/src/App.vue` | `b0733118f7ab73617711ff4111b9e244e3e53ea65caf4b32c5b5006d02b3c0ef` |
| 前端源码 | `前端代码/inventory-vue3/src/styles.css` | `4cc5ac816f5117893bd20c0d3fab23a554e72e477b4bf3e1d3991f506e1974fa` |
| 后端源码 | `后端代码/app/controller/store/product/inventory/InventoryStoreCatalog.php` | `5def3aaa4a6375ef827e65bb5de7a28cc42e12f4e87ecf49ba26c06032a0d5a1` |
| 后端源码 | `后端代码/app/services/product/inventory/query/InventoryOperationalUnifiedQueryContract.php` | `502de2ebeb6c2203bebe62a9994e1a13b71f18ae16718c48ea951fdf31f13fdf` |
| 自动化测试 | `tests/inventory/js/transfer-unreceived-quick-filter-contract.mjs` | `da4dc2714224b51b2cf108265441a2d9c097af256eefc31153679f520e2e7eb7` |
| 自动化测试 | `tests/inventory/php/operational-unified-query-registration-contract.php` | `53e83bfb269d7c4590a885045eb8efc8c4a3f2f443db41599e03e953d71505b1` |
| 自动化测试 | `tests/inventory/php/transfer-in-transit-period-contract.php` | `b67fef38b24648a58b97411524601f10adff5961a2928ba3175c106ed704bf81` |
| 自动化测试 | `tests/inventory/php/store-outbound-catalog-wiring-contract.php` | `e833ddb17165867c35e78e7900ffad8033ad19e605051bcc55397e0c876c7a35` |
| 自动化测试 | `tests/inventory/php/store-transfer-unreceived-query-contract.php` | `d14fc8117108042265bffd7e3b992991b00fe265b71fc1a9c2608fbb6055721d` |

数据库迁移、工程脚本：无。注释已更新 `App.vue`、`InventoryStoreCatalog.php`、`InventoryOperationalUnifiedQueryContract.php`。原有历史 SKU 选品实现和事务回滚测试已在基线提交 `69871706` 中，不复制第二套实现。

## 自测

- PHP 合同测试 `STORE_OUTBOUND_CATALOG_WIRING_OK`、`STORE_TRANSFER_UNRECEIVED_QUERY_CONTRACT_OK`、`TRANSFER_IN_TRANSIT_PERIOD_CONTRACT_OK`、统一查询注册全通过；JS 合同测试 `TRANSFER_UNRECEIVED_QUICK_FILTER_CONTRACT_OK`。
- 隔离库 `inventory_manual_inbound_test_20260730` 中事务回滚集成测试 `OUTBOUND_ORPHAN_STOCK_CATALOG_OK`：普通同名新 SKU 数量 0，历史库存 SKU 数量 1，单位和身份不串用；跨门店与账批不一致均阻断。
- 本地 `18091` 门店端真人操作：点击“未到货”后状态同步为“在途”，只见当前店待收货调拨；进入“出库”不再报未定义函数；商品框由错误的 574 条普通目录变成 87 条有可扣减批次的 SKU，选入“丰韵霜支”后显示库存 17，取消未保存的测试单。
- 两个 Vite 构建通过。门店集成产物 `前端代码/cashier-v3/dist/assets/index-2156de59.js` SHA-256 `225f49ae28f9cb047843937324d7fab5309cd70923ed155fcc7399bfd7738728`；独立库存产物 `前端代码/inventory-vue3/dist/assets/index-43e2903b.js` SHA-256 `9655879856ae8f8b7644487c38e9610bce971c504c276b81428a26a28e573c9e`。两者都包含“未到货”与 `for_outbound_stock`，均不包含废弃函数名。产物是忽略的生成物，不提交为源码。
- 修改过的 PHP 文件语法检查通过，`git diff --check` 通过。

## 发布边界

本地 `cashier-v3` 构建包含工作区其他任务的未提交收银改动，不能直接作为生产门店包。产品经理对上述固定源码验收后，仍需先按闭环文件清单提交，再基于已提交且已核对与三个线上收银入口兼容的标准版本分别构建、部署和验证；不可重演只更新库存独立入口或回退“权益合计”的问题。当前线上瑞昊正式、瑞昊预览、渼约008的收银入口保持原状。
