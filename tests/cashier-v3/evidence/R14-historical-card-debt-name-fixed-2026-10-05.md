# R14 历史卡项欠款名称本地自测

- 基线 HEAD：`ad686ca774fdd31a96bf6d92257d265c6b8a8d14`
- 范围：会员欠款浮层的“商品或充值摘要”，以及订单中心欠款管理“来源订单”右侧的“商品名称”。两处显示同一原卡发放名称快照；普通销售欠款、充值欠款和欠款金额不变。
- 前端源码：`前端代码/cashier-v3/src/views/OrderCenterView.vue`，SHA-256 `cc3823d2a7b98fa10733e820a232d15eae9e2b31f82064c524cbb750ebe4d719`。补齐旧账号列设置中的新增列；会员浮层无页面改动。
- 后端源码：
  - `后端代码/app/services/cashier/v3/cashier/CashierV3MemberDebtProjectionServices.php`，SHA-256 `2da8ae4593229bfead2fded97f23e44821c6f1a27942bdeee18152f7bd5de8c1`。注释说明历史占位名称、订单+商品双重匹配和共享调用权限边界。
  - `后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php`，SHA-256 `fe667bd6beb0b1ec083cae7a6a0f110fe042ac40cce819fa02386ee4a8ab4ccc`。
  - `后端代码/app/services/cashier/v3/order/CashierV3OrderCenterUnifiedQueryContract.php`，SHA-256 `f3fd2bd3a2c4cdc1caf3d7ce0e0177ce109b7a159d1f7dd23641ba9545ef21ba`。
  - `后端代码/app/services/cashier/v3/order/AbstractCashierV3OrderCenterUnifiedQueryProvider.php`，SHA-256 `5f0d5eb39ea297ce7638e03ce18a67ebe6a04979a42cb960f8fc7fb10eae1005`。
- 数据库迁移、工程脚本、生成物：无。
- 自动化测试：`tests/cashier-v3/php/member-debt-historical-card-name-mysql.php`，SHA-256 `5a0686e0418113e4d5bc2f2bf2b3834edde22afb14363d566ba061320dfa6ab4`；`tests/cashier-v3/php/order-center-debt-product-name-mysql.php`，SHA-256 `9d68f57ef27f816dc6fb6c0b9134c31b8e7abe192f6cfefd34997cda48d87a31`。

## 验证

- 本地只读 MySQL 测试：`docker exec -i mohe-cashier-app php < tests/cashier-v3/php/member-debt-historical-card-name-mysql.php`，通过，历史卡项显示“老项目次数卡”。
- 截图中的 `RH-RELOAD-102-CARD-770091837327`：本地只读联查确认欠款明细仍为占位文字，匹配的原卡发放快照为“舒缓霜”。
- 本地只读 MySQL 列表测试：`docker exec -i mohe-cashier-app php < tests/cashier-v3/php/order-center-debt-product-name-mysql.php`，通过；统一列表/导出字段合同中“商品名称”紧随“来源订单”，列表与会员欠款浮层均为“老项目次数卡”。
- `php tests/cashier-v3/php/member-debt-projection-contract.php`、`php tests/cashier-v3/php/order-center-records-readonly.php`（42/0）、PHP 语法检查、`npm run build`、`git diff --check` 均通过；本地 18091 返回 HTTP 200。
- `php tests/cashier-v3/php/order-center-unified-export-contract.php` 为 34/2；失败的两条为本轮未触及的销售日期/实际下单时间旧文本断言，不是本次新增欠款列断言。浏览器工具无法读取当前标签，故未声称完成真实页面目视测试。

## 数据架构自检与边界

- 权威欠款仍是 `store_debt`/`store_debt_item`；名称只读自原订单 `user_card_holder.card_name` 发放快照，不修改欠款、权益、收款事实或统计。
- 本次没有写事件、事务或幂等变化，也没有新增数据权限：仅在原会员或门店欠款范围内按订单和商品关联名称。分页后按欠款 ID 集合批量读取，列表与导出复用统一字段合同，避免逐行查询。
- 本地仍有 1,338 笔未结清的历史卡项欠款没有关联卡名快照（其中 SWXT 1 笔）。它们的商品 ID 与卡明细 ID 也为空；不能以当前商品目录猜测历史名称，暂保留原占位文案。要覆盖这些孤立欠款，须从原导入源另行核对并受控补齐历史名称快照。
- 订单中心前后端文件已有其他未提交改动。提交时仅能暂存本需求的 hunk；如共享文件发生并行修改，须交叉核对、重新计算指纹并补验。
- 本次未提交、未部署、未推送；待产品经理对上述固定文件版本确认验收。
