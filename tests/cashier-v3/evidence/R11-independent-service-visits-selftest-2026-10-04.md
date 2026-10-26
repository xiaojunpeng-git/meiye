# R11 服务人次与不计人数人次自测记录

- 基线 HEAD：`2e07ae86808046e6fc4ad4571442de71ccbde5c8`
- 范围：员工服务人次的独立岗位分配；收银服务行“不算人数人次”按钮；统一客数资格、相关报表取值来源及测试。未修改数据库结构、生产数据或其他收银布局。
- 独立核算口径：同门店、同日、同服务对象去重后，普通人员共分 1 次；按结账时冻结的独立结算岗位分组，每组另计 1 次。组内多人按 0.1 次精度平均分，尾数补给最后一人；同员工同日同客户多项目不重复。
- 不计数口径：`friend_counts_as_customer=0` 是服务记录级开关，本人或朋友均不计人数、人次和点客次；真实服务、消耗业绩、项目数和手工费保留。旧字段与历史快照原值不改写。收银点击“不算人数人次”保留当前本人/朋友对象。
- 实数核对：瑞昊本地库 2026-10-04 服务事实 `SSF-9eec360b11a287764411a9f435440bf4cc808a79`，汪永梅（普通组）及王美红（店长独立组）各计服务人次 1.0，薪资明细及汇总一致；各自项目数 1、消耗业绩 168 元未改变。查询与导出记录一致，目标看板两人服务客数也各 1。
- 自动化：`independent-service-customer-allocation.php` 7 组断言通过，覆盖本人不计数及独立岗位；`r51-unified-service-customer.php` 的本地事务回滚用例通过，覆盖本人/朋友四种计数状态、报表 SQL/PHP 一致性、金额和项目数保留；`r51-market-friend-visits.php`、`r51-service-customer-authority-contract.php`、`friend-counts-as-customer-contract.php`、`service-customer-snapshot-contract.php` 通过。薪资跨日冲销和 12 个下钻回归通过。七个后端 PHP 文件在 PHP 7.4 下语法检查通过，前端 Vite 构建和 `git diff --check` 通过。
- 验证边界：`store-target-service-customer-readonly.php` 的本地固定夹具 `FW100200008` 已不在当前数据中，未到达新逻辑断言；浏览器连接超时，未取得新页面截图。人员事后调整若新增原服务快照中不存在的独立岗位员工，当前事实缺乏该员工冻结的岗位分组字段，仍会落入普通组；本次不改写历史事实或用现时岗位回推。

## 文件清单及 SHA-256

| 分类 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/cashier-v3/src/views/CashierWorkbenchView.vue` | `88d362c8d52818ea8eddfd6b135151a36a898d0eabea2af447588e42eee38adf` |
| 后端源码 | `后端代码/app/services/query/metric/ServiceCustomerEligibility.php` | `c4469112483105b5d531b8861654b0f58146f3fccddc13ce899c929675807e9a` |
| 后端源码 | `后端代码/app/services/query/metric/ServiceCustomerMetricReadServices.php` | `56b380e3ebb0ad8d45b6ce299e2b559f8984cbb6414c1ee3af414ec708045eda` |
| 后端源码 | `后端代码/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php` | `2038b6f27d5352151dba631f0a823580ab107fe993c30aa32fd10c1604e03070` |
| 后端源码 | `后端代码/app/services/cashier/v3/dashboard/CashierV3StoreTargetDashboardReadModel.php` | `c036cc85284382836e3f7d3ad1798c27345b7bec5d6e9976635477d67c10d1e9` |
| 后端源码 | `后端代码/app/services/metric/MetricDictionaryServices.php` | `976f66d2460ec8a9dd42e5c3b496163ace3191f91dfa11a27ce7399e94e1edcb` |
| 后端源码 | `后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php` | `47f9e1b608eafa4b6ad4a515dc62ad285f2c99788a18e654792fd772aed6ae8b` |
| 后端源码 | `后端代码/app/services/report/StoreUnifiedReportPhaseSixServices.php` | `c36c571655e35d8ebc0930dea75d2ea5cdc9fddfdd0377d379912c2b85381d59` |
| 自动化测试 | `tests/cashier-v3/php/independent-service-customer-allocation.php` | `605eb9b36208cde0a05774d5f812df2e39337975d7561527ae17db1d0d35be01` |
| 自动化测试 | `tests/cashier-v3/php/r51-unified-service-customer.php` | `093c2c1e7a674826816bbea967ef94ff2d82ffdec2014f49ff1938f18460690e` |
| 自动化测试 | `tests/cashier-v3/php/r51-market-friend-visits.php` | `6d59506d65cc4322786799e814b26dbb66c7dcf14e348815d83bdc27a4694264` |
| 自动化测试 | `tests/cashier-v3/php/r51-service-customer-authority-contract.php` | `b6b9781b69a44083b6456d893911e5c1faee4094db03b9a042db265b724e4cf8` |

数据库迁移、工程脚本、生成物：无纳入交付。上述前后端业务注释已同步更新。

状态：开发及自测完成，待产品经理对上述固定版本确认验收；尚未 commit、部署或 push。
