# R39 门店目标看板查询别名修复自测

- 基线 HEAD：`b356b81cbe5490c565a7acbfad4bd0b5c98fe561`。
- 闭环：修正目标看板收款事实查询在作废过滤原生 SQL 中引用未加物理表前缀的逻辑表名，改用明确的 `pf` 别名；不改变指标公式、筛选或页面。
- 前端源码：无。
- 后端源码：`后端代码/app/services/cashier/v3/dashboard/CashierV3StoreTargetDashboardReadModel.php`，SHA-256 `03013d2f6e693eb25c35b1b10311176d7a810bdd523e44776fe582f7768ad042`；补充注释说明 ORM 表前缀与原生关联条件的边界。
- 数据库迁移、工程脚本：无。
- 自动化测试：`tests/report-phase-seven/php/store-target-dashboard-contract.php`，SHA-256 `8fd2ae351529ae6f36547be38a0dc8b2e5a04ee318e7ebbe955a0c6d58261e13`。静态契约新增别名检查，`tests/report-phase-seven/run-static.sh` 全部通过；PHP 语法和 `git diff --check` 通过。
- 补丁指纹：所涉两文件的 `git diff` SHA-256 为 `068a7daf6c80eb802fed4eca15a7038430f814743358a97d629b79cf2b802f89`。
- 本地真实读验证：在 `mohe-cashier-app` 以只读方式查询肥西水晶城（门店 133）2026-09-01 至 2026-09-28 看板，返回 `availability=active`、当月完成 8,447,300 分、现金排行 6 人、消耗排行 10 人。重启本地收银 API 后，`18091` 目标页刷新显示月度、年度目标及两类排行，原 `ACTION_DEPENDENCY_NOT_READY` 提示消失。未写入业务数据。
- 数据架构自检：权威收款、人员业绩与服务事实及门店月目标源未变；事实粒度、业务日、签名冲销、服务端门店权限、历史人员快照、指标口径和读取性能边界均未改动。此次只修复 SQL 别名，不引入新事实、迁移或客户端计算；以真实只读结果及静态契约对账。
- 生成物与其他验收证据：无新增生成物；浏览器当前标签保留可查看的本地修复结果。
- 状态：开发与自测完成，待产品经理确认固定版本；未提交、未部署、未推送。
