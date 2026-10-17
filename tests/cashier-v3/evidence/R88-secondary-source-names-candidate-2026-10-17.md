# R88 二级来源历史名称同步固定候选

## 范围与状态

- 用户确认改了后台全部二级来源名称，要求只刷瑞昊所有可按来源编号关联的旧名称快照，不改功能。
- 本候选为一次性数据维护，不部署前后端，不改统计逻辑，不操作008，不 push。
- 已完成只读盘点、PHP 7.4 隔离测试及独立恢复点；尚未执行生产 UPDATE，尚未提交，待产品经理固定候选验收。
- 唯一源码基线 HEAD：`208bcb073575564b33b0cc0b1a358f1a15b9e9db`。共享工作区有其他任务改动，均未纳入本轮。
- 瑞昊目标：`rh-server`，站点 `/www/wwwroot/rh.cc3798.com`，数据库 `127.0.0.1:3306/ruihao`；最新升级日志 id247，key `20261017-041-deal-people-order-threshold`。
- 本次无运行代码变更，线上 JS/CSS 与共享后端文件替换不适用；不改变已上线功能。

## 固定业务结果

| 二级编号 | 当前名称 |
| --- | --- |
|16|E合作方|
|118|E自家导购|
|119|H觉知|
|120|D抖音|
|121|D美团|
|122|H睡眠激活|

按现有二级编号关联配置并验证父子关系，仅更新二级名称快照及完整来源标签。保留一级名称、两级编号、归属类型、金额、日期、状态和其他全部列。完整标签使用原有一级名称加新二级名称，不改变原归属。零编号、无法关联及父子不匹配不猜测；审计和历史事件原始载荷保留原状。

| 表 | 待更新行 |
| --- | ---: |
|eb_cashier_v3_balance_fact|12|
|eb_cashier_v3_checkout_business_source_selection|286|
|eb_cashier_v3_customer_lifecycle_fact|102|
|eb_cashier_v3_customer_lifecycle_projection|19|
|eb_cashier_v3_debt_repayment|1|
|eb_cashier_v3_entitlement_service_fact|227|
|eb_cashier_v3_payment_fact|98|
|eb_cashier_v3_performance_fact|789|
|eb_cashier_v3_recharge_debt_repayment|0|
|eb_cashier_v3_reservation|21|
|eb_cashier_v3_sale_fact|357|
|eb_cashier_v3_sales_order|252|
|eb_user_recharge|2|
|合计|2166|

样本订单 `XS26101700432` 目前来源编号4/16、二级名称“合作方”；预期名称为“E合作方”、完整标签为“E地推拓客 / E合作方”。

## 文件与指纹

- 前端源码、后端业务源码：无修改。
- 数据维护正本：`后端代码/database/upgrades/2026-10-17-瑞昊二级来源名称同步/repair.php`
  SHA-256 `d1c0d1eb950dea3cedcae07c1f7055a50ef6a25c0c87aa6c9b2afceef2bfb37e`。
- 自动化测试：`tests/cashier-v3/php/r88-source-name-repair.php`
  SHA-256 `f23d32d05d08822a8d57f47c21631f74cec6c5d4f83e9883520e1e6b3e455839`。
- 只读盘点工程脚本：`scripts/r88-source-name-inventory.php`
  SHA-256 `c4e2476cbf73181287e6cf579a7c0677bebecc1213129211c0a8297d2d7c72a3`。
- 验收证据：本文；本轮注释说明见以上维护脚本、测试与盘点脚本，均注明职责及禁止修改的边界。
- 生成物/独立恢复文件不入 Git：本地 `/private/tmp/r88-source-names-plan.json`，服务器 `/www/backups/rh.cc3798.com/r88-source-names-20261017/plan-9c6c3780.json`。
  两处 SHA-256 均为 `9c6c3780c6143545d081259e25ec24868d60eee5b94831b1615a5f5dbc1d1b78`。

## 验证与执行保护

- PHP 7.4.33 本地随机隔离数据库，13张表、26条名称更正及恢复、9项断言全部通过；测试库已删除，不改本地业务库。
- 覆盖名称更新、一级历史名称保留、无效关联不改、其他业务字段不变、恢复后与原清单一致、更新后重盘点为零、并发业务改动拦截、配置改名拦截、异常标签拦截。
- 生产只读确认目标表均为InnoDB，无自动更新时间列，无目标表触发器。
- 生产执行要求独立恢复文件路径和完整SHA-256；限定生产host/database，拒绝本地同名镜像。
- 执行前重新校验结构和当前来源配置；事务内逐行加锁、核对全部非名称列哈希，且更新后逐列验证仅两列变化。任一差异整批回滚。
- 恢复模式读取同一文件，仅恢复两列；并发业务或名称变化时拒绝覆盖，不自动回退其他人的修改。
- 验收后先按本轮精准文件清单提交维护材料，再执行用户已授权的瑞昊数据刷新。执行结果须另行记录实际行数、剩余差异、样本订单及页面验证，不以本候选文档代替完成证明。
