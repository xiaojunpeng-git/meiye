# R15 员工薪资报表本地最终验收版本

- 基线 HEAD：`e42ed6ddcffda6e7ca4b1a6696da02117857498b`。
- 产品经理验收：原本地版本测试无问题；发布前修正瑞昊 PHP 7.4 前缀判断兼容性，并将跨单回归改为自动发现业务事实后，产品经理确认更新版验收通过，授权依次 commit、部署瑞昊、线上验证、push。
- 本闭环 10 个源码/测试文件 SHA-256 清单的指纹：`c49c58980c2f6576a897a1a35168aa8919bd26f0226a93e9e9ff4d5aa7849ba4`。
- 业务范围：两张薪资表的岗位、明细单据号筛选、汇总四项精确下钻、列名取值来源、下钻“返回”及原筛选恢复；服务人次下钻补全同单及同会员本人/同门店/同日/同员工的跨单服务项目，零份额行显示 0.0，不改变共享客数计算。

## 文件分组

- 前端源码：`前端代码/cashier-v3/src/views/StoreBusinessReportView.vue`。
- 后端源码：`后端代码/app/services/report/StoreUnifiedReportPhaseSixServices.php`、`后端代码/app/services/query/metric/ServiceCustomerMetricReadServices.php`、`后端代码/app/controller/admin/v1/report/UnifiedReport.php`、`后端代码/app/controller/cashier/v3/Report.php`、`后端代码/app/controller/store/report/UnifiedReport.php`。
- 自动化测试：`tests/report-phase-six/js/contract.mjs`、`tests/report-phase-six/php/service-contract.php`、`tests/cashier-v3/php/independent-service-customer-allocation.php`、`tests/report-phase-six/php/salary-metric-drilldown-integration.php`。
- 数据库迁移、工程脚本：无。生成物：门店端构建 `dist/`，只作部署产物，不作源码提交。验收证据：本文件及本目录 R15 本地自测记录。
- 业务注释：已在上述 Vue 视图、报表服务、共享客数读取器及三个控制器中说明返回上下文、客数分摊、权限与筛选边界。

## 自测与架构边界

- 32 组指标汇总/明细对账通过；跨单样本由有效服务事实动态发现，逐行核对所点员工、门店和业务日的应展示服务项目。原订单号和固定行数均不作为运行规则或回归预期。
- 薪资单据列/导出联测通过；跨日期冲销 144 条事实、19 组分类下钻对账通过；报表服务契约、员工独立核算份额测试、前端契约与 44 项门店报表契约通过。
- 门店端 `npm run build` 通过，1989 模块；仅既有大包提示。后端报表服务经瑞昊 PHP 7.4.33 语法检查通过；`git diff --check` 通过。
- 报表仍只读已完成服务事实、有效人员业绩事实和统一客数读取器；门店及员工稳定 ID 由后端权限裁剪。没有新增事实、写入、迁移、重算历史或改变人次指标；下钻零份额行不增加汇总值。业务日期、冲销、快照及导出口径沿用现有报表服务。

本文件记录提交前固定版本；部署构建、备份、运行指纹、线上只读验证与 push 结果另行登记。
