# R51 旧会话完整人员排名修复：本地自测记录

基线 HEAD：`ad27384a2a9ed128fd26afdc965572840b32594a`。本记录对应未提交的工作区版本；未部署、未 push、未上传体验版。此前瑞昊线上两次历史会话失败及回滚原始记录保留于 `R51-FRESH-RANKING-RH-ONLINE-2026-09-28.md`，不得改写为本版通过。

## 修复范围

- 后端 `AiIntentResultContract.php`：只对首段理解已完整给出当前问题的人员、单一精确注册指标、单一期段和有限名次的排名，允许在旧会话编译绑定；显式 `context_delta` 替换新指标、对象、时间、排名，清理旧人员筛选；若旧查询限定门店而本句无当前门店证据，继续走普通模型路径。若要求混入历史证据、额外条件或多重指标，也不走快捷路径。
- 后端 `AiGatewayServices.php`：移除将上述完整排名限制为新对话的门禁；后续仍经过已有的上下文合并、能力、授权和 Reader 检查。没有新增词组特判、数据库查询或另一路指标计算。
- 合同诊断：冲突门店只记结构性错误、数量及固定形态枚举，不记录本地标记序号；这与 Run 存储的无客户内容白名单一致，避免诊断记录被拒绝。
- 更新的业务代码注释说明当前证据、旧条件替换和诊断隐私边界。

## 自测

- `intent-understanding-contract.php`：195 检查通过，新增旧会话新排名、旧门店/人员/指标清除、缺当前门店或历史证据拒绝、冲突诊断结构检查。
- `context-delta-gateway.php`：92；`gateway-integration.php`：155；`gateway-review-regressions.php`：85；`gateway-components.php`：140；`personnel-analysis.php`：56；`runtime-contract.php`：105；`state-attempt-contract.php`：76，全部通过。以上均为固定模型/合成事实或事务测试，不冒充真实问答。
- 本机 PHP 8.5、运行容器 PHP 7.4 对两份后端文件语法检查通过；三文件 `git diff --check` 通过。
- 之后在新开的本地 18081 浏览器测试页正常进入工作台，同一会话第一问“这个月现金业绩最高的是哪个门店”用时 2 秒并返回数据。第二问“这个月肥西水晶城这个门店技师项目数最多的是哪个，最低的是哪个”用时 36 秒失败，run `410cbfc8f201102fffad584c81a0a6e3d34239dfcbd95509`；未执行数据查询。诊断为 `conflicting_store_terms`，两条门店要求。加入安全的诊断形态后，同句再试用时 36 秒，run `f6147eafaaea65bb4b4574c796da3feb256e265a5fb984e1`，确认是一条不透明门店标记加一条文字指代（`mixed_ref_phrase`）。
- 曾试验“紧邻文字指代合并”并通过合成合同测试，但真人复测用时 39 秒仍失败，run `66dca30f62d3400fe77d2bc3715f3052a9dfab8bcb266216`，诊断仍为 `mixed_ref_phrase`。该未经真人验证的合并实现及其测试已撤回；失败运行记录保留。当前不把这三次失败说成修复成功。

## 固定文件与边界

```text
1010bb5fcd9f569ec1088606996bc042f7f0c2b03c67004ded3833ba4b7b6c3b  后端代码/app/services/ai/AiGatewayServices.php
f6910468d2a9f1869992e45d030b2f16e4fec569f003724dcf150385fc8fb35b  后端代码/app/services/ai/contract/AiIntentResultContract.php
a165dc7f3fd12f7f02873308f476af65146827088f80803560883112a347145d  tests/mohe-ai/intent-understanding-contract.php
```

本闭环无前端源码、数据库迁移、工程脚本或生成物；本文件为验收证据。仓库其他任务原有脏文件与未跟踪证据不纳入本版。状态：离线自测通过但关键真人问答失败，问题未修复；不满足产品经理验收、提交或上线门禁。

## 2026-09-28 追加修复与复验（保留以上失败记录）

排查发现本地 `mohe-ai-execution-worker` 是常驻进程，最初几次页面重试仍执行 45 分钟前载入的旧代码；已仅重启本地执行进程再测，不影响瑞昊线上。旧版本的失败不能算新版结果。更新后的失败记录显示，模型有时把当前岗位的不透明标记也填入门店条件（`conflicting_store_terms`）；另一种返回把人员指标中的“项目”误当排行对象，岗位标记在查询前被严格校验拦下。上述两类都发生在数据读取前。

本次仅在现有首轮理解契约内做保守纠偏：用本地目录确认的标记类型区分门店与岗位；紧邻已命名门店的指代只在当前原文可证同时合并。岗位标记仍须经过现有人员筛选和授权解析，否则保留失败。人员指标的注册事实粒度可纠正只出现在指标名称中的对象名；独立提到的项目不改写。完整的当前排名在已验证旧会话中复用首轮理解，省去第二次绑定模型调用。模型提示只调整当前明确门店与旧结果指代的优先级，不增加客户名称或特定问句模板。临时排查诊断已从最终源码删除；历史运行诊断仍留在运行记录中。

本地浏览器真实两轮测试：先问“这个月现金业绩最高的是哪个门店”，2 秒返回；再问“这个月肥西水晶城这个门店技师项目数最多的是哪个，最低的是哪个”。早期候选版仍有 32–36 秒失败，保留 run `0f3e37fb239a0b915fb8bd30f86aa198bf376cd1b93f20b6`、`54b0f2c451a6505f135c27696ed4d1698d09f99821dad8bd`、`487699a5f21d1dac19d9a8f854b3a57c7f9605273d7ee78e`，不得计入通过。收紧后的最终业务逻辑对应 run `3fb03dcafdcc76c28890704557b3e41223972b6df785cd9e`：页面 25 秒返回项目数最高与最低各一名。加密证据中的实际查询是单门店 `肥西水晶城`、`staff_project_num`、`role:craftsman`、`ranking`；没有沿用前问的另一家门店，也没有丢掉技师岗位。另一次候选版 run `55542c032d285c88cd2725d286513c6345843fc631a83de1` 26 秒成功，实际查询同样经过单门店/岗位/指标核对。

最终成功样本服务端分段：人员目录约 0.3 秒，会员姓名保护查询约 3.6 秒，模型首轮理解约 19.1 秒，门店绑定约 0.02 秒，Reader 约 0.4 秒；没有第二次模型绑定。耗时较先前 36–44 秒失败改善，但页面 25 秒仍未达到更短问答的理想目标，不能宣称速度问题完全解决。该瓶颈属于模型首轮延迟与现有会员姓名保护查询；本闭环没有为了缩短数字绕开脱敏或改用猜测规则。

回归：`intent-understanding-contract.php` 203、`context-delta-gateway.php` 92、`gateway-integration.php` 155、`gateway-review-regressions.php` 85、`gateway-components.php` 140、`personnel-analysis.php` 56、`runtime-contract.php` 105、`state-attempt-contract.php` 76，均通过；运行容器 PHP 7.4 对四份后端文件语法检查通过，`git diff --check` 通过。新增反例覆盖两个真实门店不得合并、外加未知门店文字不得删除、独立项目对象不得改写。修改到的复杂业务逻辑注释已同步更新：`AiGatewayServices.php`、`AiIntentResultContract.php`、`AiIntentUnderstandingContract.php`、`SiliconFlowClient.php`。

固定验收版本：当前基线 HEAD `04370d2fa0da784223e89db6b28d4e2e7dcd5130`；文件 SHA-256：

```text
61ef572373ea79e8e465f66174cd11f16014cb8f9502546e0317133de1829835  后端代码/app/services/ai/AiGatewayServices.php
f6910468d2a9f1869992e45d030b2f16e4fec569f003724dcf150385fc8fb35b  后端代码/app/services/ai/contract/AiIntentResultContract.php
7d9e4a571bb7598649d13941bf6db4eee46fb9cb1364c770bdb9547717b22533  后端代码/app/services/ai/contract/AiIntentUnderstandingContract.php
afc0d6f37c3637481566fb70e02609e4b0d1052d717979bddbb3aa539c84af42  后端代码/app/services/ai/model/SiliconFlowClient.php
48e15047bb2d8f06e5ed1c7ae5ba13f271c034fe9562399e2050635393c8f847  tests/mohe-ai/intent-understanding-contract.php
```

本闭环前端源码、数据库迁移、工程脚本和生成物：无；验收证据：本文件。状态：本地开发与自测通过，待产品经理按上述固定版本确认验收；未 commit、未部署、未 push。小程序当前连接瑞昊线上旧版，本次本地修复尚未上线，不能把本地网页结果冒充线上小程序验证。

最后仅重命名契约方法、删除临时诊断并重启本地执行进程后，用最终源码在全新会话重问相同问题：run `98ddc59482ef6f46522012dd64d7ece0a011405a9591f1ec`，页面 22 秒成功；实际查询仍是单门店肥西水晶城、`staff_project_num`、`role:craftsman`，仅一次模型调用。服务端首轮模型 16.6 秒，会员姓名保护查询 3.6 秒，Reader 0.34 秒。该复验不改变以上固定源码 SHA。
