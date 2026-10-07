# R29 门店到店与人员客数口径拆分：本地自测与固定版本

- 状态：开发与本地自测完成，待产品经理确认验收；未 commit、未部署、未 push、未改生产库。
- 基线 HEAD：`97aa7915abdf43f8553cec766f9f257b5b4b10be`。本工作区另有其他任务/用户改动，本闭环只包含下列文件；验收前如文件变化，重新固定指纹并补验。
- 业务口径：`friend_counts_as_customer`（“不算人数人次”）仅控制员工服务人数/人次；服务事实冻结的 `is_experience`（“体验”）控制门店到店/进店/活客/市场人次。体验服务仍保留真实服务明细、金额、消耗、手工费及专门的“体验人数/见诊人数”；员工统计不改口径。
- 底层结论：两个标记在结账/核销计划与服务事实上分别冻结，没有数据结构或写入耦合；误差来自若干读取器把人员资格当作门店资格，或在门店到店读取时未判断体验。新建 `StoreCustomerVisitEligibility` 统一 SQL/PHP 资格；不回写历史事实、不新增迁移。
- 影响范围：市场业绩/明细、会员进店与年度到店、地推护理槽位、异业首次到店、客户概况/到店数据分析、运营月报到店/活客、拓客来源及门店健康、集团来源看板、会员管理看板到店频次/睡眠、会员列表/筛选/详情、动态客群最近到店及注册会员到店指标。财务金额、实际服务项目和员工薪资统计沿用原事实口径。
- 前端源码：无页面呈现变更。后端源码、自动化测试见下表。数据库迁移、工程脚本、生成物：无。验收证据：本文件。

## 自测

- 事务回滚集成：`r51-unified-service-customer.php` 通过。覆盖体验/非体验 × 人员开关 0/1 的 SQL/PHP 一致性、会员同日多项目去重、市场/会员年度结果、体验专项人数、金额不变、员工统计仍仅看人员开关；测试数据回滚。
- 事务回滚集成：`market-experience-visits.php`、`r51-market-friend-visits.php` 通过。覆盖本人/朋友/游客、明细保留、汇总/下钻/导出与来源归属。
- 本机预览只读核对：2026-10-07 江苏金湖桓裕店伍华的市场明细“人次”已显示 `1`（原为 `0`），金额仍为 ¥198；合计人次同为 `1`。未修改页面或业务数据。
- 本地只读跨报表冒烟：`r29-store-visit-report-smoke.php` 15/15 通过，包含市场、会员进店、地推、异业、体验专项、运营健康、客户分析、拓客/院店健康、会员/集团看板、会员列表到店投影及生命周期到店投影。
- 静态契约：R29 跨入口、R51 资格、会员年度、统一会员查询、市场明细/业绩、客户分析、第六阶段、会员/集团看板相关合同通过。变更 PHP 语法与 `git diff --check` 通过。
- 第三阶段现有 `service-contract.php` 在“平台补充记录必须显式注册报表权限”断言失败；该断言检查的是本轮未修改的平台注释权限路径，不以其通过冒充本轮验收。其余该文件前序断言均通过。
- 新增/更新业务注释：本轮后端源码文件中门店资格、实际护理/金额保留、员工指标边界及冻结维度投影的关键分支；测试文件注释同步口径。

## 固定版本 SHA-256

| 类别 | 文件 | SHA-256 |
| --- | --- | --- |
| 后端 | `后端代码/app/services/query/metric/StoreCustomerVisitEligibility.php` | `dd75782490c25aebd6aebb8833464677174ca5f4e16eb577b183bcfebd142abd` |
| 后端 | `后端代码/app/services/query/metric/ServiceCustomerEligibility.php` | `1e2548c398db30ec7104e35f415a09e69702df8e1e3dafa8e63701b1963fb77d` |
| 后端 | `后端代码/app/services/query/metric/RegisteredMetricReadServices.php` | `dad881f33448c55addacf836b1c1ee3d73d535c61a8b94eafffadf234ba6d85c` |
| 后端 | `后端代码/app/services/query/metric/MetricDefinitionRegistry.php` | `1d8414814adc2c5b3731ea67be795912b6787e2b92f0fba54c3ddf7b0edbff7e` |
| 后端 | `后端代码/app/services/metric/MetricDictionaryServices.php` | `65448ba33bef160dbe603b3e40297bccba547242de60fbc86b25bea56c6492cb` |
| 后端 | `后端代码/app/services/query/provider/MemberUnifiedQueryProvider.php` | `7cd7b77723b522d87b21ec6a450591a2472219d3e10d29aaf3ec1ef8e64f3c20` |
| 后端 | `后端代码/app/services/mobile/customer/MobileCustomerAudienceOverviewServices.php` | `f0ed59fb9bcc049707568a209429f15684198ece5bd94a4560d3909f6c4e2707` |
| 后端 | `后端代码/app/services/cashier/v3/member/CashierV3MemberDetailQueryServices.php` | `98f8253f0a96407466fb3e0880eb6dc81fff1af641f2e1cdbe9f57a6f4514675` |
| 后端 | `后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php` | `4a647b53431ce76bddcbec77dcaace60215c27b25690e35dc6fc2f33724a3c79` |
| 后端 | `后端代码/app/services/report/StoreUnifiedReportPhaseThreeServices.php` | `ee21b852928d598caa76957e59147d8e91dcda10e72b9d69cda961761f2f3bd9` |
| 后端 | `后端代码/app/services/report/StoreUnifiedReportPhaseFourServices.php` | `7cb687ab2d6fd9a573982ddd80325d3c8f6049b56584c2a6897c4d1cc24a1ba8` |
| 后端 | `后端代码/app/services/report/StoreUnifiedReportPhaseSixServices.php` | `467545ae65e1e57be99091e6551b602f89013324bf428aaad0f1a05206bc88f2` |
| 后端 | `后端代码/app/services/report/StoreUnifiedReportServices.php` | `595985d33796641a9eb24b7ca5a7e135923063b88291a5df5c64d831e9b180ee` |
| 后端 | `后端代码/app/services/report/CustomerAnalyticsServices.php` | `bcad60772714d9524acb1967a6e9b91ca56735d40feb518c8f5a27f7851f1629` |
| 后端 | `后端代码/app/services/report/CustomerLifecycleNormalReadServices.php` | `0c685ff5c0b0c2c48a28fad94f433a7e765dbb21a88265fc099630d1f394436d` |
| 后端 | `后端代码/app/services/report/MemberManagementDashboardServices.php` | `42c5d60c593ca7f304e449a5bc4c8a606ea947faae1683af84ae59fe41419a41` |
| 后端 | `后端代码/app/services/report/GroupManagementDashboardServices.php` | `3de0d7ca10f105343063c0abf07d3cb04eccbb1368a1f57a9448793b4c38f930` |
| 测试 | `tests/cashier-v3/php/market-experience-visits.php` | `a485b6650b89bd2f3a40cfa69bfe94f6208861d60f68eb56cb2faf8c2befdd64` |
| 测试 | `tests/cashier-v3/php/r51-market-friend-visits.php` | `372c2f3bebbc5ff084eccf85973284cd8fdb74dfab633029c5a5b7be23fab968` |
| 测试 | `tests/cashier-v3/php/r51-unified-service-customer.php` | `ce9aa5167cd854b9c78cd358da14ee333ffa74a92155006a05fb0f2cf141db57` |
| 测试 | `tests/cashier-v3/php/r51-service-customer-authority-contract.php` | `251803fa7efd7c6b5c317bc018d66410d980e51c1a6a3bb5b62246a56b1872f0` |
| 测试 | `tests/cashier-v3/php/market-detail-summary-row-contract.php` | `91af9c2566f28a56a0ccfffd4ba596fb813df5cd804688c40b3251ad688c72c1` |
| 测试 | `tests/cashier-v3/php/market-performance-service-visit-contract.php` | `6db1017fabad462fe4e5986422c61842454612084983a505e06fde1afaaa6dbb` |
| 测试 | `tests/cashier-v3/php/member-visit-annual-cash-contract.php` | `cc72411b70d5e342e40071c020a3314803a48c027ede51d747d976a43498b152` |
| 测试 | `tests/cashier-v3/php/r29-store-visit-cross-report-contract.php` | `ab1efacf2fe1b23b9696d9f23fedded8aec5116fc762b30d8f1b86b882f07f18` |
| 测试 | `tests/cashier-v3/php/r29-store-visit-report-smoke.php` | `826d3c95f1e1fd954b9de9815344961b8cc508bdf42cddcc52909adb92725ab1` |
| 测试 | `tests/unified-query/php/member-visit-fact-contract.php` | `b94f49f5f02328f49e9f8d6439d88c763dbdcb42d0339406dcb0322f2384c4ae` |
| 测试 | `tests/unified-query/php/member-visit-fact-mysql-integration.php` | `436d512ace6ab93cc76059f948d584e027cb6e47a6274b23c774b9d2f635d3d3` |
