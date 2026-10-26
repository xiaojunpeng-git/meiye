# 魔核 AI 第37～40轮汇总与瑞昊发布准备

## 后续发布结果（2026-09-25）

产品经理随后明确将“前三名”问题留到下一轮，授权部署瑞昊及验证后push。按固定版本执行过部署，但线上新发现三指标原句的契约失败与上下文分组偏差，未通过发布验收，已精确回滚。未push，原源码提交不变。详情见 `R38-R40-RH-ROLLBACK-2026-09-25.md`。下文保留发布准备时的历史状态，不作为上线成功证明。

## 当前状态

- 日期：2026-09-25；本次仅整理、核对和本地回归，没有远程操作、部署或 push。
- 固定代码版本：`857ee73fcc4df87f56776ca2e03b4b34bd4a6038`。
- 分支：`release/store-cashier-c14-main`。
- 第37轮有瑞昊部署与线上验证记录；第38～40轮已有本地提交。本次没有重新核对线上文件，不能把历史部署记录当作当前线上指纹。
- 发布就绪结论：清单已准备，但有1项既存回归失败待处理，生产预检与发布授权尚待完成，不能直接标记为可上线。

## 业务成果

| 轮次 | 主要成果 | 提交 |
| --- | --- | --- |
| 37 | 会员复合条件筛选；结果会员的权益详情、连续追问和多人权益；独立权益Excel，回答不等待文件生成 | `3ecdbe47`、`7124e7fd`；部署记录 `62f7fa16` |
| 38 | 门店、人员、会员通用分组；明确指标优先，宽泛业绩使用注册默认指标；一对象一行、多指标分列；零值折叠与可信合计 | `0b537c7a`、`eb9608ec`、`09244b00` |
| 39 | 修复多指标问句丢项、错误分组与上下文粒度误继承；模型理解后，对完整且精确的注册指标直接绑定，减少重复模型调用 | `78d28cef` |
| 40 | 自然语言对象追问，支持“详情呢”“展开看看”等语义；可信单对象连续追问；多人不擅选，明确序号后继续；修复单元素集合被拒绝 | `1dbf2837`、`857ee73f` |

会员权限仍先验证会员可见性；取得会员查看权限后，可按产品确认读取该会员所有门店权益。不能借权益跨店放宽会员名单权限。

## 已有真人路径证据与限制

- 第37轮瑞昊实测：条件权益问答18秒、单会员权益追问8秒，独立Excel生成并逐格回读通过。详见 `R37-RH-DEPLOYMENT-2026-09-24.md`。
- 第38轮最终本地样本：门店34秒、人员28秒、会员16秒；零值对象不再淹没有数据行。详见 `R38-GENERIC-DIMENSION-PRESENTATION-SELFTEST-2026-09-24.md`。
- 第39轮本地原三指标问题22秒，1次模型调用；明确各门店双指标19秒。详见 `R39-MULTI-METRIC-BINDING-SELFTEST-2026-09-25.md`。
- 第40轮本地连续追问6～8秒，通常1次模型调用；新排行仍有19～26秒样本。详见 `R40-NATURAL-OBJECT-DETAIL-SELFTEST-2026-09-25.md`。
- 以上为历史实际样本，不是本次重新执行的真人回归，也不构成稳定P95承诺。
- 人员/门店详情目前是原指标、原期间的单对象概览，不是逐笔订单、服务或分配记录。
- 多人歧义会提示选择，不作为成功查数；不支持的对象与指标组合不能冒充已支持。
- 未取得完整分组集不计算伪合计；不承诺Excel覆盖未加载的全部分组。
- 第38～40轮没有客户端源码变化，原则上无需重新打包平台端或上传小程序；正式上线仍需对当前体验版做兼容冒烟，不能声称已真机验证。

## 本次本地回归

下列15组脚本退出成功：condition-set、member-detail-continuation、breakdown-query、intent-understanding-contract、semantic-binding-guard、gateway-review-regressions、registry-execution、object-detail-continuation、gateway-components、query-read-view、personnel-analysis、state-store-contract、gateway-integration、export-runtime-contract、metric-registry-contract。

测试属于离线契约、合成/SQLite夹具或XLSX读写检查，不代替真实业务验收与生产对账。

失败项：`context-delta-gateway.php:120`，仍要求“前三名呢”直接生成本地排行要求。第39轮记录已列出该失败；本次原样复现。需复核当前自然语言边界、更新或修复对应实现/断言，并运行后续未到达的断言及真实排行追问，不能直接删测试或宣布通过。本次没有更改测试或业务代码。

本地网关故障已通过重启Colima恢复共享目录读取：管理页刷新正常，实际“今天现金业绩多少”2秒返回；这是环境恢复验证，不是上述四轮完整业务验收。

## 部署候选清单

以第37轮已发布版本为历史起点，第38～40轮共21个后端文件；从固定提交提取，不从脏工作区全量上传。路径相对 `后端代码/`：

1. app/services/ai/AiGatewayServices.php
2. app/services/ai/context/ObjectDetailContinuationResolver.php
3. app/services/ai/contract/AiIntentResultContract.php
4. app/services/ai/contract/AiIntentUnderstandingContract.php
5. app/services/ai/execution/AiAuthority.php
6. app/services/ai/execution/AiCapabilityGuidanceCatalog.php
7. app/services/ai/execution/AiPendingContextGuidancePlanner.php
8. app/services/ai/execution/AiRegisteredPlanCompiler.php
9. app/services/ai/execution/AiRunStore.php
10. app/services/ai/execution/AiWorkflowPlanner.php
11. app/services/ai/model/SiliconFlowClient.php
12. app/services/ai/presentation/AiAnswerRenderer.php
13. app/services/ai/registry/AiBusinessManifest.php
14. app/services/ai/registry/AiBusinessRegistry.php
15. app/services/ai/workflows/AiWorkflowCatalog.php
16. app/services/query/metric/AnalysisCapabilityCatalogFactory.php
17. app/services/query/metric/GroupPerformanceMetricReadServices.php
18. app/services/query/metric/MetricDefinitionRegistry.php
19. app/services/query/metric/MetricReadViewExportProvider.php
20. app/services/query/metric/MetricReadViewServices.php
21. app/services/query/metric/RegisteredMetricReadServices.php

上述AI及指标目录本次核对没有未提交修改。当前工作区另有收银、移动登录、本地启动配置、迁移文档等未提交变更，全部排除；不能部署整个HEAD与历史版本间的所有差异，因为中间夹有其他任务提交。

第37轮共享依赖 `CashierV3MemberDetailQueryServices.php` 当前仍有其他任务未提交改动；历史上线曾保留服务器版本以保护其他已上线功能。发布前必须只读核对其跨店权益行为与线上指纹，不覆盖该文件、不把本地脏文件夹带上线。

## 发布前后顺序

1. 处理既存回归失败，完成受影响用例与固定版本确认；如产生代码修复，按项目验收/提交规则更新版本。
2. 取得明确瑞昊发布授权后，核对 `rh.cc3798.com`、`/www/wwwroot/rh.cc3798.com/`、`ruihao`、当前文件指纹、迁移登记、活动AI/导出任务及服务状态。其他客户保持不变。
3. 根据线上实际差异最终确定文件列表；备份本次覆盖文件、记录新增文件和哈希，形成独立回滚点。第37轮历史备份不能代替本次发布前备份。
4. 本次候选没有数据库迁移、业务回填、前端构建或实例配置修改；不修改指标金额口径，不写业务事实。
5. 安全切换后端及AI执行/导出进程，避免中断在途任务；逐文件核对发布指纹。
6. 瑞昊实际验证：会员条件与权益/Excel、门店/人员/会员分组、三指标汇总与明确分组反例、人员/门店连续追问、多人歧义与序号选择、页面刷新，以及现有小程序兼容。
7. 异常时按本次精确备份回滚代码及新增文件，不恢复覆盖生产业务库；验证通过后记录结果，仅在明确push授权下推送。

## 文件分组

- 后端源码：上列21个候选文件，本次未编辑。
- 前端源码、数据库迁移、工程脚本、生成物：本次无新增或修改，未制作发布包。
- 自动化测试：运行已有脚本，本次未编辑。
- 验收/准备证据：本文件；不表示生产验收已完成。
