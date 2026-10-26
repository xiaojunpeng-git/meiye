# R24 无原单退款分类固定版本（2026-10-14）

## 范围和原因

- 瑞昊预览站退款单 `TK2610110001` 的“商品分类”显示为“—”。只读核对：退款类型 `catalog`，退款 adjustment 保存的商品 SKU ID 为 `250596`，旧请求的 `_productCategoryNameSnapshot` 为空；该 SKU 严格关联平台商品 ID `252292`，门店 ID `18`，商品分类 ID `26315`，平台当前分类为“昊美”。
- 既有订单中心投影只为旧卡项退款回读平台分类；无原单退款仅展示退款快照，因此这笔旧记录缺分类。平台正式及预览入口尚未应用本轮补丁，不能据此宣称线上已修复。
- 本轮只调整退款列表/导出的只读分类投影：保存的退款分类快照优先；旧记录没有快照时按 `退款商品 SKU ID -> 平台商品 ID -> 分类 ID -> 完整分类路径` 读取平台**当前**分类。校验 SKU、门店和平台商品类型，不按名称匹配，不改写历史事实，不改变金额、退款或权限口径。

## 固定版本

- 基线 HEAD：`aff64e5f115d6078911c5640d7df5e007e9b0040`。
- 业务源码：`后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php`，SHA-256 `224aa8e8b9c34bd8e330c1f1a38aae443b5ebe836fe0cfa78c5fa2ef13cf4930`。
- 自动化测试：`tests/cashier-v3/php/r42-refund-product-columns-contract.php`，SHA-256 `2c1f9f925edb703f2591cfa4762bfcab5c824a8b0d89621b97fb33505ea358cd`；`tests/cashier-v3/php/r24-refund-catalog-platform-category-mysql.php`，SHA-256 `aa1c8f4bae9294dc6e57702c9a64a0fe0b2846b21259268cd30c3c02953a5e73`。
- 已跟踪文件的补丁 SHA-256：`9678ba201616c6fc7086182303211a7e82495553a1d1fa05d216691c721bcfa2`；新增测试单独以上述文件指纹固定。
- 前端源码、数据库迁移、工程脚本、生成物：无。验收证据：本文件。此次补充/更新了读取器中旧记录分类回退、SKU/门店校验及快照优先级的业务边界注释。

## 自测

- `php -l`：服务源码及新增集成测试均通过；`git diff --check` 通过。
- `php -d error_reporting='E_ALL & ~E_DEPRECATED' tests/cashier-v3/php/r42-refund-product-columns-contract.php`：PASS，覆盖旧 catalog 回退及退款快照优先。
- `docker exec -i mohe-cashier-app php < tests/cashier-v3/php/r24-refund-catalog-platform-category-mysql.php`：PASS；SKU -> 商品 -> 分类路径、跨门店/错误 SKU/已有快照拒绝回退、旧无原单退款 `TK2610100012` 完整订单中心列表投影（`花园/花园产品`）。只读本地数据库。
- `docker exec -i mohe-cashier-app php < tests/cashier-v3/php/r20-refund-card-platform-category-mysql.php`：PASS，旧卡项退款回归含 `TK2610130002`。
- `php -d error_reporting='E_ALL & ~E_DEPRECATED' tests/cashier-v3/php/order-center-records-readonly.php`：44/44 PASS；列表及导出使用同一订单中心记录投影。
- 本地 PHP 容器实际加载的读取器 SHA-256 与上述业务源码一致。瑞昊线上只进行了只读关联核对，未写入数据库、未部署。

## 门禁和限制

- 状态：开发与自测完成，待产品经理对以上固定版本明确确认验收；尚未 commit、部署或 push。
- 老记录回退的是平台**当前**分类，不冒充退款当日分类；商品已删除、SKU/门店关联无法核实或分类层级不完整时继续留空。新记录仍使用原退款分类快照，分类后续调整不会改写历史快照。
- 上线需另获明确授权，并分别核对瑞昊正式和预览入口的资源指纹与业务结果。
