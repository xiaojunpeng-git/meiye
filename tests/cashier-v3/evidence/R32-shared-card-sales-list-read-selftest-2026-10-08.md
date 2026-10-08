# R32 共享次数卡销售列表读取修复自测

- 基线 HEAD：`08a61084a80f0eb78315a0729f172065bc200cde`；补丁 SHA-256：`d015002acf0076a45ccdd174cf9d2db4b250bf24d75341312c16eca64a1ebfaf`。
- 本闭环后端源码：`后端代码/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php`，SHA-256 `07f620c6ea4ddac6d43e334b5235b28ce24098d23293291d3c8df38223bc207d`。
- 自动化测试：`tests/cashier-v3/php/personnel-snapshot-terminal-reversal-contract.php`，SHA-256 `95e2b4ea0c54f7a78ea5b0ca4621a618f7cdd29a5dae4d64a567b0eb57ee07f5`；`tests/cashier-v3/php/order-center-service-snapshot-read-contract.php`，SHA-256 `fadcaa84ce5ed36a15c1146b6ffa1ded7e3edf59e215f78ef88e6682d3ae9820`。
- 前端源码、数据库迁移、工程脚本、生成物：无。本文件仅为验收证据，不属于业务源码。

## 数据架构自检

- 权威源为已完成服务事实和核销快照；R32 历史修复使用 `consumption_performance_recorded` 与 `labor_performance_allocated` 的追加式正反事实，不覆盖原始记录。最小粒度是结账请求下的一条服务行，人员事实另按员工分配。
- 本次只修改销售订单列表与详情共用的只读投影，不产生业务事件、不改变生效时点、事务、幂等、Outbox、会员权益或报表聚合。更正金额以同租户、同结账请求、同来源行的最新有效正向 R32 消耗事实覆盖展示；未更正行继续使用原快照。退款和作废冲正仍保留最后一次人员快照。
- 历史人员名称继续使用事实快照，权限仍由原有订单查询的租户、门店与结账请求范围约束。更正查询按本页请求批量读取，瑞昊生产只读 `EXPLAIN` 命中 `idx_checkout_line`，预估扫描 10 行，无新增索引。
- 对账范围为瑞昊生产全部 12 条共享次数卡核销：10 条有效服务行均有 R32 更正事实，另外 2 条已作废；不存在有效且小数金额却未更正的服务行。10 条原快照与更正金额不同，更正金额均为整数元，数量均为 1；9 条劳动事实有冲正，1 条按当时决定保留手工分配。生产核对全程只读，没有再次修复或写入历史数据。

## 自测

- `php -l` 三个本闭环文件：通过；`git diff --check`：通过。
- `php tests/cashier-v3/php/personnel-snapshot-terminal-reversal-contract.php`：通过，覆盖 R32 旧人员去重及退款、作废、普通人员调整规则。
- `php tests/cashier-v3/php/order-center-service-snapshot-read-contract.php`：通过，覆盖同请求同服务行覆盖、其他行不变、单价和金额同时显示 ¥66、销售应付仍为空。
- `node tests/cashier-v3/js/order-center-sales-service-lines-contract.mjs`：通过。
- 瑞昊正式 PHP 7.4 对本地候选后端文件从标准输入执行 `php -l`：通过；未替换服务器源码。
- 扩展旧测试 `order-center-sales-readonly.php` 在既有“退款历史保留”断言失败后，又因未载入 `CashierV3EntitlementCheckoutIdentity` 终止；`sales-order-craftsmen-snapshot-contract.php` 在既有快照归一化字符串断言失败。本闭环未修改这些测试依赖或对应业务逻辑，不能将扩展旧测试报告为通过。
- 本次更新了原始核销快照说明、R32 更正读取边界与人员冲正规则注释；没有改动前端展示格式，用户看到的整数元来自更正后的后端金额。

状态：本地开发和针对性自测完成；待产品经理对本固定版本确认验收。尚未 commit、部署、线上验证或 push。
