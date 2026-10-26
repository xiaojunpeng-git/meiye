# R54 单金额条件会员人数：本地修复与自测（2026-09-29）

## 状态与版本

- 基线 HEAD：`dfad0f2516361700da805929b0550b4376409c57`。本记录对应其上的固定修复补丁；产品经理已明确要求本地自测后提交、重新部署瑞昊、线上验证通过后 push。
- 上次 `dfad0f25` 瑞昊部署的线上问句仍失败，六个后端文件已按独立备份回滚，详见 [失败与回滚记录](R54-RH-FAILED-DEPLOY-2026-09-29.md)。本记录中的结果仅为**本地自测**，不能冒充线上或双端验收；部署、线上验证与 push 另行记录。
- 固定补丁指纹（仅下列四个源码/测试文件的 `git diff --binary`）：`77028d286e63f109c32987a3f3c47cb296bb6b3890a6c2f8e4018a299ed183a7`。

| 文件 | SHA-256 |
| --- | --- |
| `后端代码/app/services/ai/AiGatewayServices.php` | `9fcfcef92a3a1e89c71bad2626b57a990cd3ae8417e3c1a46e1137bcad6533e9` |
| `后端代码/app/services/ai/contract/AiIntentUnderstandingContract.php` | `98b420dc8afd719c6f4dc554fc992bb3ee562498e9c67a4aaac0dfacb447b689` |
| `后端代码/app/services/ai/semantic/AiConditionResponseFormResolver.php` | `c184701227d5be375881ec86c28bac53aa87f99852c26cb0e84823433178b828` |
| `tests/mohe-ai/condition-set.php` | `b042701f4f06ef18f1aed9fa345fb1274f1417b98350ba8b8b6aa32a342f69e7` |

## 问题与改动边界

原问“2026年消费4980的有多少个人”在线上被模型拆成两个指标要求；后端在查数前以 `AI_ANALYSIS_COMBINATION_UNAVAILABLE` 拒绝。仅修补“模型已经给出完整条件”的分支后，本地真实复问仍用时 12.4 秒并同样失败，证明那不是充分修复。

最终改动为：对**首问、单个精确注册指标、完整金额比较、明确的单一日期范围、会员人数问法**进行封闭语法校验；仅全部条件成立时，生成一个受现有条件编译器、统一 Reader 和权限门禁继续检查的语义对象，不再等模型把人数错拆成第二指标。金额、比较符、日期均取自问句；“消费”归属由当前注册表决定，未写死指标代码或数值。额外真实条件、未识别词、多个金额、模糊日期或未注册能力均不走此路径。若模型已给出完整条件但另外投射人数指标，则只在同样的整句证明通过、模型数量和比较符也与原文一致时移除该投射。

## 自测记录

环境：本机 `mohe-ai-execution-worker` 挂载唯一后端源码，本机数据库与现有 admin 权限；先确认 `WORKFLOW_EXECUTING/WAITING_EXPORT=0`，仅重启此本地 AI 执行器加载新 PHP。通过与页面相同的 Gateway `bootstrap → create → execute → status` 新会话链路调用真实服务、现有本地数据；每个测试会话独立，未传递旧上下文。以下时间为整轮本地 Gateway 墙钟时间，结果只记人数，不留会员身份。AI Run 状态是测试产物；未修改经营事实。

| 真实问句 | 状态 | 耗时 | 结果与口径 |
| --- | --- | ---: | --- |
| 2026年消费4980的有多少个人 | COMPLETED | 2.2 秒 | 1 人，2026-01-01 至 2026-09-29；与先前本地有效结果一致 |
| 2026年消费大于980元的客户有多少人 | COMPLETED | 2.2 秒 | 65 人，同一区间；比较符保留为“大于” |
| 2026年消费980的有多少个人 | COMPLETED | 1.5 秒 | 0 人，同一区间；裸金额保留为“等于” |

最近三条本地 Run 均为 `COMPLETED`，诊断形态 `exact_registry_binding:r1:o1:t1:m1:s0:x0:e0`；尝试类型仅有本地对象/会员/查询工具，**无模型调用**。这说明本次速度改善来自已证明的本地语义准入，而非跳过统一查询。

离线边界回归：`condition-set.php` **109 项通过**，包括额外“积分 200”不得被吞掉、模型改错金额/比较符不得被安全修复、计数单位指标不得冒充金额、不同阈值可按同一规则处理。`semantic-binding-guard.php` 12 项、`gateway-integration.php` 155 项、`management-core.php` 47 项、`management-gateway.php` 36 项、`analysis-capability-catalog.php` 21 项、`analysis-object-resolution.php` 16 项、`member-analysis.php` 22 项、`query-context.php` 64 项、`context-delta-gateway.php` 92 项、`date-policy.php` 56 项均通过。四个改动 PHP 文件 `php -l` 通过，`git diff --check` 通过。

`run-all.sh` **未全绿**：执行到冻结的 R5 holdout 时停下，报告 `matched=27, mismatched=13`；其 40 个旧题均不属于本次封闭会员金额人数语法。没有修改冻结题或把这项失败记作通过。后续门禁仍以本次专项回归、真实本地问答和产品经理验收为准，旧 holdout 缺口需另行治理。

## 文件分类与剩余验证

- 前端源码：无；后端源码：上表 3 个 PHP；数据库迁移：无；自动化测试：上表 1 个 PHP；工程脚本：无；生成物：本地短期 AI Run/诊断状态；验收证据：本记录及失败/回滚记录。
- 本次补充/更新职责与约束注释的文件：`AiGatewayServices.php`、`AiIntentUnderstandingContract.php`、`AiConditionResponseFormResolver.php`。已检查新路径没有替换仍被调用的旧条件追问/模型兜底路径，故未删除其代码；没有新增平行查询 Reader。
- 尚未完成：重新部署瑞昊、线上平台与小程序同口径复问、线上验证后 push。小程序仍连瑞昊线上，线上目前是回滚版本，不能拿此本地结果冒充手机端已通过。
