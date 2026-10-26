# R54 魔核 AI 条件人数、会员详情和追问耗时：本地自测

状态：开发与自测完成；产品经理回复“我测试通过”，确认下列固定版本验收。提交前尚未部署或推送；线上电脑端和小程序仍运行旧版本，本记录不构成线上验收。

## 问题与修复边界

- 原问“2026年消费4980的有多少个人”有时被模型把“有多少个人”误列为第二项业务指标，在数据查询前失败。现在只在唯一注册条件和明确人数问法同时成立时，将这个误列字段还原成回答形式；独立指标、额外条件、排除条件不被吞掉。
- 会员详情原先逐人重复读取会员/门店关系和余额就绪状态。现在一批签名会员先经同一门店关系与权限复核，再批量读取余额；每人卡项仍按原收银权威口径计算，缺失或越权时整批失败。
- 单条件金额追问的慢样本 27 秒，经本地运行诊断确认：模型首次返回协议外字段，理解 12 秒后又纠正 13 秒。新增仅针对“已验证的唯一条件 + 完整、无剩余语义的数字比较变更”的本地语法入口，不决定指标或会员身份；日期、回答人数变化仍走既有类型契约。额外业务条件、多条件、未知单位继续走模型理解。

## 验证

- 离线：`condition-set.php` 100 项、`member-analysis.php` 22 项、`member-detail-continuation.php` 31 项、`gateway-integration.php` 155 项通过；会员余额合同套件通过，MySQL 5.6 矩阵未运行。
- 本地只读真数据：20 位现有授权会员的批量与单人读取，身份、余额摘要和卡项一致，每位都触发运行检查点；批量约 131 ms，逐人约 614 ms（仅该次环境样本，非线上 SLA）。
- 本地页面真实问答：原句返回 1 人、13 秒；“客户详情”显示同一 1 人的筛选依据及权益、16 秒；“2026年消费大于1980元的客户有多少人”返回 49 人、10 秒，追问“客户详情”显示同一批 49 人、12 秒。
- 本地页面金额追问：修复前同类问句为 27–29 秒，诊断为理解失败后重试；重载本地魔核 AI 执行进程后，完整的同类变更问句返回 16 人、2 秒，运行记录没有 `understand_meaning`/`understand_repair` 尝试，仅执行原有授权查询。以最终含逐人检查点的版本继续追问“客户详情”，同一 16 人的筛选依据和权益汇总在 10 秒返回。人数与阈值递增后的前序结果方向一致，但尚未完成独立财务事实对账。
- 负例契约：额外“积分条件”与两个已签名条件不能进入快速路径；独立未注册指标不能被当作人数问法删去；“今天收了多少钱”不会被当作人数问法。

## 固定版本及文件范围

基线 HEAD：`3ff562f55435cb312746cddded5ca64571d4d295`。以下文件为本闭环范围，路径均相对源码正本 `美容源码/`；其余脏工作区文件均不属于本轮。

| 分类 | 文件 | SHA-256 |
| --- | --- | --- |
| 后端 | `后端代码/app/services/ai/AiGatewayServices.php` | `972f713dab682efe3dbf04ffe82f60118a6fde46badad606541d17e2b5662605` |
| 后端 | `后端代码/app/services/ai/contract/AiIntentUnderstandingContract.php` | `bf6fbf553be9d98efc296407dc913642c7d278f1a753eb5ab77868a1289576b4` |
| 后端 | `后端代码/app/services/ai/semantic/AiConditionResponseFormResolver.php` | `b0f5276eb3176ae1a59043368ee77002153b6f2e0f58119ffccf956eae4517fe` |
| 后端 | `后端代码/app/services/cashier/v3/checkout/provider/CashierV3MemberBalanceProvider.php` | `51ebf8773eb545d09d2e950caefd004cb49e661e78a924e3b9305bf84424c433` |
| 后端 | `后端代码/app/services/cashier/v3/member/CashierV3MemberDetailQueryServices.php` | `ab9da9c37043812d3fe208a4116070851118596a2f637a39d67668c4979b8e56` |
| 后端 | `后端代码/app/services/query/metric/MemberAnalysisObjectServices.php` | `823518dd7abaac4a2a1e2aea76a9304ccfa191d7e8339498314a29518d2e01c0` |
| 测试 | `tests/mohe-ai/condition-set.php` | `541f4ab499899bc6552c5aba2534c3d357c191dfd2424b0ed6c8457cb0919c1f` |
| 测试 | `tests/mohe-ai/member-analysis.php` | `7d0fb3bcd726ae1b61e873a209b8dce38abb9eea4d325ea01560e00c03d1105d` |
| 测试 | `tests/mohe-ai/member-rights-batch-live-readonly.php` | `7e34f1c228798a1355ade760c0e1496974f126979efc49a0a2b99b397c51656d` |

上述已跟踪文件的补丁 SHA-256：`ac095f66173eb250b0268a48b0bf308b317811589d4a9e8519ae025c802e4b89`；新建只读测试和本记录以独立文件哈希固定。无前端源码、数据库迁移或工程脚本变更；无构建生成物。新增或改动的后端方法、权限/金额边界和上下文分支均补充职责注释；共享方法的旧逐人实现已改为批量委托，未保留第二套业务计算。

## 后续门禁

本轮固定版本已获产品经理验收，可按项目规则逐文件暂存并本地提交。瑞昊部署、线上双端验证和 push 仍须分别取得适用授权，且小程序没有新前端包可上传。
