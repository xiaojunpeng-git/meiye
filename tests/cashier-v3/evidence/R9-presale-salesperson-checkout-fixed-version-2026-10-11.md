# R9 售前销售人结账快照修复，本地待验收

- 基线 HEAD：`04cb694310c9abb31d63333b3ae1482c51518bfe`
- 范围：销售人售前标记从收银快照经结账请求、人员身份复核、结账投影及销售单据快照保留；订单中心沿用现有业绩事实的 `roleSnapshot` 展示，不修改收银页面。
- 现有样本 `XS26101100005`：本地结账请求快照没有售前标记，业绩事实为 `salesperson:postsale`。按产品经理本轮答复，历史订单不修改，也不对线上做任何操作。
- 风险边界：旧售后订单的快照与指纹保持稀疏格式；仅明确选中的售前写入 `isPreSale: true`。历史事实更正必须另走可审计的人员调整，不能覆盖原单或直接改事实。

## 文件与 SHA-256

| 类别 | 文件 | SHA-256 |
| --- | --- | --- |
| 后端源码 | `后端代码/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php` | `a94af4480880d98ffe155456dc044010ec86661fba93a90e78a67a1b4631bdf4` |
| 后端源码 | `后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutSettlementKernel.php` | `3d676ca1cc01c75a92a7fe3c82edaf0a61e127bfb0a0ea42daf65ca964111eae` |
| 后端源码 | `后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutProjectionServices.php` | `ee7bc99c8b970e60d0b14f9e72f1e166300da4fcfe5df657e22968f3eb5215df` |
| 后端源码 | `后端代码/app/services/cashier/v3/order/settlement/CashierV3SalesOrderPlanV1.php` | `e72c279fcc4724c8c312ea0e6a82c2140a8a482b35c9aa66b998b63221d5f011` |
| 自动化测试 | `tests/cashier-v3/php/c2-checkout-settlement-contract.php` | `fb63a9733d966063af2c1209290344fa352ddbccc3c542f12de2c9494505a163` |
| 自动化测试 | `tests/cashier-v3/php/r9-presale-salesperson-snapshot.php` | `66efc212d4eec129e3e86cef7da6a880747b01a42d21dac1ff57d90ece2838f7` |

已跟踪文件补丁 SHA-256：`5a5747a03d2572d934a11e2122eefd7c00a52b15ebbf6928fd945e5fc2260978`；新测试文件的 SHA 见上表。

## 本地自测

- `php tests/cashier-v3/php/c2-checkout-settlement-contract.php`：91 项通过；覆盖售前结账请求持久化、投影指纹及最终权威快照重建。
- `docker exec -i -e CASHIER_BACKEND_ROOT=/var/www/html mohe-cashier-app php /dev/stdin < tests/cashier-v3/php/r9-presale-salesperson-snapshot.php`：通过；只读事务验证人员复核保留售前，回滚事务，未写业务数据；同时验证销售单据售前/售后快照兼容。
- `node tests/cashier-v3/js/order-center-personnel-display-contract.mjs`：通过；现有订单中心销售人格式可显示售前。
- `php tests/report-phase-six/php/service-contract.php`：通过；本轮第一个薪资报表闭环未受本修复影响。
- 4 个后端源码文件及新 PHP 测试通过语法检查；`git diff --check` 通过。
- `tests/cashier-v3/js/checkout-personnel-closure-contract.mjs` 未通过，其既存断言要求页面源码含 `localCheckoutPreview.value = localCheckoutPreviewSnapshot()`；当前工作区该测试文件已有其他未提交修改，本闭环未改该文件或对应前端页面。

业务代码注释更新：上述 4 个后端源码文件均补充或更新了售前快照、旧指纹兼容和身份复核边界说明。前端源码、数据库迁移、工程脚本、生成物：无。验收证据：本文件。状态：开发与自测完成，待产品经理对本固定版本确认验收；未 commit、未部署、未 push。
