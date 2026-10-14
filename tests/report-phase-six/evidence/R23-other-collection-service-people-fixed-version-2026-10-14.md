# R23 其他收款来源服务人数：固定自测版本

- 基线 HEAD：`eb5a21a5f6b1c7b2136ef812123fc3e771e7503e`
- 状态：本地开发与自测完成，待产品经理对本固定版本确认验收；未 commit、未部署、未 push。
- 业务口径：服务使用的权益卡或项目，其可追溯的原结算订单只要含正金额、已提交的“其他收款”，该服务不计所选期间的服务人数；混合收款同样排除。实际服务人次、点客、项目数、消耗和手工费不变。同一会员另有合格服务时，合格服务仍计人数，且不由被排除服务的员工分摊。
- 来源边界：直接购买项目以服务事实关联的原销售单为准；卡项领用以核销事实、购卡凭据或升级结算关联的原销售单为准。旧卡没有可信的 V3 原结算链时不按名称或当前支付方式猜测。
- 数据边界：仅更改读取口径，不更新服务事实、销售单、收款草稿或历史报表数据；查询按租户、门店、日期及现有作废排除条件执行。明细、汇总、目标排行、指标字典共用同一人数读取器。

## 文件清单与 SHA-256

| 分组 | 文件 | SHA-256 |
| --- | --- | --- |
| 后端源码 | `后端代码/app/services/query/metric/ServiceCustomerMetricReadServices.php` | `0ec6ae2d631f3fef011f1e437aff47a8fb2543bb928f6ac535d894b84f241e93` |
| 后端源码 | `后端代码/app/services/report/StoreUnifiedReportPhaseSixServices.php` | `df93b9da85e39fcf015ee4d272616924540505ddbbfa1c58e2f69cfba6aa393f` |
| 后端源码 | `后端代码/app/services/metric/MetricDictionaryServices.php` | `f328aa4b3cd1d9b69e0b429c379e62190d3ce7fc2ee4d07d2db1fc7cb50d0d4f` |
| 后端源码 | `后端代码/app/services/query/metric/MetricDefinitionRegistry.php` | `0979e04b8d58b9e11f8020c8582279befadd04c71fcf8c578dea55121e7752b3` |
| 自动化测试 | `tests/report-phase-six/php/r23-other-collection-service-people.php` | `22b956defbea22ce282f7c229eebfaa4a97e0aeca7e56fb4638e8345f836e7d2` |

上述四个后端文件的当前工作区补丁指纹：`fa21be73d56db30b11da5e462eec164a9c26dc569a6cf4a6f38f8b7b28466b33`。其中部分文件在本轮开始前已有其他未提交改动，指纹包含这些既有改动；它用于固定当前可验收状态，不声明整份 diff 都属于 R23。提交时必须复核并仅暂存本轮改动。目标看板只沿用本轮共用读取器；其既有未提交改动不纳入本闭环。

前端源码、数据库迁移、工程脚本、生成物：无。此文档为验收证据，不是业务源码。

## 验证

1. 六个本轮文件 `php -l` 全部通过；相关后端文件 `git diff --check` 通过。
2. `php -d error_reporting='E_ALL & ~E_DEPRECATED' tests/report-phase-six/php/r23-other-collection-service-people.php`：七项通过，覆盖全额和混合其他收款、同会员后续合格服务、共同服务分摊、明细/汇总一致、零手工费回退、原单 SQL 约束及字典说明的客次/人数区别。
3. `php tests/report-phase-six/php/service-contract.php`：既有第六阶段报表合同测试通过。
4. 本地容器运行 `salary-metric-drilldown-integration.php`：32 个薪资汇总/明细下钻指标对账通过；测试只读。
5. 本地数据库只读联调：2026-10-11 门店 175 的有效其他收款服务，薪资汇总显示服务人次 `1.0`、服务人数 `0.0`；门店 39 同日有其他合格服务，汇总与明细均为人次 `2.0`、人数 `1.0`。门店 168、179 的实际卡项领用服务均识别为原单含其他收款。
6. 门店 168 在 2026-10-01 至 2026-10-14 的 `EXPLAIN` 使用现有范围、关联索引，关联子查询各约 1 行；本地单次汇总读取约 78 ms。此数值仅为本机样本，不代表线上 SLA。

## 注释与限制

本轮在服务人数读取器补充原结算关联与“只排除人数”的边界注释，更新薪资报表和指标字典的客次/人数说明。无可靠 V3 原销售单关联的历史导入卡、跨次升级中的更早资金来源无法凭当前事实证明含“其他收款”，本轮不追溯推断；若后续要求覆盖，需先确定可信历史来源与迁移口径。
