# R15 服务人次下钻展示同单项目本地自测

- 基线 HEAD：`e42ed6ddcffda6e7ca4b1a6696da02117857498b`。工作区原有薪资报表改动未回退；本次仅追加下述规则与回归断言。
- 业务范围：员工薪资汇总的“服务人次”下钻，保留原计次行及同一结账单、同一员工的其他已完成服务项目行；补充行显示 `0.0` 人次。共享指标 Reader 的每日去重、岗位分组、人数和汇总口径不变。
- 前端源码：无新增改动。后端源码：`后端代码/app/services/report/StoreUnifiedReportPhaseSixServices.php`，SHA-256 `7d39a1f66302436ca3aff622a8d2838c2e3d614dd33c1aa8cfbacdf594e12c09`。
- 数据库迁移、工程脚本、生成物：无。自动化测试：`tests/report-phase-six/php/salary-metric-drilldown-integration.php`，SHA-256 `3ac71c74311b0bccdbf86accf9ea6b222e7a6407dd0db7d8c5702716a9d87d69`。验收证据：本文件。

## 本地只读验证

- 瑞昊本地数据副本，江苏江都店 2026-10-05，员工杜秋梅，单据 `FW100500046`：普通薪资明细为 3 行（仪器放松 2 行、科技美颜 1 行），项目数合计 3、消耗业绩合计 382 元；修改前服务人次下钻仅显示科技美颜 1 行。
- 修改后该单服务人次下钻显示 3 行，人次依次为 `0.0 / 0.0 / 1.0`，人次合计仍为 `1.0`。该员工当日下钻显示 8 条有效服务项目行，人次合计仍为 `3.0`。
- `php -l` 后端服务与回归脚本：通过；`php tests/report-phase-six/php/service-contract.php`：通过。
- `salary-metric-drilldown-integration.php`：32 组薪资指标汇总/明细对账通过，并通过本单三行回归及同单零人次范围断言。
- `salary-document-column-integration.php`：通过；`salary-later-reversal-integration.php`：排除 144 条跨日反向事实并核对 19 组分类下钻，通过。
- `git diff --check`：通过。尚未完成已登录浏览器中的人工点击验收。

## 数据架构自检

- 权威源仍是已完成服务事实与有效员工绩效事实；报表事实粒度仍为员工-服务项目行。只改变下钻列表的展示范围，不写业务主表、事实、聚合或数据库。
- 服务人次继续从统一 Reader 获取，按门店、经营日、服务对象与员工岗位分组去重和分摊；补充行只显示 `0.0`，不重新计算人次。业务事件、四类时间、事务、并发、幂等、冲销、历史快照与对账口径不变。
- 服务端先按租户、授权门店、日期和员工收紧范围，再取同一结账单的已完成服务行；纯销售行不因同单而进入。原查询路径和索引不变，仅对已读取的薪资行作一次线性归组。
- 新增/更新业务注释：`后端代码/app/services/report/StoreUnifiedReportPhaseSixServices.php`，说明同单零人次行的展示边界和统一 Reader 的计数所有权。

状态：本地实现和自动化自测通过；待产品经理对当前固定版本验收。未 commit、未部署、未 push。
