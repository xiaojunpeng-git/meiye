# 市场明细补交作废可见性：本地自测

日期：2026-10-03。基线 HEAD：`68c2cda779c772a4cce0d674fe092f4aa578397f`。

## 范围与数据架构

- 权威金额源是 `cashier_v3_payment_fact`，最小粒度为一条正向或冲销收款事实；作废权威源是已成功的 `cashier_v3_order_center_void_operation`。
- 正常经营报表按租户、门店及原补交单识别已作废补交，同时隐藏原事实和反向事实，跨业务日期也不残留原单。审计事实不删除、不改写。
- 市场明细、市场业绩、合计及导出共用第二阶段报表的 `cashFacts` 查询。原有组织、门店和参与人员权限、成功终态、日期范围、退款规则均未改动。
- 本次仅改读模型；不产生业务事件，不改变交易、幂等、收款事实或历史快照。现有 `idx_ai_cash_source_guard` 覆盖作废操作的租户、来源类型、原单号和状态查找；无迁移和生产写入。

## 固定文件

| 分类 | 文件 | SHA-256 |
|---|---|---|
| 后端源码 | `后端代码/app/services/report/StoreReportNormalDataScopeServices.php` | `3581781071b50886929d082898e7f0bc33e57a2186ca14a8d06263824cd74e5b` |
| 后端源码 | `后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php` | `8cad0501b14dedc0c61940066fa52b4ca0cf4e4598d4173de078c176806b2e60` |
| 自动化测试 | `tests/cashier-v3/php/market-performance-service-visit-contract.php` | `134840d6fc269d8e2d661bbb9dae475f5b98b041131ef04f5e47a3606994e77f` |

前端源码、数据库迁移、工程脚本、生成物均无本轮改动。上述两份后端源码已补充或沿用说明正常数据范围及作废边界的注释。

## 验证

- PHP 语法检查：两份后端源码及合同测试通过；`git diff --check` 通过。
- `market-performance-service-visit-contract.php`：18/18；市场明细合计：8/8；有效人员下钻：5/5；固定表格：5/5；会员年度收款：8/8；会员日聚合：通过。
- 瑞昊线上只读对照：11 笔成功作废补交对应 22 条正反向有效收款事实，签名金额合计为 0。截图会员当日旧查询为 2400 元；按本次正常数据排除条件，仅剩另一笔未作废补交 1200 元、1 张有效补交单。
- 两个既有静态测试未通过：`phase-two-report-projection-contract.php` 要求当前源码不存在的 `first_course_completed` 字面量；`store-operations-report-contract.php` 要求旧的会员日去重 SQL 字面量。失败断言不涉及本次补交可见性，但全量静态套件不能宣称通过。

状态：开发与本地自测完成，待产品经理对上述固定文件确认验收。尚未 commit、部署、修改瑞昊数据或 push；线上页面仍运行旧口径。
