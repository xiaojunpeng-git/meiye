# R38 通用对象分组问数与简洁结果展示：汇总及自测

## 状态与范围

2026-09-24，本地源码开发及相称自测完成，待产品经理确认固定版本。未在本任务执行 commit、部署、push 或小程序上传。本次真实页面测试入口为 `http://127.0.0.1:18081/admin/setting/mohe-ai`，不是瑞昊生产站。

本轮从“今天各门店业绩多少”丢失分组维度出发，沿用通用 breakdown 查询链，不另建门店专用问句分支。对象、指标、日期、结果形式分别保留；支持已注册兼容对象，不承诺任意对象与指标组合均可执行。

## 本轮成果

- 按对象列明细，不把“各个对象”变成总额或排行。
- 宽泛门店业绩采用注册表的实际业绩首答，员工采用销售人业绩首答；明确指标优先，不默认铺开所有指标。
- 多指标一对象一行、各指标一列，单位放在列名，简短结论加统计日期；分页截断如实提示。
- 复用统一事实 Reader 和权限，不在前端计算金额，不用展示名合并同名对象，不把缺失证据补成零。
- 本次复核修复：模型误加第二个指标时仍须纠正单指标；多个明确指标不得因唯一匹配为空而落入默认指标；首次理解提示明确连续原文证据，减少引用改写引发的重试，严格校验保留。

## 自动化自测

以下测试在当前修改版本通过，共 498 个检查；夹具和静态测试不等同于业务数据对账或产品经理验收。

| 测试 | 检查数 |
| --- | ---: |
| breakdown-query.php | 27 |
| intent-understanding-contract.php | 144 |
| semantic-binding-guard.php | 12 |
| gateway-integration.php | 155 |
| gateway-review-regressions.php | 71 |
| metric-registry-contract.php | 89 |

七个改动 PHP 业务文件语法检查通过，`git diff --check` 通过。注册表测试原来的完整 query_shapes 断言遗漏已存在的 breakdown 能力，已更新该断言，未删测试。

扩展检查 `gateway-components.php` 在 `explicit top five phrasing` 断言失败：仍期待旧解析器直接产生 top_5 信号。该测试、投影器、语义解析器本次没有改动。未为满足旧断言恢复问句映射，也未将其计入通过数；此项待单独核对旧排行测试契约，因此不能声称全仓测试通过。

## 浏览器实际操作记录

通过页面输入并发送问题，观察完成后的用时、结论、列名及结果行；未直接注入接口答案。

| 实际问题与操作 | 页面结果 | 用时 |
| --- | --- | ---: |
| 今天各门店现金业绩和销售额分别是多少（改进证据提示前） | 门店、现金业绩、销售额三列 | 37 秒 |
| 在双指标后问：今天各门店现金业绩多少 | 只保留现金业绩一列 | 27 秒 |
| 改进证据提示后重问双指标 | 两指标正确保留，一门店一行 | 26 秒 |
| 继续问：今天各员工业绩多少 | 人员、销售人业绩两列，未继承门店双指标 | 20 秒 |
| 继续问原始问题：今天各门店业绩是多少 | 门店、实际业绩两列，未继承员工指标 | 20 秒 |

双指标样本行可见现金业绩 70,571 元、销售额 71,571 元，两列未错误复用同值。门店结果提示当前前 99 条，未宣称为完整门店清单。本次没有独立逐笔业务对账，也没有实机小程序验证；页面数据属于测试时的本地事实数据，不代表生产当日数据。

## 耗时证据与限制

修复前 run `2d0a80a56811a52c0798db6cb5f2f5b1af2de72d556cec63`：客户端 37,969 ms；理解 14,089 ms，原文证据修复 12,421 ms，绑定 7,437 ms，复核 1,538 ms，查数 203 ms。

修复后双指标 run `a887c75f145cd01b150ec6c45f9265225e2916dd01365718`：客户端 26,828 ms；理解 13,323 ms，绑定 8,571 ms，复核 2,333 ms，查数 181 ms；没有 understand_repair。

其余本次完成记录：`535147f1edf50ee29555895e4973e8e508006ef561fbbbfa`、`bcbae9ad0b372beada3e5580824de08e5e098c76c4001fb8`、`cc21431f6479b7e3b5ef0a612604c7ceb4897b11983d2786`。

上述只证明这些样本完成且一次重试被避免，不是稳定性能 SLA。模型理解和绑定仍是主要耗时，20—26 秒仍有优化空间；未取消语义或权限复核来换速度。

## 代码质量与交付分组

- 后端源码：以下七个 PHP 文件。关键默认策略、证据边界、精确多指标保留及对象行合并均补有职责与约束注释。
- 自动化测试：以下两个测试文件。
- 前端源码、数据库迁移、工程脚本、构建生成物：本次无新增或修改。
- 验收证据：本文件，与源码清单分开。
- 治理：展示使用同一分组渲染入口，替换旧纵向重复表达，未增加第二套业务实现；未删除其他任务或仍被使用的历史路径。工作区其他脏文件保持原状。

## 固定版本

记录时 HEAD：`9ade9b60280909561a834be574276d723d9fe10e`。本次工作期间其他任务提交过收银 R33；本轮九文件以如下 SHA-256 为准，不能将其他任务改动混入验收。

九文件补丁 SHA-256：`e14ee6010d54979a05dc4d51f7044b71cffa1ea7dcaafc709db10dbae5ab5887`。

| 文件 | SHA-256 |
| --- | --- |
| 后端代码/app/services/ai/AiGatewayServices.php | 8c6a29a5da3f252b3f9b679f3da81a89c9d5d1651f3aa595656fe6e6cd7b03aa |
| 后端代码/app/services/ai/contract/AiIntentResultContract.php | 862ffaa76862fa6e8c038dacb6cd91c46c17974cd3ddc5f962086a0350a5c73c |
| 后端代码/app/services/ai/contract/AiIntentUnderstandingContract.php | 089c4569077a6a01e8ff9693a3ea617e6a1ce09aa4142058a48ffbb3a112348a |
| 后端代码/app/services/ai/execution/AiCapabilityGuidanceCatalog.php | 5f7da0eaf10164e151953f9ffed8641764880cfc269cc9f4697eb05a043380b5 |
| 后端代码/app/services/ai/model/SiliconFlowClient.php | b3e5a69c5619d2364f20b9f20fdfaf5158b217266b22f4eb6cb5377087fbd55a |
| 后端代码/app/services/ai/presentation/AiAnswerRenderer.php | feee136de82ded823167ae29cf68660aa3ae037c708177f7c64d202456212abc |
| 后端代码/app/services/query/metric/MetricDefinitionRegistry.php | 85afe0e61fee403e0bb7886b3c398f1fe81067bb3bc219fcb097d6078c0ea1bd |
| tests/mohe-ai/breakdown-query.php | 9883d5893d568a498bff23b6805cde308f965391ec574c1a1bf49bc04ea77777 |
| tests/mohe-ai/metric-registry-contract.php | a0fbc9c619f275f481ba0af5a6c6a6d9400223cfaf5a0bfa8240cc06874fd4a5 |
