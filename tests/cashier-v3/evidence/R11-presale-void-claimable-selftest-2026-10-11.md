# R11 预售订单作废后待领用资格未关闭

## 现象与权威源

- 本地订单 `XS26101100010` 的权威销售单 ID 为 `CSO-97df5f1c00f83b4a836bf5cd066d6c4c3a817d97`，生命周期作废操作 `OLO-4BC1CBAA36E96C419C97E78647A912433E5D7368` 已成功。
- 对应 `cashier_v3_presale_claimable_line` 只有一条 `AVAILABLE`、总量 1、已领 0；`cashier_v3_presale_claim` 没有对应出库单。因此页面所指不是一张已出库单，不能伪造领用单或执行库存退回。
- 同库只读筛查原发现 6 笔已成功作废订单仍有 6 条 `AVAILABLE`/`FULLY_CLAIMED` 预售资格，6 条均已领 0；本次仅获准修复指定的本地订单，其他 5 笔未处理，瑞昊线上未连接或修改。
- 现有作废计划仅以 `gift.consumed`/`entitlement.writeoff.completed` 判定预售域；纯预售产品只建立待领用行，没有这两类事件，故跳过已有的 `closeForSalesOrderReversalInTx`。

## 修复与数据架构自检

- 作废计划补查同租户、同销售单且 `source_kind=PRESALE` 的权威待领用行；外层订单事务继续调用既有关闭服务。关闭仅更新资格状态、版本、关闭操作号及实际关闭时间；不物理删除销售、领用或库存事实。
- 关闭服务增加来源类型约束，不会误关闭独立赠送产品资格。已实际领用的单据和原批次库存保持不变；退回实物及作废领用单是独立业务操作，不能由财务作废推断。
- 事实粒度与时点：销售作废原反向事实链不变；本项只修正同订单预售资格投影。`closed_at` 采用真实关闭时间，不倒填订单作废时间。无新增报表指标、跨端计算、权限范围或迁移；按租户与原单 ID 走既有索引，资格行在事务内锁定、按版本更新，重试时已关闭行不重复更新。
- 本地历史修复已在事务中执行：先校验数据库为本机 `mysql/ruihao`，订单号、门店、原成功作废操作、唯一预售资格行、版本 1、已领 0 和领用单 0；再通过同一关闭服务只更新 `PCL-97F0808D153813EEC6BE8F6D6FD96209708E53FB`。该行从 `AVAILABLE`、版本 1 变为 `CLOSED_AFTER_SALE_REVERSAL`、版本 2，`close_operation_id=OLO-4BC1CBAA36E96C419C97E78647A912433E5D7368`，`closed_at=1791709044`；领用单仍为 0。原订单、作废操作和库存事实未改，实际修复时间没有倒填为作废时间。
- 修复后再次查询：目标订单开放资格 0 条，其余同类历史资格仍 5 条。若须回滚此单，必须先确认此后没有新的领用或状态变化，再按修复前快照恢复该行的 `claim_status=AVAILABLE`、`version=1`、`close_operation_id=''`、`closed_at=0`、`updated_at=1791708207`；不得直接重开已作废订单的可领用资格。

## 验证

- PHP 7.4 两份业务服务语法检查通过；两份测试文件 PHP 语法检查通过；`git diff --check` 通过。
- 新增本地事务回滚集成测试 `PRESALE_ORDER_VOID_CLAIMABLE_LOCAL_OK`：已修复纯预售订单仍进入预售域、另一条开放资格关闭后回滚仍为原状态；另取已有实际领用样本验证关闭资格不作废出库单、不增加库存。
- 原 `order-lifecycle-contract.php` 新增的预售断言通过；该合同脚本当前全量运行仍有 4 个其他断言失败（服务作废前端版本、订单详情终态、人员快照、退款口径），未将它冒充为全量通过，也未在本轮顺带改这些业务。
- 修改的业务注释：`CashierV3OrderLifecycleServices.php` 说明无独立事件的预售资格仍须进入作废计划；`CashierV3PresaleClaimServices.php` 说明已领用单与库存不可随财务作废自动回退。

## 交付范围

- 前端源码、数据库迁移、工程脚本、生成物：无。
- 后端源码：`CashierV3OrderLifecycleServices.php`、`CashierV3PresaleClaimServices.php`。
- 自动化测试：`order-lifecycle-contract.php`、`presale-order-void-claimable-local.php`。
- 验收证据：本文件。开发与自测完成，待以最终文件指纹固定版本交产品经理确认；尚未 commit、部署或 push。
