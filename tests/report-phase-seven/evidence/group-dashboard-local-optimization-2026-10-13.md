# 集团看板本地优化核验（2026-10-13）

范围：本机 `ruihao` 开发库，102 家门店，2026-10-01 至 2026-10-13。未连接或修改客户线上实例。

- 优化前：同范围两次只读请求约 15.1 秒、14.7 秒；旧日汇总全量核对约 3.6 秒且不参与展示。
- 优化后：同范围看板只读请求约 10.5 秒、10.7 秒、10.1 秒。实际业绩卡片与统一 Reader 均为 200,614,600 分；102 家已设目标门店排行之和与本月目标完成值相等。本月目标 1,020,000,000 分，即每店 100,000 元。越权门店请求被拒绝；单店分类筛选仍限定在授权门店，未使用日聚合。
- 本机读模型迁移后，原有 57 行为 `legacy`。对 2026-10-01 至 2026-10-13 重建得到 842 行；统一指标逐门店日对账 `stale_group_count=0`。读模型仅供离线对账，不供页面读取。
- 只读回归脚本：`tests/report-phase-seven/php/group-dashboard-optimization-local.php`；静态契约：`tests/report-phase-seven/php/group-dashboard-stability-contract.php`。
- 全年趋势单店回归：12 个自然月实际业绩之和为 1,613,300 分，与同范围统一 Reader 汇总相等。上述 PHP 文件语法检查、静态契约和 `git diff --check` 均通过。

## 固定验收版本

基线 HEAD：`cd359dd614609d33867c86c0df69581372fb53a0`。以下为本次闭环相关文件 SHA-256；`GroupManagementDashboardServices.php` 原先已有其他分类修复的未提交改动，本轮在其上增量开发，验收与提交时需区分原有变更。

| 文件 | SHA-256 |
| --- | --- |
| `后端代码/app/services/report/GroupManagementDashboardServices.php` | `61f2c7f09f2ee91d29e60e2deaf2f62839592d84f0126a126936adc10279f84e` |
| `后端代码/app/services/report/GroupManagementDashboardDailyAggregateServices.php` | `941674c8eb4ea28733b58292834797e5b3431f65fafdf7bbfb644f49825d4976` |
| `后端代码/app/services/report/StoreUnifiedReportOrganizationDimensionServices.php` | `96db9e12bd10724477888deccde94c97f3ca1e86306eeb8ab2c423aeb5ee59cd` |
| `后端代码/app/services/target/TargetSchemeReportServices.php` | `d4fa43fbd0ee40bd4003af076cfe8ebbc34d1f067e1f9a38fb663b14854bdf30` |
| `后端代码/database/upgrades/2026-10-13-集团看板日汇总指标版本/01-正式升级.sql` | `63e8d6832c4e79d2cdedbb6271b342f2fbfa5877d19088e39f2e11898a38ef98` |
| `tests/report-phase-seven/php/group-dashboard-stability-contract.php` | `a461027efe41e2a7f9765a4953656710ec3118a5bb5a043c22c4a069dd999a4b` |
| `tests/report-phase-seven/php/group-dashboard-optimization-local.php` | `32e83239cd241be797ee433ca00d5ee3253e74460fe0fbbb502a567fb63b51c6` |

本轮注释同步更新：`GroupManagementDashboardServices.php`、`GroupManagementDashboardDailyAggregateServices.php`、`StoreUnifiedReportOrganizationDimensionServices.php`、`TargetSchemeReportServices.php`。仍缺跨支付、退款、核销、作废和回溯更正的可靠日汇总失效事件，因此不把新读模型切入页面。产品经理验收和正式实例迁移、部署、push 均未执行。

旧的 `tests/mohe-ai/cash-report-projection.php` 夹具仍调用此前分类改动已引入的 `withCurrentReportProductCategory()`，但其 mock 不提供该方法，且引用已删除的私有辅助方法；该旧夹具当前无法运行，未把它的失败冒充本次真实库回归失败。`tests/report-phase-seven/php/store-target-dashboard-contract.php` 对未改动的门店目标读取器有三项旧字符串断言失败，另行维护。
