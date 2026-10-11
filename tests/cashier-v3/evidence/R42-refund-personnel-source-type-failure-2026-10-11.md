# R42 退款销售人来源字段异常本地自测

- 状态：本地修复与自测完成，待产品经理确认验收；未提交真实退款、未 commit、部署或 push。
- 观察：本地 `18091` 订单中心提交卡项退款时显示“操作失败，请稍后重试”。本地日志 `20261011_error.log` 在 11:56:39 记录 `refund-card-or-line` 的 `Undefined index: target_type`，位置为 `CashierV3RefundSalespersonServices.php:325`。故障请求未产生该卡的退款明细；本地只读查询 `card_holder_id=2074929` 的 `cashier_v3_refund_adjustment` 结果为空。
- 根因：新退款传入生命周期操作行，退款模式字段为 `source_type`；历史人员改派传入退款明细行，字段为 `target_type`。负向业绩事实生成器只读取后者，有退款销售人时在事务中抛错。修复仅兼容两种权威行形状，沿用原有 `manual_refund`／`imported_card_refund` 事实分类。

## 数据架构自检

- 权威源：卡权益与退款生命周期主表；每位所选销售人的一条负向业绩事实。成功退款的事件、退款行和人员事实仍由同一命令事务约束，失败回滚不生成经营事实。
- 时间、幂等、并发、权限与历史快照口径均未改变；`business_date` 和实际操作时间仍由原命令确定。原销售事实不改写，人员改派仍走冲销和新版快照。
- 本次不新增查询、指标、索引、数据库迁移或跨实例配置；只在本地统一源码修复。对账检查覆盖失败卡无退款行及测试负向业绩事实回滚后为零。生产实例、线上备份和回滚点不适用。

## 固定文件

- 基线 HEAD：`4a00cf487274dcf88460ac091567a7527ba04679`。工作区含其他任务改动；以下服务文件的整体补丁不单独代表本项。
- 后端源码：`后端代码/app/services/cashier/v3/order/CashierV3RefundSalespersonServices.php`，SHA-256 `bc2c528ff511bd3715ac5183e71614af0bdfa7511cded0a39ae439d71ce75a45`。
- 自动化测试：`tests/cashier-v3/php/r42-refund-personnel-source-type-rollback.php`，SHA-256 `ccafb1276f15a4dfb9b7ee9a274b2f84cb81efd00f7853d6fc8b7be1c06272fa`。
- 服务文件整体工作区补丁 SHA-256：`7e02448e7284508b187ef17a332f744b5524e847df43e031c6ef787258d27513`。
- 前端源码、数据库迁移、工程脚本、生成物：无。验收证据：本文件。本次更新服务文件注释，说明两类输入行的字段边界。

## 验证

- PHP 服务文件和新测试脚本语法检查：PASS。
- `docker exec -i mohe-cashier-app php < tests/cashier-v3/php/r42-refund-personnel-source-type-rollback.php`：PASS；覆盖新退款 `source_type=card`、普通改派 `target_type=card`、迁入旧卡改派 `target_type=imported_card`，负向金额和来源分类正确，外层事务回滚后测试事实为零。
- `docker exec -i mohe-cashier-app php < tests/cashier-v3/php/r42-refund-attribution-rollback.php`：PASS。R42 退款销售人与导购归属两项 JS 契约：PASS。
- 原 `r32-refund-salesperson-rollback.php` 当前被其任意选取的本地测试账号权限拒绝，未计为通过；未用用户当前退款请求做真实重试。本地 Swoole 热更新工作进程在源码改动后重新启动。
- `git diff --check`：PASS。退款真实提交结果仍待产品经理在固定版本上验收。
